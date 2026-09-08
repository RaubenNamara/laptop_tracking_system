<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);

// ── Handle lab transfer POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_laptops'])) {
    $target_lab = ($_POST['target_lab'] ?? '') === 'alevel' ? 'alevel' : 'olevel';
    $ids_raw = $_POST['laptop_ids'] ?? [];
    $ids = array_filter(array_map('intval', (array)$ids_raw));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare(
            "UPDATE laptops SET lab = ? WHERE laptop_id IN ($placeholders)"
        );
        $params = array_merge([$target_lab], $ids);
        $types_full = 's' . $types;
        $stmt->bind_param($types_full, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $label = lts_lab_label($target_lab);
        $_SESSION['flash'] = ['type' => 'success',
            'msg' => "$affected laptop(s) transferred to $label."];
    }
    header("Location: labs.php" . (isset($_GET['lab']) ? '?lab=' . htmlspecialchars($_GET['lab']) : ''));
    exit();
}

// ── Handle single laptop transfer via GET (quick action) ────────────────
if (isset($_GET['transfer']) && isset($_GET['to'])) {
    $laptop_id = intval($_GET['transfer']);
    $to_lab = ($_GET['to'] === 'alevel') ? 'alevel' : 'olevel';
    if ($laptop_id > 0) {
        $stmt = $conn->prepare("UPDATE laptops SET lab = ? WHERE laptop_id = ?");
        $stmt->bind_param('si', $to_lab, $laptop_id);
        $stmt->execute();
        $stmt->close();
        $label = lts_lab_label($to_lab);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Laptop transferred to $label."];
    }
    $redirect_lab = $_GET['from'] ?? '';
    header("Location: labs.php" . ($redirect_lab ? '?lab=' . htmlspecialchars($redirect_lab) : ''));
    exit();
}

