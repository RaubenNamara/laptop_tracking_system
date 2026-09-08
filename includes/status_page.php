<?php
/**
 * Shared renderer for status-filtered laptop pages.
 *
 * Usage from a page (e.g. issued.php):
 *   $status_filter = 'issued';
 *   $page_title    = 'Issued Laptops';
 *   $page_subtitle = 'Laptops currently with students';
 *   include('../includes/status_page.php');
 */

if (!isset($conn)) {
    require_once(__DIR__ . '/../config/config.php');
}
require_once(__DIR__ . '/migrate.php');
lts_run_migrations($conn);

$status_filter = $status_filter ?? 'issued';
$page_title    = $page_title    ?? ucwords(str_replace('_', ' ', $status_filter)) . ' Laptops';
$page_subtitle = $page_subtitle ?? '';

$stmt = $conn->prepare("
    SELECT l.laptop_id, l.laptop_number, l.serial_number, l.model, l.status, l.lab,
           l.issued_at, l.returned_at, l.due_date,
           s.student_id, s.name AS student_name, s.class AS student_class, s.stream AS student_stream
    FROM laptops l
    LEFT JOIN students s ON l.student_id = s.student_id
    WHERE l.status = ?
    ORDER BY COALESCE(l.issued_at, l.created_at) DESC, l.laptop_id DESC
");
$stmt->bind_param('s', $status_filter);
$stmt->execute();
$result = $stmt->get_result();
$count  = $result ? $result->num_rows : 0;

include(__DIR__ . '/header.php');

function lts_fmt_date($v) {
    if (!$v || $v === '0000-00-00 00:00:00') return '<span class="text-soft">—</span>';
    $t = strtotime($v);
    return $t ? date('d M Y · H:i', $t) : htmlspecialchars($v);
}
function lts_overdue($due) {
    if (!$due) return false;
    return strtotime($due) < time();
}
?>
<style>
.sp-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid transparent;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s ease;
    text-decoration: none;
}
.sp-btn-issue {
    background: rgba(251,191,36,.15);
    color: #b45309;
    border-color: rgba(251,191,36,.35);
}
.sp-btn-issue:hover { background: rgba(251,191,36,.28); color: #92400e; }
.sp-btn-return {
    background: rgba(14,165,233,.12);
    color: #0369a1;
    border-color: rgba(14,165,233,.30);
}
.sp-btn-return:hover { background: rgba(14,165,233,.24); color: #075985; }
.sp-btn-loading { opacity: .6; pointer-events: none; }

.sp-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    background: #0f172a; color: #fff;
    padding: 12px 18px; border-radius: 12px;
    font-size: 13.5px; font-weight: 600;
    box-shadow: 0 8px 24px rgba(15,23,42,.35);
    display: flex; align-items: center; gap: 10px;
    transform: translateY(80px); opacity: 0;
    transition: all .3s cubic-bezier(.34,1.56,.64,1);
    max-width: 320px;
}
.sp-toast.show { transform: translateY(0); opacity: 1; }
.sp-toast.success { background: #064e3b; border: 1px solid #065f46; }
.sp-toast.error   { background: #7f1d1d; border: 1px solid #991b1b; }
</style>

<div class="page-header">
    <div>
        <h2 class="title"><?= htmlspecialchars($page_title) ?></h2>
        <?php if ($page_subtitle): ?>
            <p class="subtitle"><?= htmlspecialchars($page_subtitle) ?></p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge-pill badge-<?= htmlspecialchars($status_filter) ?>" style="font-size:13px;">
            <?= $count ?> <?= $count === 1 ? 'laptop' : 'laptops' ?>
        </span>
        <a href="scan.php" class="btn btn-outline"><i class="fas fa-qrcode"></i> Quick Scan</a>
        <a href="laptops.php" class="btn btn-outline"><i class="fas fa-laptop"></i> All Laptops</a>
    </div>
</div>

<div class="table-wrapper">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Laptop</th>
                    <th>Serial</th>
                    <th>Model</th>
                    <th>Lab</th>
                    <th>Student</th>
                    <th>Issued</th>
                    <th>Due / Returned</th>
                    <th style="width:120px;">Status</th>
                    <th style="width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sn = 1;
            if ($count > 0):
                while ($row = $result->fetch_assoc()):
                    $st       = $row['status'] ?? '';
                    $issued   = $row['issued_at']   ?? null;
                    $returned = $row['returned_at'] ?? null;
                    $due      = $row['due_date']    ?? null;
                    $isOverdue = ($st === 'issued') && lts_overdue($due);
                    $lid      = (int)($row['laptop_id'] ?? 0);
                    $sid      = (int)($row['student_id'] ?? 0);
            ?>
                <tr id="row-<?= $lid ?>"<?= $isOverdue ? ' style="background: rgba(239,68,68,.06);"' : '' ?>>
                    <td><?= $sn++ ?></td>
                    <td><strong><?= htmlspecialchars($row['laptop_number'] ?? '—') ?></strong></td>
                    <td><code style="font-size:12px;"><?= htmlspecialchars($row['serial_number'] ?? '—') ?></code></td>
                    <td><?= htmlspecialchars($row['model'] ?? '—') ?></td>
                    <td>
                        <?php $lab_v = $row['lab'] ?? 'olevel'; ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;background:<?= $lab_v==='alevel'?'rgba(139,92,246,.12)':'rgba(59,130,246,.12)' ?>;color:<?= $lab_v==='alevel'?'#7c3aed':'#2563eb' ?>;">
                            <?= $lab_v === 'alevel' ? 'A-Level' : 'O-Level' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($row['student_name'])): ?>
                            <?= htmlspecialchars($row['student_name']) ?>
                            <?php
                                $meta = array_filter([
                                    $row['student_class']  ?? '',
                                    $row['student_stream'] ?? '',
                                ]);
                            ?>
                            <?php if ($meta): ?>
                                <div class="text-soft" style="font-size:12px;"><?= htmlspecialchars(implode(' · ', $meta)) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-soft">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= lts_fmt_date($issued) ?></td>
                    <td>
                        <?php if ($st === 'returned' && $returned): ?>
                            <span style="color:var(--success);"><i class="fas fa-check-circle"></i> <?= lts_fmt_date($returned) ?></span>
                        <?php elseif ($due): ?>
                            <span style="color:<?= $isOverdue ? 'var(--danger)' : 'var(--text)' ?>; font-weight:<?= $isOverdue ? '700' : '500' ?>;">
                                <?php if ($isOverdue): ?><i class="fas fa-triangle-exclamation"></i> <?php endif; ?>
                                <?= lts_fmt_date($due) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-soft">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge-pill badge-<?= htmlspecialchars($st) ?>" id="badge-<?= $lid ?>">
                            <?= ucwords(str_replace('_', ' ', $st)) ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <?php if ($st !== 'issued'): ?>
                            <button class="sp-action-btn sp-btn-issue"
                                    onclick="spUpdateStatus(<?= $lid ?>, <?= $sid ?>, 'issued', this)"
                                    title="Mark as Issued">
                                <i class="fas fa-paper-plane"></i> Issue
                            </button>
                            <?php endif; ?>
                            <?php if ($st !== 'returned'): ?>
                            <button class="sp-action-btn sp-btn-return"
                                    onclick="spUpdateStatus(<?= $lid ?>, <?= $sid ?>, 'returned', this)"
                                    title="Mark as Returned">
                                <i class="fas fa-rotate-left"></i> Return
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="10" class="text-center" style="padding:32px 12px; color: var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size:28px; opacity:.4; display:block; margin-bottom:8px;"></i>
                        No laptops are currently <strong><?= htmlspecialchars(str_replace('_', ' ', $status_filter)) ?></strong>.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Toast notification -->
<div class="sp-toast" id="spToast"></div>

<script>
const STATUS_LABELS_SP = {
    issued: 'Issued', returned: 'Returned', out_for_project: 'Out for Project',
    back_to_school: 'Back to School', taken_home: 'Taken Home'
};

let spToastTimer = null;
function showToast(msg, type = 'success') {
    const t = document.getElementById('spToast');
    t.textContent = msg;
    t.className = 'sp-toast show ' + type;
    clearTimeout(spToastTimer);
    spToastTimer = setTimeout(() => { t.className = 'sp-toast'; }, 3200);
}

function spUpdateStatus(laptopId, studentId, newStatus, btn) {
    btn.classList.add('sp-btn-loading');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> …';

    fetch('../pages/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ laptop_id: laptopId, status: newStatus })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.message || 'Update failed');

        // Log the action
        return fetch('../pages/logs_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ laptop_id: laptopId, student_id: studentId, action: newStatus, note: '' })
        }).then(r => r.json()).then(() => {
            showToast('✓ Laptop marked as ' + (STATUS_LABELS_SP[newStatus] || newStatus), 'success');
            // Reload to reflect updated list
            setTimeout(() => location.reload(), 800);
        });
    })
    .catch(err => {
        btn.classList.remove('sp-btn-loading');
        btn.innerHTML = origHtml;
        showToast('Error: ' + err.message, 'error');
    });
}
</script>

<?php
$stmt->close();
include(__DIR__ . '/footer.php');
