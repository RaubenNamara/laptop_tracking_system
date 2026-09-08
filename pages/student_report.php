<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');

$page_title = 'Student Report';
include('../includes/header.php');

// Get student ID from URL
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Fetch student info
$student = null;
if ($student_id > 0) {
    $stmt = $conn->prepare("
        SELECT s.*, p.name AS parent_name, p.contact AS parent_contact, p.email AS parent_email
        FROM students s
        LEFT JOIN parents p ON s.parent_id = p.parent_id
        WHERE s.student_id = ?
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$student) {
    echo '<div class="alert alert-danger">Student not found.</div>';
    include('../includes/footer.php');
    exit();
}

// Fetch all laptops assigned to this student with usage stats
$laptops_query = "
    SELECT 
        l.*,
        COALESCE(us.total_sessions, 0) AS usage_count,
        COALESCE(us.total_hours, 0) AS usage_hours,
        COALESCE(us.last_used, '—') AS last_used_date,
        COALESCE(us.first_used, '—') AS first_used_date,
        COALESCE(us.avg_duration_min, 0) AS avg_duration_min
    FROM laptops l
    LEFT JOIN (
        SELECT 
            laptop_id,
            COUNT(*) AS total_sessions,
            ROUND(SUM(COALESCE(duration_minutes, 0)) / 60, 1) AS total_hours,
            MAX(usage_date) AS last_used,
            MIN(usage_date) AS first_used,
            ROUND(AVG(COALESCE(duration_minutes, 0)), 0) AS avg_duration_min
        FROM laptop_usage_log
        WHERE student_id = ?
        GROUP BY laptop_id
    ) us ON l.laptop_id = us.laptop_id
    WHERE l.student_id = ?
    ORDER BY l.laptop_number ASC
";

$stmt = $conn->prepare($laptops_query);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$laptops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Ensure laptops is always an array
if (!is_array($laptops)) {
    $laptops = [];
}

// Fetch all usage history for this student
// FIXED: Changed 'st.name' to 'st.username'
$history_query = "
    SELECT 
        ul.*,
        l.laptop_number,
        l.model AS laptop_model,
        l.serial_number,
        st.username AS staff_name
    FROM laptop_usage_log ul
    LEFT JOIN laptops l ON ul.laptop_id = l.laptop_id
    LEFT JOIN users st ON ul.staff_id = st.user_id
    WHERE ul.student_id = ?
    ORDER BY ul.usage_date DESC, ul.usage_time DESC
    LIMIT 50
";

$stmt = $conn->prepare($history_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Ensure history is always an array
if (!is_array($history)) {
    $history = [];
}

// Aggregate stats
$total_sessions = array_sum(array_column($laptops, 'usage_count'));
$total_hours = array_sum(array_column($laptops, 'usage_hours'));
$unique_laptops = count(array_filter($laptops, function($l) { return $l['usage_count'] > 0; }));

// Monthly usage pattern
$monthly_query = "
    SELECT 
        DATE_FORMAT(usage_date, '%Y-%m') AS month,
        COUNT(*) AS sessions,
        SUM(COALESCE(duration_minutes, 0)) AS total_minutes
    FROM laptop_usage_log
    WHERE student_id = ?
    GROUP BY month
    ORDER BY month ASC
";

$stmt = $conn->prepare($monthly_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$monthly_usage = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!is_array($monthly_usage)) {
    $monthly_usage = [];
}

// Action breakdown
$action_query = "
    SELECT 
        action,
        COUNT(*) AS count
    FROM laptop_usage_log
    WHERE student_id = ?
    GROUP BY action
";

$stmt = $conn->prepare($action_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$action_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// FIXED: Ensure action_breakdown is always an array
if (!is_array($action_breakdown)) {
    $action_breakdown = [];
}

// Handle PDF download
if ($action === 'download_pdf') {
    generateStudentPDF($student, $laptops, $history, $monthly_usage, $action_breakdown, $total_sessions, $total_hours, $unique_laptops, $conn);
    exit();
}

function generateStudentPDF($student, $laptops, $history, $monthly_usage, $action_breakdown, $total_sessions, $total_hours, $unique_laptops, $conn) {
    $tcpdfPath = __DIR__ . '/../TCPDF/tcpdf.php';
    if (!file_exists($tcpdfPath)) {
        $tcpdfPath = $_SERVER['DOCUMENT_ROOT'] . '/laptop_tracking_system/TCPDF/tcpdf.php';
    }
    if (!file_exists($tcpdfPath)) {
        die('TCPDF not found.');
    }
    require_once($tcpdfPath);

    $generatedAt = date('Y-m-d H:i:s');
    $studentName = htmlspecialchars($student['name']);
    $studentClass = htmlspecialchars($student['class'] ?? '—');
    $studentStream = htmlspecialchars($student['stream'] ?? '—');

    $actionLabels = [
        'issued' => 'Issued',
        'returned' => 'Returned',
        'out_for_project' => 'Project Use',
        'back_to_school' => 'Back to School',
        'taken_home' => 'Taken Home'
    ];

    // Build action summary
    $actionSummary = [];
    if (is_array($action_breakdown)) {
        foreach ($action_breakdown as $a) {
            $actionSummary[$a['action']] = $a['count'];
        }
    }

    $html = '
    <style>
        .header { text-align:center; margin-bottom:12px; }
        .header h1 { font-family:dejavusans; font-size:18pt; color:#1f3c88; margin:0; }
        .header h2 { font-family:dejavusans; font-size:11pt; color:#4f46e5; margin:4px 0; }
        .header p { font-family:dejavusans; font-size:8pt; color:#94a3b8; margin:2px 0; }
        .section-title { font-family:dejavusans; font-size:11pt; font-weight:700; color:#1f3c88; border-bottom:1px solid #e2e8f0; padding-bottom:4px; margin:16px 0 8px 0; }
        table { width:100%; border-collapse:collapse; margin-bottom:10px; }
        th { background:#f1f5f9; font-family:dejavusans; font-size:8pt; font-weight:700; color:#64748b; text-transform:uppercase; padding:8px 6px; text-align:left; border:1px solid #e2e8f0; }
        td { font-family:dejavusans; font-size:9pt; padding:7px 6px; border:1px solid #e2e8f0; }
        .stat-box { display:inline-block; width:23%; text-align:center; padding:8px; margin:4px 1%; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; }
        .stat-box .val { font-family:dejavusans; font-size:14pt; font-weight:800; }
        .stat-box .lbl { font-family:dejavusans; font-size:7pt; color:#64748b; text-transform:uppercase; }
        .badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:7pt; font-weight:700; }
        .badge-issued { background:#ecfdf5; color:#065f46; }
        .badge-returned { background:#fef2f2; color:#991b1b; }
        .badge-taken_home { background:#fee2e2; color:#991b1b; }
        .badge-back_to_school { background:#d1fae5; color:#065f46; }
        .badge-out_for_project { background:#ede9fe; color:#5b21b6; }
        .footer { margin-top:16px; padding-top:8px; border-top:1px solid #e2e8f0; text-align:center; font-family:dejavusans; font-size:7pt; color:#94a3b8; }
    </style>
    
    <div class="header">
        <h1>St Mark\'s College Namagoma</h1>
        <h2>Individual Student Laptop Usage Report</h2>
        <p>Generated: ' . $generatedAt . '</p>
    </div>
    
    <div class="section-title">Student Information</div>
    <table>
        <tr><td width="30%"><strong>Student Name:</strong></td><td>' . $studentName . '</td><td width="30%"><strong>Student ID:</strong></td><td>' . $student['student_id'] . '</td></tr>
        <tr><td><strong>Class:</strong></td><td>' . $studentClass . '</td><td><strong>Stream:</strong></td><td>' . $studentStream . '</td></tr>
        <tr><td><strong>Parent/Guardian:</strong></td><td>' . htmlspecialchars($student['parent_name'] ?? '—') . '</td><td><strong>Contact:</strong></td><td>' . htmlspecialchars($student['parent_contact'] ?? '—') . '</td></tr>
    </table>
    
    <div class="section-title">Usage Summary</div>
    <div style="text-align:center; margin:10px 0;">
        <div class="stat-box"><div class="val" style="color:#4f46e5;">' . $total_sessions . '</div><div class="lbl">Total Sessions</div></div>
        <div class="stat-box"><div class="val" style="color:#059669;">' . number_format($total_hours, 1) . 'h</div><div class="lbl">Total Hours</div></div>
        <div class="stat-box"><div class="val" style="color:#d97706;">' . $unique_laptops . '</div><div class="lbl">Laptops Used</div></div>
        <div class="stat-box"><div class="val" style="color:#7c3aed;">' . count($history) . '</div><div class="lbl">Activities</div></div>
    </div>
    
    <div class="section-title">Action Breakdown</div>
    <table>
        <tr><th>Action</th><th>Count</th></tr>
        <tr><td>Issued</td><td align="center">' . ($actionSummary['issued'] ?? 0) . '</td></tr>
        <tr><td>Returned</td><td align="center">' . ($actionSummary['returned'] ?? 0) . '</td></tr>
        <tr><td>Taken Home</td><td align="center">' . ($actionSummary['taken_home'] ?? 0) . '</td></tr>
        <tr><td>Back to School</td><td align="center">' . ($actionSummary['back_to_school'] ?? 0) . '</td></tr>
        <tr><td>Project Use</td><td align="center">' . ($actionSummary['out_for_project'] ?? 0) . '</td></tr>
    </table>
    
    <div class="section-title">Assigned Laptops</div>
    <table>
        <tr><th>Laptop #</th><th>Model</th><th>Serial</th><th>Status</th><th>Sessions</th><th>Hours</th><th>Last Used</th></tr>';
    
    if (is_array($laptops) && count($laptops) > 0) {
        foreach ($laptops as $l) {
            $statusColor = strtolower($l['status'] ?? '') === 'issued' ? '#059669' : (strtolower($l['status'] ?? '') === 'returned' ? '#dc2626' : '#000');
            $html .= '<tr>
                <td>' . htmlspecialchars($l['laptop_number'] ?? '—') . '</td>
                <td>' . htmlspecialchars($l['model'] ?? '—') . '</td>
                <td style="font-size:7pt;">' . htmlspecialchars($l['serial_number'] ?? '—') . '</td>
                <td style="color:' . $statusColor . ';">' . htmlspecialchars($l['status'] ?? '—') . '</td>
                <td align="center">' . ($l['usage_count'] ?? 0) . '</td>
                <td align="center">' . number_format($l['usage_hours'] ?? 0, 1) . 'h</td>
                <td style="font-size:7pt;">' . ($l['last_used_date'] ?? '—') . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="7" align="center">No laptops assigned.</td></tr>';
    }
    
    $html .= '</table>
    
    <div class="section-title">Recent Usage History (Last 50)</div>
    <table>
        <tr><th>Date</th><th>Time</th><th>Laptop</th><th>Action</th><th>Duration</th><th>Staff</th></tr>';
    
    if (is_array($history) && count($history) > 0) {
        foreach ($history as $h) {
            $action = $h['action'] ?? '';
            $label = $actionLabels[$action] ?? $action;
            $badgeClass = 'badge-' . $action;
            $duration = $h['duration_minutes'] 
                ? ($h['duration_minutes'] >= 60 
                    ? floor($h['duration_minutes'] / 60) . 'h ' . ($h['duration_minutes'] % 60) . 'm'
                    : $h['duration_minutes'] . 'm')
                : '—';
            
            $html .= '<tr>
                <td>' . ($h['usage_date'] ?? '—') . '</td>
                <td>' . ($h['usage_time'] ?? '—') . '</td>
                <td>' . htmlspecialchars($h['laptop_number'] ?? '—') . '</td>
                <td><span class="badge ' . $badgeClass . '">' . $label . '</span></td>
                <td align="center">' . $duration . '</td>
                <td>' . htmlspecialchars($h['staff_name'] ?? '—') . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="6" align="center">No usage history recorded yet.</td></tr>';
    }
    
    $html .= '</table>
    
    <div class="footer">
        <p>St Mark\'s College Namagoma — Laptop Tracking System</p>
        <p>This is an auto-generated report. For official records, contact the school administration.</p>
        <p>Generated: ' . $generatedAt . '</p>
    </div>';

    $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('St Mark\'s College Laptop Tracking');
    $pdf->SetTitle("Student Laptop Usage Report - $studentName");
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(TRUE, 12);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    $pdfdoc = $pdf->Output('', 'S');
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    $filename = 'student_report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $studentName) . '_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfdoc));
    echo $pdfdoc;
    exit();
}
?>

<style>
.sr-container { max-width: 1000px; margin: 0 auto; }

.sr-header-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 28px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 20px;
}

.sr-student-info h3 {
    font-size: 22px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 4px 0;
}
.sr-student-info .sr-meta {
    color: #64748b;
    font-size: 14px;
}
.sr-student-info .sr-meta span {
    margin-right: 16px;
}
.sr-student-info .sr-meta i {
    color: #4f46e5;
    margin-right: 4px;
}

.sr-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.sr-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
    font-family: inherit;
    white-space: nowrap;
}
.sr-btn-pdf {
    background: #fff;
    color: #dc2626;
    border: 1.5px solid #fecaca;
}
.sr-btn-pdf:hover { background: #fef2f2; border-color: #f87171; transform: translateY(-1px); }
.sr-btn-back {
    background: #fff;
    color: #64748b;
    border: 1.5px solid #e2e8f0;
}
.sr-btn-back:hover { background: #f8fafc; }

.sr-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}

.sr-stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sr-stat-card .sr-stat-val {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.02em;
}
.sr-stat-card .sr-stat-lbl {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.04em;
    margin-top: 4px;
}
.sr-primary { color: #4f46e5; }
.sr-success { color: #059669; }
.sr-warning { color: #d97706; }
.sr-purple { color: #7c3aed; }

.sr-section-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    overflow: hidden;
    margin-bottom: 24px;
}
.sr-section-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #fafbfc;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 14px;
    color: #1e293b;
}
.sr-section-header i { color: #4f46e5; }
.sr-table-wrap { overflow-x: auto; padding: 0; }
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.sr-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.sr-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.sr-table tbody tr:hover td { background: #fafbfc; }
.sr-table tbody tr:last-child td { border-bottom: none; }

.sr-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.sr-badge-issued { background: #ecfdf5; color: #065f46; }
.sr-badge-returned { background: #fef2f2; color: #991b1b; }
.sr-badge-taken_home { background: #fee2e2; color: #991b1b; }
.sr-badge-back_to_school { background: #d1fae5; color: #065f46; }
.sr-badge-out_for_project { background: #ede9fe; color: #5b21b6; }

/* Action breakdown chart */
.sr-chart-bars {
    padding: 16px 20px;
}
.sr-chart-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}
.sr-chart-label {
    width: 120px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-align: right;
}
.sr-chart-bar-wrap {
    flex: 1;
    height: 22px;
    background: #f1f5f9;
    border-radius: 6px;
    overflow: hidden;
}
.sr-chart-bar {
    height: 100%;
    border-radius: 6px;
    transition: width 0.3s;
}
.sr-chart-count {
    width: 40px;
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
}
.bar-issued { background: #10b981; }
.bar-returned { background: #ef4444; }
.bar-taken_home { background: #f59e0b; }
.bar-back_to_school { background: #06b6d4; }
.bar-out_for_project { background: #8b5cf6; }

@media (max-width: 768px) {
    .sr-stats-row { grid-template-columns: repeat(2, 1fr); }
    .sr-header-card { padding: 18px; }
    .sr-chart-label { width: 80px; font-size: 11px; }
}
</style>

<div class="page-header">
    <div>
        <h2 class="title">Student Laptop Usage Report</h2>
        <p class="subtitle">Detailed usage history and analytics for individual student</p>
    </div>
</div>

<div class="sr-container">
    
    <!-- Student Header Card -->
    <div class="sr-header-card">
        <div class="sr-student-info">
            <h3>👤 <?= htmlspecialchars($student['name']) ?></h3>
            <div class="sr-meta">
                <span><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($student['class'] ?? '—') ?> <?= htmlspecialchars($student['stream'] ?? '') ?></span>
                <span><i class="fas fa-id-card"></i> ID: <?= $student['student_id'] ?></span>
            </div>
            <?php if (!empty($student['parent_name'])): ?>
            <div class="sr-meta" style="margin-top:6px;">
                <span><i class="fas fa-user-tie"></i> Parent: <?= htmlspecialchars($student['parent_name']) ?></span>
                <?php if (!empty($student['parent_contact'])): ?>
                <span><i class="fas fa-phone"></i> <?= htmlspecialchars($student['parent_contact']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="sr-actions">
            <a href="?student_id=<?= $student_id ?>&action=download_pdf" class="sr-btn sr-btn-pdf">
                <i class="fa-solid fa-file-pdf"></i> Download PDF Report
            </a>
            <a href="reports.php" class="sr-btn sr-btn-back">
                <i class="fas fa-arrow-left"></i> Back to Reports
            </a>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="sr-stats-row">
        <div class="sr-stat-card">
            <div class="sr-stat-val sr-primary"><?= $total_sessions ?></div>
            <div class="sr-stat-lbl">Total Sessions</div>
        </div>
        <div class="sr-stat-card">
            <div class="sr-stat-val sr-success"><?= number_format($total_hours, 1) ?>h</div>
            <div class="sr-stat-lbl">Total Usage Hours</div>
        </div>
        <div class="sr-stat-card">
            <div class="sr-stat-val sr-warning"><?= $unique_laptops ?></div>
            <div class="sr-stat-lbl">Laptops Used</div>
        </div>
        <div class="sr-stat-card">
            <div class="sr-stat-val sr-purple"><?= count($history) ?></div>
            <div class="sr-stat-lbl">Recorded Activities</div>
        </div>
    </div>
    
    <!-- Action Breakdown -->
    <div class="sr-section-card">
        <div class="sr-section-header">
            <i class="fas fa-chart-bar"></i> Action Breakdown
        </div>
        <div class="sr-chart-bars">
            <?php 
            $actionData = [
                'issued' => ['label' => 'Issued', 'color' => 'bar-issued'],
                'returned' => ['label' => 'Returned', 'color' => 'bar-returned'],
                'taken_home' => ['label' => 'Taken Home', 'color' => 'bar-taken_home'],
                'back_to_school' => ['label' => 'Back to School', 'color' => 'bar-back_to_school'],
                'out_for_project' => ['label' => 'Project Use', 'color' => 'bar-out_for_project'],
            ];
            
            // FIXED: Handle empty action_breakdown safely
            $countValues = array_column($action_breakdown, 'count');
            $maxCount = !empty($countValues) ? max($countValues) : 1;
            
            $counts = [];
            foreach ($action_breakdown as $a) { $counts[$a['action']] = $a['count']; }
            
            foreach ($actionData as $key => $ad):
                $count = $counts[$key] ?? 0;
                $pct = $maxCount > 0 ? round(($count / $maxCount) * 100) : 0;
            ?>
            <div class="sr-chart-row">
                <div class="sr-chart-label"><?= $ad['label'] ?></div>
                <div class="sr-chart-bar-wrap">
                    <div class="sr-chart-bar <?= $ad['color'] ?>" style="width:<?= max($pct, 3) ?>%;"></div>
                </div>
                <div class="sr-chart-count"><?= $count ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Assigned Laptops Table -->
    <div class="sr-section-card">
        <div class="sr-section-header">
            <i class="fas fa-laptop"></i> Assigned Laptops
        </div>
        <div class="sr-table-wrap">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th>Laptop #</th>
                        <th>Model</th>
                        <th>Serial</th>
                        <th>OS</th>
                        <th>Status</th>
                        <th>Sessions</th>
                        <th>Hours</th>
                        <th>Last Used</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($laptops)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px;">No laptops assigned to this student.</td></tr>
                    <?php else: ?>
                    <?php foreach ($laptops as $l): 
                        $statusLower = strtolower(trim($l['status'] ?? ''));
                        $statusBadge = $statusLower === 'issued' ? 'sr-badge-issued' : ($statusLower === 'returned' ? 'sr-badge-returned' : '');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($l['laptop_number'] ?? '—') ?></strong></td>
                        <td><?= htmlspecialchars($l['model'] ?? '—') ?></td>
                        <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($l['serial_number'] ?? '—') ?></code></td>
                        <td><?= htmlspecialchars($l['os'] ?? '—') ?></td>
                        <td><span class="sr-badge <?= $statusBadge ?>"><?= htmlspecialchars($l['status'] ?? '—') ?></span></td>
                        <td><strong><?= $l['usage_count'] ?></strong></td>
                        <td><?= number_format($l['usage_hours'], 1) ?>h</td>
                        <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($l['last_used_date']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Recent Usage History -->
    <div class="sr-section-card">
        <div class="sr-section-header">
            <i class="fas fa-history"></i> Recent Usage History (Last 50)
        </div>
        <div class="sr-table-wrap">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Laptop</th>
                        <th>Action</th>
                        <th>Duration</th>
                        <th>Staff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">No usage history recorded yet.</td></tr>
                    <?php else: ?>
                    <?php 
                    $actionLabels = [
                        'issued' => 'Issued',
                        'returned' => 'Returned',
                        'out_for_project' => 'Project Use',
                        'back_to_school' => 'Back to School',
                        'taken_home' => 'Taken Home'
                    ];
                    foreach ($history as $h): 
                        $action = $h['action'] ?? '';
                        $label = $actionLabels[$action] ?? $action;
                        $badgeClass = 'sr-badge-' . $action;
                        $duration = $h['duration_minutes'] 
                            ? ($h['duration_minutes'] >= 60 
                                ? floor($h['duration_minutes'] / 60) . 'h ' . ($h['duration_minutes'] % 60) . 'm'
                                : $h['duration_minutes'] . 'm')
                            : '—';
                    ?>
                    <tr>
                        <td><?= $h['usage_date'] ?? '—' ?></td>
                        <td style="color:#64748b;"><?= $h['usage_time'] ?? '—' ?></td>
                        <td><strong><?= htmlspecialchars($h['laptop_number'] ?? '—') ?></strong></td>
                        <td><span class="sr-badge <?= $badgeClass ?>"><?= $label ?></span></td>
                        <td><?= $duration ?></td>
                        <td style="color:#64748b;"><?= htmlspecialchars($h['staff_name'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
</div>

<?php include('../includes/footer.php'); ?>