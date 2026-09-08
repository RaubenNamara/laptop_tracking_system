<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');
require_once('../includes/migrate.php');
require_once('../includes/sms.php');
lts_run_migrations($conn);

$flash = null;

// Bulk SMS action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'notify_all') {
    $sent = 0; $failed = 0;
    $rs = $conn->query("
        SELECT l.laptop_id, l.laptop_number, l.due_date, s.student_id, s.name AS student_name, p.name AS parent_name, p.contact AS parent_contact
        FROM laptops l
        LEFT JOIN students s ON s.student_id = l.student_id
        LEFT JOIN parents  p ON p.parent_id  = s.parent_id
        WHERE l.status IN ('issued','out_for_project') AND l.due_date IS NOT NULL AND l.due_date < NOW()
    ");
    if ($rs) while ($r = $rs->fetch_assoc()) {
        if (empty($r['parent_contact'])) { $failed++; continue; }
        $msg = "St. Mark's Laptop Tracking: Laptop #" . $r['laptop_number']
             . " issued to " . ($r['student_name'] ?? 'student')
             . " was due on " . date('d M Y', strtotime($r['due_date']))
             . ". Please ensure it is returned promptly.";
        $res = lts_send_sms($conn, $r['parent_contact'], $msg, (int)$r['laptop_id'], (int)($r['student_id'] ?? 0));
        if ($res['success']) $sent++; else $failed++;
    }
    $flash = ['success', "SMS sent: $sent · failed/skipped: $failed"];
}

// Single SMS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'notify_one') {
    $lid = intval($_POST['laptop_id'] ?? 0);
    $stmt = $conn->prepare("SELECT l.laptop_id, l.laptop_number, l.due_date, s.student_id, s.name AS student_name, p.name AS parent_name, p.contact AS parent_contact
                            FROM laptops l LEFT JOIN students s ON s.student_id = l.student_id LEFT JOIN parents p ON p.parent_id = s.parent_id
                            WHERE l.laptop_id = ?");
    $stmt->bind_param('i', $lid); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if (!$r) $flash = ['danger', 'Laptop not found.'];
    elseif (empty($r['parent_contact'])) $flash = ['warning', 'No parent phone on file for that student.'];
    else {
        $msg = "St. Mark's Laptop Tracking: Laptop #" . $r['laptop_number']
             . " issued to " . ($r['student_name'] ?? 'student')
             . " was due on " . date('d M Y', strtotime($r['due_date']))
             . ". Please ensure it is returned promptly.";
        $res = lts_send_sms($conn, $r['parent_contact'], $msg, (int)$r['laptop_id'], (int)($r['student_id'] ?? 0));
        $flash = $res['success'] ? ['success', 'SMS sent.'] : ['danger', 'SMS failed: ' . $res['message']];
    }
}

// Fetch overdue
$rows = [];
$rs = $conn->query("
    SELECT l.laptop_id, l.laptop_number, l.serial_number, l.model, l.status, l.due_date,
           s.name AS student_name, s.class AS student_class,
           p.name AS parent_name, p.contact AS parent_contact
    FROM laptops l
    LEFT JOIN students s ON s.student_id = l.student_id
    LEFT JOIN parents  p ON p.parent_id  = s.parent_id
    WHERE l.status IN ('issued','out_for_project') AND l.due_date IS NOT NULL AND l.due_date < NOW()
    ORDER BY l.due_date ASC
");
if ($rs) while ($r = $rs->fetch_assoc()) $rows[] = $r;

// Coming-soon (next 24h)
$soon = [];
$rs2 = $conn->query("
    SELECT l.laptop_id, l.laptop_number, l.due_date, s.name AS student_name
    FROM laptops l LEFT JOIN students s ON s.student_id = l.student_id
    WHERE l.status IN ('issued','out_for_project') AND l.due_date IS NOT NULL
      AND l.due_date >= NOW() AND l.due_date < DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ORDER BY l.due_date ASC
");
if ($rs2) while ($r = $rs2->fetch_assoc()) $soon[] = $r;

$page_title = 'Overdue Laptops';
include('../includes/header.php');
?>
<div class="page-header">
    <div>
        <h2 class="title">Overdue Laptops</h2>
        <p class="subtitle">Laptops past their return date. Notify parents to recover them quickly.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" onsubmit="return confirm('Send SMS to all overdue parents?');">
            <input type="hidden" name="action" value="notify_all">
            <button class="btn btn-primary"><i class="fas fa-comment-sms"></i> Notify all parents</button>
        </form>
        <a href="sms_settings.php" class="btn btn-outline"><i class="fas fa-gear"></i> SMS Settings</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash[0]; ?>"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash[1]); ?></div>
<?php endif; ?>

<div class="card-soft elev mb-3">
    <div class="card-head">
        <h3><i class="fas fa-clock me-2" style="color:var(--danger)"></i>Currently overdue (<?php echo count($rows); ?>)</h3>
    </div>
    <?php if (empty($rows)): ?>
        <div class="empty-state"><div class="icon"><i class="fas fa-circle-check"></i></div>
            <div class="title">All clear</div>
            <div>No laptops are overdue right now. Great job.</div>
        </div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr>
            <th>Laptop</th><th>Student</th><th>Class</th><th>Parent</th><th>Phone</th><th>Due</th><th>Overdue by</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $due = strtotime($r['due_date']);
            $hours = max(0, floor((time() - $due) / 3600));
            $overdue_str = $hours >= 48 ? floor($hours/24) . ' day' . ($hours>=48 && floor($hours/24)!=1 ? 's' : '') : $hours . ' hr' . ($hours==1?'':'s');
        ?>
        <tr>
            <td>
                <strong><?php echo htmlspecialchars($r['laptop_number']); ?></strong>
                <div class="text-small muted"><?php echo htmlspecialchars($r['model'] ?? ''); ?></div>
            </td>
            <td><?php echo htmlspecialchars($r['student_name'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['student_class'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($r['parent_name'] ?? '—'); ?></td>
            <td>
                <?php if (!empty($r['parent_contact'])): ?>
                    <a href="tel:<?php echo htmlspecialchars($r['parent_contact']); ?>"><?php echo htmlspecialchars($r['parent_contact']); ?></a>
                <?php else: ?>
                    <span class="muted text-small">No phone</span>
                <?php endif; ?>
            </td>
            <td><?php echo date('d M Y · H:i', $due); ?></td>
            <td><span class="badge-pill badge-bad"><?php echo $overdue_str; ?></span></td>
            <td>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="notify_one">
                    <input type="hidden" name="laptop_id" value="<?php echo (int)$r['laptop_id']; ?>">
                    <button class="btn btn-outline btn-sm" <?php echo empty($r['parent_contact']) ? 'disabled' : ''; ?>>
                        <i class="fas fa-comment-sms"></i> SMS
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card-soft">
    <div class="card-head"><h3><i class="fas fa-hourglass-half me-2" style="color:var(--warning)"></i>Due in next 24 hours (<?php echo count($soon); ?>)</h3></div>
    <?php if (empty($soon)): ?>
        <p class="muted mb-0">Nothing scheduled in the next 24 hours.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Laptop</th><th>Student</th><th>Due</th></tr></thead>
        <tbody>
        <?php foreach ($soon as $r): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($r['laptop_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($r['student_name'] ?? '—'); ?></td>
                <td><?php echo date('D, d M Y · H:i', strtotime($r['due_date'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include('../includes/footer.php'); ?>
