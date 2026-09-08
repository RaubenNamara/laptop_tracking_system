<?php
// logs.php — Activity Logs with pagination, usage-hour tracking, and single sidebar

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
ob_start();

include('../config/config.php');

// ── helper ──────────────────────────────────────────────────────────────────
function send_json_and_exit($data, $http_status = 200) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8', true, $http_status);
    echo json_encode($data);
    exit;
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));

if ($isAjax && !isset($_SESSION['user_id'])) {
    send_json_and_exit(['success' => false, 'error' => 'Session expired. Please login again.'], 401);
}
if (!$isAjax && !isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// ── AJAX: delete single log ──────────────────────────────────────────────────
if ($isAjax && $_POST['action'] === 'delete_log') {
    $log_id = intval($_POST['log_id'] ?? 0);
    if ($log_id <= 0) send_json_and_exit(['success' => false, 'error' => 'Invalid log ID.'], 400);
    $del = $conn->prepare("DELETE FROM logs WHERE log_id = ? LIMIT 1");
    if (!$del) send_json_and_exit(['success' => false, 'error' => 'Prepare failed.'], 500);
    $del->bind_param("i", $log_id);
    $ok = $del->execute();
    $aff = $del->affected_rows;
    $del->close();
    if (!$ok || $aff <= 0) send_json_and_exit(['success' => false, 'error' => 'Log not found or already deleted.'], 404);
    send_json_and_exit(['success' => true]);
}

// ── AJAX: clear all logs ─────────────────────────────────────────────────────
if ($isAjax && $_POST['action'] === 'clear_logs') {
    $ok = $conn->query("DELETE FROM logs");
    if ($ok === false) send_json_and_exit(['success' => false, 'error' => 'Failed: ' . $conn->error], 500);
    $conn->query("ALTER TABLE logs AUTO_INCREMENT = 1");
    send_json_and_exit(['success' => true]);
}

// ── Page render ──────────────────────────────────────────────────────────────
$PER_PAGE = 100;
$page     = max(1, intval($_GET['pg'] ?? 1));
$offset   = ($page - 1) * $PER_PAGE;
$search   = trim((string)($_GET['search'] ?? ''));

// Base SELECT — includes subquery to get last issued_at for duration calc
$baseSelect = "
    SELECT l.log_id, l.laptop_id, l.student_id, l.action, l.staff_name, l.timestamp,
           s.name AS student_name, s.class AS student_class, s.stream AS student_stream,
           lap.model, lap.serial_number, lap.laptop_number,
           (SELECT lg2.timestamp
            FROM logs lg2
            WHERE lg2.laptop_id = l.laptop_id
              AND LOWER(TRIM(lg2.action)) IN ('issued','issued out')
              AND lg2.timestamp < l.timestamp
            ORDER BY lg2.timestamp DESC LIMIT 1) AS last_issued_at
    FROM logs l
    LEFT JOIN students s  ON l.student_id  = s.student_id
    LEFT JOIN laptops  lap ON l.laptop_id  = lap.laptop_id
";

try {
    if ($search !== '') {
        $like = '%' . $search . '%';

        // Total count for pagination
        $cSql  = "SELECT COUNT(*) AS c FROM logs l
                  LEFT JOIN students s ON l.student_id = s.student_id
                  LEFT JOIN laptops lap ON l.laptop_id = lap.laptop_id
                  WHERE s.name LIKE ? OR lap.serial_number LIKE ? OR l.action LIKE ?
                     OR l.staff_name LIKE ? OR lap.laptop_number LIKE ?";
        $cStmt = $conn->prepare($cSql);
        $cStmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $cStmt->execute();
        $total = (int)$cStmt->get_result()->fetch_assoc()['c'];
        $cStmt->close();

        $sql   = $baseSelect . "
                  WHERE s.name LIKE ? OR lap.serial_number LIKE ? OR l.action LIKE ?
                     OR l.staff_name LIKE ? OR lap.laptop_number LIKE ?
                  ORDER BY l.timestamp DESC
                  LIMIT ? OFFSET ?";
        $stmt  = $conn->prepare($sql);
        $stmt->bind_param('sssssii', $like, $like, $like, $like, $like, $PER_PAGE, $offset);
        $stmt->execute();
        $logs  = $stmt->get_result();
        $stmt->close();
    } else {
        // Count
        $cr = $conn->query("SELECT COUNT(*) AS c FROM logs");
        $total = $cr ? (int)$cr->fetch_assoc()['c'] : 0;

        $sql  = $baseSelect . " ORDER BY l.timestamp DESC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $PER_PAGE, $offset);
        $stmt->execute();
        $logs = $stmt->get_result();
        $stmt->close();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Server error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

$total_pages = max(1, (int)ceil($total / $PER_PAGE));

// ── Badge helper ─────────────────────────────────────────────────────────────
function action_badge(string $action): array {
    switch (strtolower(trim($action))) {
        case 'issued':      case 'issued out':     return ['badge-issued',  'Issued'];
        case 'returned':    case 'returned in':    return ['badge-returned','Returned'];
        case 'out for project': case 'out_for_project': return ['badge-out','Out for Project'];
        case 'back to school':  case 'back_to_school':  return ['badge-school','Back to School'];
        case 'taken home':  case 'taken_home':     return ['badge-home',   'Taken Home'];
        default: return ['badge-default', htmlspecialchars($action)];
    }
}

// ── Duration helper (returns "Xh Ym" or "—") ─────────────────────────────────
function usage_duration(string $action, ?string $ts, ?string $last_issued): string {
    $a = strtolower(trim($action));
    if (!in_array($a, ['returned','returned in','back to school','back_to_school'], true)) return '—';
    if (!$ts || !$last_issued) return '—';
    $diff = strtotime($ts) - strtotime($last_issued);
    if ($diff <= 0) return '—';
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    if ($h > 0) return "{$h}h {$m}m";
    return $m > 0 ? "{$m}m" : '<1m';
}

$page_title = 'Activity Logs';
include('../includes/header.php');
// NOTE: header.php already includes sidebar.php — do NOT include it again here.
?>

<style>
.badge-action { display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; font-size:11.5px; font-weight:700; }
.badge-issued  { background:rgba(99,102,241,.12);  color:#4f46e5; }
.badge-returned{ background:rgba(16,185,129,.12);  color:#047857; }
.badge-out     { background:rgba(245,158,11,.12);  color:#b45309; }
.badge-school  { background:rgba(14,165,233,.12);  color:#0369a1; }
.badge-home    { background:rgba(244,63,94,.12);   color:#be123c; }
.badge-default { background:var(--bg-soft);        color:var(--text-muted); }

.dur-pill {
    display:inline-flex; align-items:center; gap:4px;
    font-size:11.5px; font-weight:700;
    padding:3px 9px; border-radius:999px;
    background:rgba(99,102,241,.10); color:#4f46e5;
}

.pagination { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:16px; }
.pagination a, .pagination span {
    padding:6px 12px; border-radius:8px; font-size:13px; font-weight:600;
    border:1px solid var(--border); background:var(--bg-elev); color:var(--text);
    text-decoration:none; transition:all .15s;
}
.pagination a:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
.pagination span.current { background:var(--primary); color:#fff; border-color:var(--primary); }
.pagination span.disabled { opacity:.45; pointer-events:none; }
.pagination .info { border:0; background:transparent; color:var(--text-muted); font-size:12px; }
</style>

<div class="page-header" style="margin-bottom:18px;">
    <div>
        <h2 class="title">Activity Logs</h2>
        <p class="subtitle">All laptop transactions — showing <?= number_format($total) ?> records</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button id="clearLogsBtn" class="btn btn-outline" style="color:var(--danger); border-color:var(--danger);">
            <i class="fas fa-trash-can"></i> Clear All
        </button>
    </div>
</div>

<!-- Search -->
<form method="GET" class="d-flex gap-2 mb-4" style="max-width:560px;">
    <input type="text" name="search" class="form-control"
           placeholder="Search by student, serial, action, staff or laptop #…"
           value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    <?php if ($search): ?>
        <a href="logs.php" class="btn btn-outline"><i class="fas fa-xmark"></i></a>
    <?php endif; ?>
</form>

<div class="table-wrapper">
    <div class="table-responsive">
        <table class="table" id="logsTable">
            <thead>
                <tr>
                    <th style="width:55px;">#</th>
                    <th>Student</th>
                    <th>Laptop</th>
                    <th>Serial</th>
                    <th>Action</th>
                    <th>Duration</th>
                    <th>Staff</th>
                    <th>Date &amp; Time</th>
                    <th style="width:60px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs && $logs->num_rows > 0):
                $sn = $offset + 1;
                while ($row = $logs->fetch_assoc()):
                    $ts        = $row['timestamp'] ?? null;
                    $action    = $row['action'] ?? '';
                    [$badge, $label] = action_badge($action);
                    $dur       = usage_duration($action, $ts, $row['last_issued_at'] ?? null);
                    $studentMeta = array_filter([$row['student_class'] ?? '', $row['student_stream'] ?? '']);
            ?>
                <tr id="log-row-<?= intval($row['log_id']) ?>">
                    <td class="text-soft" style="font-size:12px;"><?= $sn++ ?></td>
                    <td>
                        <?= htmlspecialchars($row['student_name'] ?? 'N/A') ?>
                        <?php if ($studentMeta): ?>
                            <div class="text-soft" style="font-size:11px;"><?= htmlspecialchars(implode(' · ', $studentMeta)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= htmlspecialchars($row['laptop_number'] ?? '—') ?></strong>
                        <?php if (!empty($row['model'])): ?>
                            <div class="text-soft" style="font-size:11px;"><?= htmlspecialchars($row['model']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size:11.5px;"><?= htmlspecialchars($row['serial_number'] ?? '—') ?></code></td>
                    <td><span class="badge-action <?= $badge ?>"><?= $label ?></span></td>
                    <td>
                        <?php if ($dur !== '—'): ?>
                            <span class="dur-pill"><i class="fas fa-clock"></i> <?= $dur ?></span>
                        <?php else: ?>
                            <span class="text-soft">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['staff_name'] ?? '') ?></td>
                    <td style="font-size:12.5px; white-space:nowrap;">
                        <?php if ($ts): ?>
                            <?= date('d M Y', strtotime($ts)) ?>
                            <div class="text-soft" style="font-size:11px;"><?= date('H:i', strtotime($ts)) ?></div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-ghost delete-log"
                                data-log-id="<?= intval($row['log_id']) ?>"
                                title="Delete"
                                style="color:var(--danger); padding:4px 8px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="9" class="text-center" style="padding:32px 12px; color:var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size:28px; opacity:.4; display:block; margin-bottom:8px;"></i>
                        No logs found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav class="pagination">
    <?php
    $base = '?' . http_build_query(array_filter(['search' => $search]));
    $sep  = $search ? '&' : '?';
    ?>
    <?php if ($page > 1): ?>
        <a href="<?= $base ?>&pg=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
    <?php endif; ?>

    <?php
    $from = max(1, $page - 2);
    $to   = min($total_pages, $page + 2);
    if ($from > 1) echo '<a href="' . $base . '&pg=1">1</a>';
    if ($from > 2) echo '<span class="info">…</span>';
    for ($i = $from; $i <= $to; $i++):
        if ($i === $page) echo '<span class="current">' . $i . '</span>';
        else echo '<a href="' . $base . '&pg=' . $i . '">' . $i . '</a>';
    endfor;
    if ($to < $total_pages - 1) echo '<span class="info">…</span>';
    if ($to < $total_pages) echo '<a href="' . $base . '&pg=' . $total_pages . '">' . $total_pages . '</a>';
    ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?= $base ?>&pg=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
    <?php endif; ?>

    <span class="info">Page <?= $page ?> of <?= $total_pages ?> (<?= number_format($total) ?> records)</span>
</nav>
<?php endif; ?>

<script>
// ── Delete single log ──────────────────────────────────────────────────────
document.querySelectorAll('.delete-log').forEach(btn => {
    btn.addEventListener('click', function () {
        const logId = this.dataset.logId;
        if (!confirm('Delete this log entry?')) return;
        fetch('logs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_log&log_id=' + encodeURIComponent(logId)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('log-row-' + logId);
                if (row) row.remove();
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
            }
        })
        .catch(() => alert('Network error. Please try again.'));
    });
});

// ── Clear all logs ─────────────────────────────────────────────────────────
document.getElementById('clearLogsBtn').addEventListener('click', function () {
    if (!confirm('This will permanently delete ALL log entries. Are you sure?')) return;
    fetch('logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear_logs'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + (data.error || 'Unknown'));
    })
    .catch(() => alert('Network error. Please try again.'));
});
</script>

<?php include('../includes/footer.php'); ?>