// ── Stats ────────────────────────────────────────────────────────────────
$stats = [];
$r = $conn->query("SELECT lab, COUNT(*) AS total,
    SUM(status='issued') AS issued,
    SUM(status='returned') AS returned,
    SUM(status='out_for_project') AS out_for_project,
    SUM(status='back_to_school') AS back_to_school,
    SUM(status='taken_home') AS taken_home
    FROM laptops GROUP BY lab");
while ($row = ($r ? $r->fetch_assoc() : null)) {
    $stats[$row['lab']] = $row;
}
$olevel_total = (int)($stats['olevel']['total'] ?? 0);
$alevel_total = (int)($stats['alevel']['total'] ?? 0);
$olevel_issued = (int)($stats['olevel']['issued'] ?? 0);
$alevel_issued = (int)($stats['alevel']['issued'] ?? 0);

// ── Active lab filter ────────────────────────────────────────────────────
$lab_filter = $_GET['lab'] ?? '';
if (!in_array($lab_filter, ['olevel', 'alevel'], true)) $lab_filter = '';

// ── Laptop list ──────────────────────────────────────────────────────────
if ($lab_filter) {
    $stmt = $conn->prepare("
        SELECT l.*, s.name AS student_name, s.class AS student_class, s.stream AS student_stream
        FROM laptops l
        LEFT JOIN students s ON l.student_id = s.student_id
        WHERE l.lab = ?
        ORDER BY l.laptop_id DESC
    ");
    $stmt->bind_param('s', $lab_filter);
    $stmt->execute();
    $laptops_res = $stmt->get_result();
} else {
    $laptops_res = $conn->query("
        SELECT l.*, s.name AS student_name, s.class AS student_class, s.stream AS student_stream
        FROM laptops l
        LEFT JOIN students s ON l.student_id = s.student_id
        ORDER BY l.lab ASC, l.laptop_id DESC
    ");
}

$page_title = 'Cyber Labs';
include('../includes/header.php');

function normalize_status_lab($s) {
    $s = strtolower(trim((string)($s ?? '')));
    return str_replace(' ', '_', preg_replace('/[^a-z0-9_ ]/', '', $s));
}
?>

<style>
.lab-hero {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 768px) { .lab-hero { grid-template-columns: 1fr; } }

.lab-card {
    border-radius: 18px;
    padding: 24px 26px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: all .18s ease;
    text-decoration: none;
    display: block;
    position: relative;
    overflow: hidden;
}
.lab-card::before {
    content: '';
    position: absolute; inset: 0;
    opacity: .07;
    border-radius: inherit;
}
.lab-card.olevel { background: var(--bg-elev); border-color: #3b82f6; }
.lab-card.olevel::before { background: #3b82f6; }
.lab-card.alevel { background: var(--bg-elev); border-color: #8b5cf6; }
.lab-card.alevel::before { background: #8b5cf6; }
.lab-card.active { box-shadow: 0 0 0 3px rgba(99,102,241,.25); }
.lab-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -10px rgba(15,23,42,.18); }

.lab-card .lab-icon {
    width: 52px; height: 52px; border-radius: 14px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 22px; margin-bottom: 14px;
}
.lab-card.olevel .lab-icon { background: rgba(59,130,246,.15); color: #3b82f6; }
.lab-card.alevel .lab-icon { background: rgba(139,92,246,.15); color: #8b5cf6; }

.lab-card .lab-name { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.lab-card .lab-classes { font-size: 12.5px; color: var(--text-muted); font-weight: 600; margin-bottom: 16px; }
.lab-card .lab-stats { display: flex; gap: 18px; flex-wrap: wrap; }
.lab-card .lab-stat .n { font-size: 24px; font-weight: 800; color: var(--text); letter-spacing: -.02em; line-height: 1; }
.lab-card .lab-stat .l { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-top: 3px; }
.lab-card .transfer-hint {
    position: absolute; top: 16px; right: 16px;
    font-size: 11.5px; font-weight: 600; color: var(--text-muted);
    display: flex; align-items: center; gap: 5px;
}

.lab-filter-bar {
    display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;
}
.lab-filter-bar .seg {
    display: inline-flex; background: var(--bg-soft); padding: 3px; border-radius: 12px;
    border: 1px solid var(--border);
}
.lab-filter-bar .seg a {
    padding: 7px 16px; border-radius: 9px; font-size: 13px; font-weight: 600;
    color: var(--text-muted); text-decoration: none; transition: all .15s;
}
.lab-filter-bar .seg a.on {
    background: var(--bg-elev); color: var(--text);
    box-shadow: 0 1px 3px rgba(15,23,42,.1);
}

.transfer-panel {
    background: var(--bg-elev); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 20px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.transfer-panel .tp-label { font-weight: 700; font-size: 14px; color: var(--text); flex: 1; min-width: 180px; }
.transfer-panel .tp-sub { font-size: 12.5px; color: var(--text-muted); }
.transfer-panel .tp-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.lab-action-group {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    flex-wrap: wrap;
}

.lab-action-group .btn {
    min-width: 150px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
    padding: 0.48rem 0.8rem;
    border-radius: 8px;
    font-size: 0.8rem;
    line-height: 1.2;
}

.laptop-grid-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13.5px;
    background: var(--bg-elev);
    border-radius: 14px;
    overflow: hidden;
}
.laptop-grid-table th {
    padding: 12px 14px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #fff;
    border-bottom: 0;
    background: linear-gradient(180deg, #4361ee, #3a56e0);
    white-space: nowrap;
}
.laptop-grid-table td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--border-soft);
    vertical-align: middle;
    background: transparent;
}
.laptop-grid-table tr:last-child td { border-bottom: 0; }
.laptop-grid-table tbody tr:nth-child(even) td { background: rgba(148,163,184,.03); }
.laptop-grid-table tbody tr:hover td { background: rgba(67,97,238,.05); }

.lab-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
}
.lab-badge.olevel { background: rgba(59,130,246,.12); color: #2563eb; }
.lab-badge.alevel { background: rgba(139,92,246,.12); color: #7c3aed; }

.flash-wrapper { margin-bottom: 16px; }
</style>

<?php if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash'];
    $bs = ($f['type'] === 'success') ? 'success' : (($f['type'] === 'danger') ? 'danger' : 'warning');
    unset($_SESSION['flash']);
?>
<div class="flash-wrapper">
    <div class="alert alert-<?= $bs ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h2 class="title">Cyber Labs</h2>
        <p class="subtitle">Manage laptop allocation across O-Level and A-Level cyber labs.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="laptops.php" class="btn btn-outline"><i class="fas fa-laptop"></i> All Laptops</a>
    </div>
</div>

<!-- Lab overview cards -->
<div class="lab-hero">
    <a href="labs.php?lab=olevel" class="lab-card olevel<?= ($lab_filter === 'olevel') ? ' active' : '' ?>">
        <div class="lab-icon"><i class="fas fa-computer"></i></div>
        <div class="lab-name">O-Level Cyber Lab</div>
        <div class="lab-classes"><i class="fas fa-users"></i> &nbsp;Classes: S.1 · S.3 · S.4</div>
        <div class="lab-stats">
            <div class="lab-stat">
                <div class="n"><?= $olevel_total ?></div>
                <div class="l">Total</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= $olevel_issued ?></div>
                <div class="l">Issued</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= (int)($stats['olevel']['returned'] ?? 0) ?></div>
                <div class="l">Returned</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= (int)($stats['olevel']['out_for_project'] ?? 0) ?></div>
                <div class="l">Out</div>
            </div>
        </div>
        <div class="transfer-hint"><i class="fas fa-arrow-right"></i> View</div>
    </a>

    <a href="labs.php?lab=alevel" class="lab-card alevel<?= ($lab_filter === 'alevel') ? ' active' : '' ?>">
        <div class="lab-icon"><i class="fas fa-desktop"></i></div>
        <div class="lab-name">A-Level Cyber Lab</div>
        <div class="lab-classes"><i class="fas fa-users"></i> &nbsp;Classes: S.2 · S.5 · S.6</div>
        <div class="lab-stats">
            <div class="lab-stat">
                <div class="n"><?= $alevel_total ?></div>
                <div class="l">Total</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= $alevel_issued ?></div>
                <div class="l">Issued</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= (int)($stats['alevel']['returned'] ?? 0) ?></div>
                <div class="l">Returned</div>
            </div>
            <div class="lab-stat">
                <div class="n"><?= (int)($stats['alevel']['out_for_project'] ?? 0) ?></div>
                <div class="l">Out</div>
            </div>
        </div>
        <div class="transfer-hint"><i class="fas fa-arrow-right"></i> View</div>
    </a>
</div>

<!-- Filter bar -->
<div class="lab-filter-bar">
    <div class="seg">
        <a href="labs.php" class="<?= $lab_filter === '' ? 'on' : '' ?>">All Labs</a>
        <a href="labs.php?lab=olevel" class="<?= $lab_filter === 'olevel' ? 'on' : '' ?>">O-Level Lab</a>
        <a href="labs.php?lab=alevel" class="<?= $lab_filter === 'alevel' ? 'on' : '' ?>">A-Level Lab</a>
    </div>
    <input type="text" id="labSearch" class="form-control" style="max-width:260px;"
           placeholder="Search laptops…" oninput="filterLabTable(this.value)">
</div>

<!-- Bulk transfer panel -->
<div class="transfer-panel" id="bulkPanel" style="display:none;">
    <div>
        <div class="tp-label"><i class="fas fa-arrows-left-right"></i> &nbsp;<span id="selectedCount">0</span> laptop(s) selected</div>
        <div class="tp-sub">Choose target lab to transfer selected laptops</div>
    </div>
    <div class="tp-actions">
        <form method="POST" id="bulkForm">
            <input type="hidden" name="transfer_laptops" value="1">
            <input type="hidden" name="target_lab" id="bulkTargetLab" value="">
            <div id="bulkIdsContainer"></div>
            <button type="button" class="btn btn-outline" onclick="bulkTransfer('olevel')">
                <i class="fas fa-computer"></i> → O-Level Lab
            </button>
            <button type="button" class="btn btn-primary ms-2" onclick="bulkTransfer('alevel')">
                <i class="fas fa-desktop"></i> → A-Level Lab
            </button>
        </form>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="clearSelection()"><i class="fas fa-times"></i></button>
</div>

<!-- Laptops table -->
<section class="table-card">
    <div class="table-responsive">
        <table class="laptop-grid-table" id="labTable">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" title="Select all"></th>
                    <th>S/N</th>
                    <th>Lab</th>
                    <th>Laptop #</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>Status</th>
                    <th>Transfer</th>
                </tr>
            </thead>
            <tbody id="labTableBody">
            <?php if ($laptops_res && $laptops_res->num_rows > 0):
                $sn = 1;
                while ($row = $laptops_res->fetch_assoc()):
                    $lab = $row['lab'] ?? 'olevel';
                    $st  = normalize_status_lab($row['status'] ?? 'out_for_project');
                    $label = ucwords(str_replace('_', ' ', $st));
                    $badgeClass = [
                        'issued' => 'status-issued', 'returned' => 'status-returned',
                        'back_to_school' => 'status-back-to-school',
                        'taken_home' => 'status-taken-home',
                        'out_for_project' => 'status-out-for-project',
                    ][$st] ?? 'status-out-for-project';
                    $other_lab = ($lab === 'olevel') ? 'alevel' : 'olevel';
                    $other_label = ($lab === 'olevel') ? 'A-Level Lab' : 'O-Level Lab';
                    $class_parts = array_filter([$row['student_class'] ?? '', $row['student_stream'] ?? '']);
                    $class_str = implode(' ', $class_parts) ?: '—';
            ?>
                <tr>
                    <td><input type="checkbox" class="row-check" value="<?= intval($row['laptop_id']) ?>"></td>
                    <td><?= $sn++ ?></td>
                    <td>
                        <span class="lab-badge <?= htmlspecialchars($lab) ?>">
                            <i class="fas fa-<?= $lab === 'olevel' ? 'o' : 'a' ?>"></i>
                            <?= $lab === 'olevel' ? 'O-Level' : 'A-Level' ?>
                        </span>
                    </td>
                    <td><strong><?= htmlspecialchars($row['laptop_number'] ?? '—') ?></strong></td>
                    <td><?= htmlspecialchars($row['student_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($class_str) ?></td>
                    <td><?= htmlspecialchars($row['model'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['serial_number'] ?? '—') ?></td>
                    <td><span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span></td>
                    <td>
                        <div class="lab-action-group">
                            <a href="labs.php?transfer=<?= intval($row['laptop_id']) ?>&to=<?= $other_lab ?>&from=<?= $lab_filter ?>"
                               class="btn btn-sm btn-outline"
                               onclick="return confirm('Transfer this laptop to <?= $other_label ?>?')"
                               title="Move to <?= $other_label ?>">
                                <i class="fas fa-arrows-left-right"></i> <?= $other_label ?>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="10" class="text-center" style="padding:40px; color:var(--text-muted);">
                    <i class="fas fa-laptop" style="font-size:32px; opacity:.3; display:block; margin-bottom:10px;"></i>
                    No laptops found<?= $lab_filter ? ' in this lab' : '' ?>.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include('../includes/footer.php'); ?>
<script>
function filterLabTable(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#labTableBody tr').forEach(row => {
        row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
}

// ── Bulk selection ────────────────────────────────────────────────────────
const panel      = document.getElementById('bulkPanel');
const countSpan  = document.getElementById('selectedCount');
const selectAll  = document.getElementById('selectAll');
const idsContainer = document.getElementById('bulkIdsContainer');

function updatePanel() {
    const checked = document.querySelectorAll('.row-check:checked');
    countSpan.textContent = checked.length;
    panel.style.display   = checked.length > 0 ? 'flex' : 'none';
}

document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updatePanel));
selectAll.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') cb.checked = this.checked;
    });
    updatePanel();
});

function clearSelection() {
    document.querySelectorAll('.row-check, #selectAll').forEach(cb => cb.checked = false);
    updatePanel();
}

function bulkTransfer(targetLab) {
    const checked = document.querySelectorAll('.row-check:checked');
    if (!checked.length) return;
    const labLabel = targetLab === 'alevel' ? 'A-Level Cyber Lab' : 'O-Level Cyber Lab';
    if (!confirm('Transfer ' + checked.length + ' laptop(s) to ' + labLabel + '?')) return;
    document.getElementById('bulkTargetLab').value = targetLab;
    idsContainer.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'laptop_ids[]';
        input.value = cb.value;
        idsContainer.appendChild(input);
    });
    document.getElementById('bulkForm').submit();
}
</script>
