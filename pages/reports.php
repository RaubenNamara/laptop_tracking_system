<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);

// ---------------- Fetch helper data ---------------- //
$studentsArr = [];
$resStudents = $conn->query("SELECT student_id, name FROM students ORDER BY name ASC");
if ($resStudents) {
    while ($r = $resStudents->fetch_assoc()) {
        $studentsArr[] = $r;
    }
}

$classes = $conn->query("SELECT DISTINCT class FROM students ORDER BY class ASC");
$streams = $conn->query("SELECT DISTINCT stream FROM students ORDER BY stream ASC");

// ---------------- Load results from session (after PRG) ---------------- //
$resultRows = [];
if (isset($_SESSION['report_rows'])) {
    $resultRows = $_SESSION['report_rows'];
    unset($_SESSION['report_rows']);
}

function set_flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

// ---------------- Handle POST ---------------- //
$student_id = '';
$class = '';
$stream = '';
$mode = '';

$whereClauses = [];
$params = [];
$types = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['generate_report']) || isset($_POST['generate_pdf']) || isset($_POST['download_qr_zip']))) {

    $student_id = $_POST['student_id'] ?? '';
    $student_name = $_POST['student_name'] ?? '';
    $class = $_POST['class'] ?? '';
    $stream = $_POST['stream'] ?? '';
    $mode = $_POST['mode'] ?? '';

    if (!$student_id && !empty($student_name)) {
        $stmtFind = $conn->prepare("SELECT student_id FROM students WHERE name = ? LIMIT 1");
        if ($stmtFind) {
            $stmtFind->bind_param("s", $student_name);
            $stmtFind->execute();
            $resFind = $stmtFind->get_result();
            if ($rowFind = $resFind->fetch_assoc()) {
                $student_id = $rowFind['student_id'];
            }
            $stmtFind->close();
        }
    }

    if ($student_id && $student_id !== "all") {
        $whereClauses[] = "s.student_id = ?";
        $params[] = $student_id;
        $types .= 'i';
    }
    if ($class) {
        $whereClauses[] = "s.class = ?";
        $params[] = $class;
        $types .= 's';
    }
    if ($stream) {
        $whereClauses[] = "s.stream = ?";
        $params[] = $stream;
        $types .= 's';
    }
    if ($mode) {
        $whereClauses[] = "l.model LIKE ?";
        $params[] = "%$mode%";
        $types .= 's';
    }

    $whereSQL = $whereClauses ? "WHERE " . implode(" AND ", $whereClauses) : "";

    $usageTableExists = $conn->query("SHOW TABLES LIKE 'laptop_usage_log'");
    $usageTableExists = $usageTableExists && $usageTableExists->num_rows > 0;

    if ($usageTableExists) {
        $usageJoinSQL = "LEFT JOIN (
                SELECT 
                    laptop_id,
                    COUNT(*) AS total_sessions,
                    ROUND(SUM(COALESCE(duration_minutes, 0)) / 60, 1) AS total_hours,
                    MAX(usage_date) AS last_used,
                    MIN(usage_date) AS first_used,
                    ROUND(AVG(COALESCE(duration_minutes, 0)), 0) AS avg_duration_min
                FROM laptop_usage_log
                GROUP BY laptop_id
            ) usage_stats ON l.laptop_id = usage_stats.laptop_id";
    } else {
        $usageJoinSQL = "LEFT JOIN (
                SELECT laptop_id, 0 AS total_sessions, 0 AS total_hours, '—' AS last_used, '—' AS first_used, 0 AS avg_duration_min
                FROM laptops
            ) usage_stats ON l.laptop_id = usage_stats.laptop_id";
    }

    // ─── SQL: Include usage stats ─────────────────────────────────────
    $sql = "SELECT 
                s.student_id,
                s.name, 
                s.class, 
                s.stream, 
                l.laptop_id,
                l.laptop_number, 
                l.model, 
                l.serial_number, 
                l.os, 
                l.status, 
                l.qr_code_path,
                COALESCE(usage_stats.total_sessions, 0) AS usage_count,
                COALESCE(usage_stats.total_hours, 0) AS usage_hours,
                COALESCE(usage_stats.last_used, '—') AS last_used_date,
                COALESCE(usage_stats.first_used, '—') AS first_used_date,
                COALESCE(usage_stats.avg_duration_min, 0) AS avg_duration_min
            FROM students s 
            LEFT JOIN laptops l ON s.student_id = l.student_id
            $usageJoinSQL
            $whereSQL 
            ORDER BY s.name ASC";

    // --------------- Generate PDF (with usage data) --------------- //
    if (isset($_POST['generate_pdf'])) {
        $tcpdfPath = __DIR__ . '/../TCPDF/tcpdf.php';
        if (!file_exists($tcpdfPath)) {
            $tcpdfPath = $_SERVER['DOCUMENT_ROOT'] . '/laptop_tracking_system/TCPDF/tcpdf.php';
        }
        if (!file_exists($tcpdfPath)) {
            die('TCPDF not found at expected path.');
        }
        require_once($tcpdfPath);

        $stmtPdf = $conn->prepare($sql);
        if ($params) $stmtPdf->bind_param($types, ...$params);
        $stmtPdf->execute();
        $resPdf = $stmtPdf->get_result();
        $rows = $resPdf->fetch_all(MYSQLI_ASSOC);
        $stmtPdf->close();

        $generatedAt = date('Y-m-d H:i:s');
        $totalLaptops = count($rows);
        $totalSessions = array_sum(array_column($rows, 'usage_count'));
        $totalHours = array_sum(array_column($rows, 'usage_hours'));

        $html = '
        <div style="text-align:center; margin-bottom:10px;">
            <span style="display:block; font-family:dejavusans; font-size:18pt; font-weight:700; color:#1f3c88;">
                St Mark\'s College Namagoma
            </span>
            <span style="display:block; font-family:dejavusans; font-size:12pt; font-weight:600; color:#1f3c88; margin-top:4px;">
                Student Laptop Report — with Usage Analytics
            </span>
            <span style="display:block; font-family:dejavusans; font-size:9pt; color:#555; margin-top:6px;">
                Generated: ' . $generatedAt . '
            </span>
        </div>
        
        <!-- Summary Box -->
        <table border="1" cellpadding="4" cellspacing="0" width="100%" style="margin-bottom:12px; background:#f8fafc;">
            <tr>
                <td width="25%" align="center" style="font-family:dejavusans; font-size:9pt;">
                    <strong style="font-size:14pt; color:#4f46e5;">' . $totalLaptops . '</strong><br>
                    <span style="color:#64748b;">Total Laptops</span>
                </td>
                <td width="25%" align="center" style="font-family:dejavusans; font-size:9pt;">
                    <strong style="font-size:14pt; color:#059669;">' . $totalSessions . '</strong><br>
                    <span style="color:#64748b;">Total Sessions</span>
                </td>
                <td width="25%" align="center" style="font-family:dejavusans; font-size:9pt;">
                    <strong style="font-size:14pt; color:#d97706;">' . number_format($totalHours, 1) . 'h</strong><br>
                    <span style="color:#64748b;">Total Usage Hours</span>
                </td>
                <td width="25%" align="center" style="font-family:dejavusans; font-size:9pt;">
                    <strong style="font-size:14pt; color:#7c3aed;">' . round($totalSessions / max($totalLaptops, 1), 1) . '</strong><br>
                    <span style="color:#64748b;">Avg Sessions/Laptop</span>
                </td>
            </tr>
        </table>
        
        <hr style="border:0; height:2px; background:#1f3c88; margin:8px 0 12px 0;" />
        
        <table border="1" cellpadding="5" cellspacing="0" width="100%">
        <thead><tr style="background-color:#eef2ff;">
            <th style="font-family:dejavusans;"><strong>#</strong></th>
            <th style="font-family:dejavusans;"><strong>Student</strong></th>
            <th style="font-family:dejavusans;"><strong>Class</strong></th>
            <th style="font-family:dejavusans;"><strong>Stream</strong></th>
            <th style="font-family:dejavusans;"><strong>Laptop #</strong></th>
            <th style="font-family:dejavusans;"><strong>Model</strong></th>
            <th style="font-family:dejavusans;"><strong>Serial</strong></th>
            <th style="font-family:dejavusans;"><strong>OS</strong></th>
            <th style="font-family:dejavusans;"><strong>Status</strong></th>
            <th style="font-family:dejavusans;"><strong>Sessions</strong></th>
            <th style="font-family:dejavusans;"><strong>Hours</strong></th>
            <th style="font-family:dejavusans;"><strong>Last Used</strong></th>
        </tr></thead><tbody>';

        if (count($rows) > 0) {
            foreach ($rows as $idx => $r) {
                $statusRaw = $r['status'] ?? '';
                $statusText = htmlspecialchars($statusRaw);
                $statusLower = strtolower(trim($statusRaw));
                $statusColor = $statusLower === 'returned' ? '#e74c3c' : ($statusLower === 'issued' ? '#27ae60' : '#000');
                $usageColor = ($r['usage_count'] > 20) ? '#059669' : (($r['usage_count'] > 5) ? '#d97706' : '#64748b');
                
                $html .= '<tr>
                    <td style="font-family:dejavusans;">' . ($idx + 1) . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['name']) . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['class']) . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['stream']) . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['laptop_number'] ?? '—') . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['model'] ?? '—') . '</td>
                    <td style="font-family:dejavusans; font-size:8pt;">' . htmlspecialchars($r['serial_number'] ?? '—') . '</td>
                    <td style="font-family:dejavusans;">' . htmlspecialchars($r['os'] ?? '—') . '</td>
                    <td style="font-family:dejavusans;color:' . $statusColor . ';">' . $statusText . '</td>
                    <td style="font-family:dejavusans;color:' . $usageColor . ';font-weight:bold;" align="center">' . $r['usage_count'] . '</td>
                    <td style="font-family:dejavusans;" align="center">' . number_format($r['usage_hours'], 1) . 'h</td>
                    <td style="font-family:dejavusans; font-size:8pt;">' . $r['last_used_date'] . '</td>
                </tr>';
            }
        } else {
            $html .= '<tr><td colspan="12" align="center" style="font-family:dejavusans;">No results found.</td></tr>';
        }
        $html .= '</tbody></table>
        
        <div style="margin-top:16px; padding-top:8px; border-top:1px solid #e2e8f0; text-align:center; font-family:dejavusans; font-size:8pt; color:#94a3b8;">
            Generated by St Mark\'s Laptop Tracking System · Usage data from laptop_usage_log
        </div>';

        $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Laptop Tracking System');
        $pdf->SetTitle("St Mark's College Namagoma Student Laptop Report");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdfdoc = $pdf->Output('', 'S');

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="student_laptop_report_' . date('Ymd_His') . '.pdf"');
        header('Content-Length: ' . strlen($pdfdoc));
        echo $pdfdoc;
        exit();
    }

    // --------------- Download QR ZIP --------------- //
    if (isset($_POST['download_qr_zip'])) {
        $stmtZip = $conn->prepare($sql);
        if ($params) $stmtZip->bind_param($types, ...$params);
        $stmtZip->execute();
        $resZip = $stmtZip->get_result();
        $rows = $resZip->fetch_all(MYSQLI_ASSOC);
        $stmtZip->close();

        $filesToAdd = [];
        $projectRoot = realpath(__DIR__ . '/..');
        foreach ($rows as $r) {
            if (empty($r['qr_code_path'])) continue;
            $candidate = realpath(__DIR__ . '/../' . ltrim($r['qr_code_path'], '/\\'));
            if (!$candidate || strpos($candidate, $projectRoot) !== 0) continue;
            $filesToAdd[] = [
                'path' => $candidate,
                'name' => (empty($r['laptop_number']) ? ($r['serial_number'] ?? 'qr') : $r['laptop_number']) . '_' . basename($candidate)
            ];
        }

        if (empty($filesToAdd)) {
            set_flash('warning', 'No QR files found for the selected filters.');
            header("Location: reports.php");
            exit();
        }

        $tmpDir = sys_get_temp_dir();
        $zipFile = tempnam($tmpDir, 'qrs_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            set_flash('danger', 'Failed to create ZIP archive.');
            header("Location: reports.php");
            exit();
        }
        foreach ($filesToAdd as $f) $zip->addFile($f['path'], $f['name']);
        $zip->close();

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="qrcodes_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        @unlink($zipFile);
        exit();
    }

    // --------------- On-page display (PRG) --------------- //
    if (isset($_POST['generate_report'])) {
        $stmt = $conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $_SESSION['report_rows'] = $rows;
        header("Location: reports.php");
        exit();
    }
}
?>
<?php $page_title = "Reports"; include('../includes/header.php'); ?>

<style>
/* ===== Reports Page Modern Styles ===== */
:root {
    --rp-primary: #4f46e5;
    --rp-primary-light: #eef2ff;
    --rp-surface: #ffffff;
    --rp-border: #e2e8f0;
    --rp-text: #1e293b;
    --rp-text-soft: #64748b;
    --rp-radius: 14px;
    --rp-radius-sm: 10px;
    --rp-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --rp-shadow-md: 0 4px 12px rgba(0,0,0,0.06), 0 2px 4px rgba(0,0,0,0.04);
    --rp-transition: 0.2s ease;
    --usage-high: #059669;
    --usage-mid: #d97706;
    --usage-low: #94a3b8;
}

/* Filter card */
.rp-filter-card {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: var(--rp-radius);
    padding: 24px 28px;
    box-shadow: var(--rp-shadow);
    margin-bottom: 28px;
    transition: box-shadow var(--rp-transition);
}
.rp-filter-card:hover { box-shadow: var(--rp-shadow-md); }

.rp-filter-title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--rp-text-soft);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rp-filter-title i { color: var(--rp-primary); font-size: 14px; }

.rp-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
}

.rp-search-group {
    display: flex;
    gap: 10px;
    grid-column: 1 / -1;
}
.rp-search-group input { flex: 2; }
.rp-search-group select { flex: 1; }

.rp-form-control {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--rp-border);
    border-radius: var(--rp-radius-sm);
    font-size: 14px;
    color: var(--rp-text);
    background: #fafbfc;
    transition: all var(--rp-transition);
    outline: none;
    font-family: inherit;
}
.rp-form-control:focus {
    border-color: var(--rp-primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.08);
    background: #fff;
}
.rp-form-control::placeholder { color: #a0aec0; }

.rp-form-select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%2364748b'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
}

/* Action bar */
.rp-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid var(--rp-border);
}

/* Buttons */
.rp-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: var(--rp-radius-sm);
    font-size: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all var(--rp-transition);
    font-family: inherit;
    text-decoration: none;
    white-space: nowrap;
}
.rp-btn-primary { background: var(--rp-primary); color: #fff; }
.rp-btn-primary:hover { background: #4338ca; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); }
.rp-btn-pdf { background: #fff; color: #dc2626; border: 1.5px solid #fecaca; }
.rp-btn-pdf:hover { background: #fef2f2; border-color: #f87171; transform: translateY(-1px); }
.rp-btn-zip { background: #fff; color: #0f766e; border: 1.5px solid #99f6e4; }
.rp-btn-zip:hover { background: #f0fdfa; border-color: #2dd4bf; transform: translateY(-1px); }
.rp-btn-print {
    background: #fff;
    color: var(--rp-text-soft);
    border: 1.5px solid var(--rp-border);
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: all var(--rp-transition);
    white-space: nowrap;
}
.rp-btn-print:hover { background: var(--rp-primary-light); border-color: var(--rp-primary); color: var(--rp-primary); }

/* Student link */
.rp-student-link {
    color: var(--rp-primary);
    font-weight: 700;
    text-decoration: none;
    transition: all 0.15s ease;
}
.rp-student-link:hover {
    text-decoration: underline;
    color: #4338ca;
}
.rp-student-link i {
    font-size: 10px;
    margin-left: 4px;
    opacity: 0;
    transition: opacity 0.15s ease;
}
.rp-student-link:hover i { opacity: 1; }

/* Summary Stats Bar */
.rp-summary-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.rp-summary-card {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: var(--rp-radius);
    padding: 16px;
    text-align: center;
    box-shadow: var(--rp-shadow);
}
.rp-summary-card .rps-value { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; }
.rp-summary-card .rps-label { font-size: 11px; color: var(--rp-text-soft); text-transform: uppercase; font-weight: 600; letter-spacing: 0.04em; margin-top: 4px; }
.rps-primary { color: var(--rp-primary); }
.rps-success { color: var(--usage-high); }
.rps-warning { color: var(--usage-mid); }
.rps-purple { color: #7c3aed; }

@media (max-width: 768px) { .rp-summary-stats { grid-template-columns: repeat(2, 1fr); } }

/* Table card */
.rp-table-card {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: var(--rp-radius);
    box-shadow: var(--rp-shadow);
    overflow: hidden;
}
.rp-table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    border-bottom: 1px solid var(--rp-border);
    background: #fafbfc;
}
.rp-table-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--rp-text); display: flex; align-items: center; gap: 8px; }
.rp-table-header h3 i { color: var(--rp-primary); }
.rp-badge-count { background: var(--rp-primary-light); color: var(--rp-primary); padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }

.rp-table-wrap { overflow-x: auto; }
.rp-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.rp-table thead th {
    background: #f8fafc;
    color: var(--rp-text-soft);
    font-weight: 700;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 2px solid var(--rp-border);
    white-space: nowrap;
}
.rp-table thead th.usage-col { text-align: center; }
.rp-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: var(--rp-text); vertical-align: middle; }
.rp-table tbody td.usage-cell { text-align: center; font-weight: 700; }
.rp-table tbody tr { transition: background var(--rp-transition); }
.rp-table tbody tr:hover { background: #f8fafc; }
.rp-table tbody tr:last-child td { border-bottom: none; }

/* Usage sparkline bar */
.usage-bar-mini { display: inline-block; height: 6px; border-radius: 3px; min-width: 20px; }
.usage-high { background: var(--usage-high); }
.usage-mid { background: var(--usage-mid); }
.usage-low { background: var(--usage-low); }
.usage-none { background: #e2e8f0; }

/* Status badges */
.rp-status { display: inline-block; padding: 5px 11px; border-radius: 999px; font-size: 12px; font-weight: 600; letter-spacing: 0.01em; }
.rp-status-issued { background: #ecfdf5; color: #065f46; }
.rp-status-returned { background: #fef2f2; color: #991b1b; }
.rp-status-default { background: #f8fafc; color: var(--rp-text-soft); }

/* Mobile cards */
.rp-mobile-card {
    background: var(--rp-surface);
    border: 1px solid var(--rp-border);
    border-radius: var(--rp-radius);
    padding: 16px 18px;
    margin-bottom: 12px;
    box-shadow: var(--rp-shadow);
    transition: box-shadow var(--rp-transition);
}
.rp-mobile-card:hover { box-shadow: var(--rp-shadow-md); }
.rp-mobile-card .rp-mc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.rp-mobile-card .rp-mc-name { font-weight: 700; font-size: 15px; color: var(--rp-text); }
.rp-mobile-card .rp-mc-model { font-size: 12px; color: var(--rp-text-soft); margin-top: 2px; }
.rp-mc-details { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; font-size: 13px; }
.rp-mc-details div { display: flex; flex-direction: column; }
.rp-mc-details .rp-mc-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-soft); font-weight: 600; }
.rp-mc-details .rp-mc-value { color: var(--rp-text); font-weight: 500; }
.rp-mc-usage-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.rp-mc-usage-high { background: #ecfdf5; color: #065f46; }
.rp-mc-usage-mid  { background: #fffbeb; color: #92400e; }
.rp-mc-usage-low  { background: #f8fafc; color: #64748b; }
.rp-mc-usage-none { background: #f1f5f9; color: #94a3b8; }

/* Results summary */
.rp-results-summary { display: flex; align-items: center; gap: 8px; padding: 14px 0; font-size: 13px; color: var(--rp-text-soft); }
.rp-results-summary strong { color: var(--rp-text); }

/* Empty state */
.rp-empty { text-align: center; padding: 60px 20px; color: var(--rp-text-soft); }
.rp-empty .rp-empty-icon { width: 64px; height: 64px; border-radius: 20px; background: var(--rp-primary-light); color: var(--rp-primary); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 16px; }

@media (max-width: 768px) {
    .rp-filter-card { padding: 18px 16px; }
    .rp-search-group { flex-direction: column; }
    .rp-actions { flex-direction: column; }
    .rp-actions .rp-btn { justify-content: center; width: 100%; }
    .d-desktop { display: none; }
}
@media (min-width: 769px) { .d-mobile { display: none; } }
</style>

<div class="page-header">
    <div>
        <h2 class="title">Reports</h2>
        <p class="subtitle">Generate, export, and print student laptop reports with usage analytics</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="laptops.php" class="btn btn-outline"><i class="fas fa-laptop"></i> All Laptops</a>
        <a href="scan.php" class="btn btn-outline"><i class="fas fa-qrcode"></i> Quick Scan</a>
    </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
    <?php $f = $_SESSION['flash']; ?>
    <div class="alert alert-<?= htmlspecialchars($f['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($f['msg'] ?? '') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Filter Card -->
<div class="rp-filter-card">
    <div class="rp-filter-title"><i class="fas fa-sliders"></i> Filter Options</div>
    <form method="POST" autocomplete="off">
        <div class="rp-filter-grid">
            <div class="rp-search-group">
                <input id="student_search" list="students_datalist" name="student_name" class="rp-form-control" placeholder="🔍  Search by student name…" autocomplete="off" />
                <datalist id="students_datalist">
                    <?php foreach($studentsArr as $s): ?>
                        <option value="<?= htmlspecialchars($s['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <select name="student_id" id="student_select" class="rp-form-control rp-form-select">
                    <option value="">All Students</option>
                    <option value="all" <?= ($student_id === "all") ? 'selected' : '' ?>>— All —</option>
                    <?php foreach($studentsArr as $s): ?>
                        <option value="<?= $s['student_id'] ?>" <?= ($student_id !== '' && $student_id == $s['student_id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <select name="class" class="rp-form-control rp-form-select">
                <option value="">All Classes</option>
                <?php while($c = $classes->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($c['class']) ?>" <?= ($class == $c['class']) ? 'selected' : '' ?>><?= htmlspecialchars($c['class']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="stream" class="rp-form-control rp-form-select">
                <option value="">All Streams</option>
                <?php while($st = $streams->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($st['stream']) ?>" <?= ($stream == $st['stream']) ? 'selected' : '' ?>><?= htmlspecialchars($st['stream']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="mode" class="rp-form-control rp-form-select">
                <option value="">All Models</option>
                <?php
                $modelsRes = $conn->query("SELECT DISTINCT model FROM laptops WHERE model IS NOT NULL AND model != '' ORDER BY model ASC");
                if ($modelsRes) {
                    while ($m = $modelsRes->fetch_assoc()) {
                        $selected = ($mode == $m['model']) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($m['model']) . '" ' . $selected . '>' . htmlspecialchars($m['model']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>
        <div class="rp-actions">
            <button type="submit" name="generate_report" class="rp-btn rp-btn-primary"><i class="fas fa-magnifying-glass"></i> Generate Report</button>
            <button type="submit" name="generate_pdf" class="rp-btn rp-btn-pdf"><i class="fa-solid fa-file-pdf"></i> Download PDF</button>
            <button type="submit" name="download_qr_zip" class="rp-btn rp-btn-zip"><i class="fas fa-download"></i> Download QRs (ZIP)</button>
        </div>
    </form>
</div>

<!-- Results -->
<?php if (!empty($resultRows)): 
    $totalLaptops = count($resultRows);
    $totalSessions = array_sum(array_column($resultRows, 'usage_count'));
    $totalHours = array_sum(array_column($resultRows, 'usage_hours'));
    $avgSessions = $totalLaptops > 0 ? round($totalSessions / $totalLaptops, 1) : 0;
    $maxSessions = max(array_column($resultRows, 'usage_count')) ?: 1;
?>

<div class="rp-summary-stats">
    <div class="rp-summary-card"><div class="rps-value rps-primary"><?= $totalLaptops ?></div><div class="rps-label">Total Laptops</div></div>
    <div class="rp-summary-card"><div class="rps-value rps-success"><?= $totalSessions ?></div><div class="rps-label">Total Sessions</div></div>
    <div class="rp-summary-card"><div class="rps-value rps-warning"><?= number_format($totalHours, 1) ?>h</div><div class="rps-label">Total Usage Hours</div></div>
    <div class="rp-summary-card"><div class="rps-value rps-purple"><?= $avgSessions ?></div><div class="rps-label">Avg Sessions / Laptop</div></div>
</div>

<div class="rp-results-summary">
    <i class="fas fa-circle-check" style="color:#10b981;"></i>
    <strong><?= $totalLaptops ?></strong> result<?= $totalLaptops !== 1 ? 's' : '' ?> found
    <span style="margin-left:8px; font-size:11px; color:#94a3b8;">— Click a student name for detailed report</span>
</div>

<!-- Desktop Table -->
<div class="rp-table-card d-desktop">
    <div class="rp-table-header">
        <h3><i class="fas fa-table"></i> Report Results with Usage Analytics</h3>
        <span class="rp-badge-count"><?= $totalLaptops ?> records</span>
    </div>
    <div class="rp-table-wrap">
        <table class="rp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Stream</th>
                    <th>Laptop #</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>OS</th>
                    <th>Status</th>
                    <th class="usage-col">Sessions</th>
                    <th class="usage-col">Hours</th>
                    <th class="usage-col">Last Used</th>
                    <th>Print</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach($resultRows as $row):
                    $statusLower = strtolower(trim($row['status'] ?? ''));
                    $statusClass = ($statusLower === 'issued') ? 'rp-status-issued' : (($statusLower === 'returned') ? 'rp-status-returned' : 'rp-status-default');
                    $qrPath = isset($row['qr_code_path']) && $row['qr_code_path'] ? ('../' . $row['qr_code_path']) : '';
                    $usageCount = (int)($row['usage_count'] ?? 0);
                    $usageHours = (float)($row['usage_hours'] ?? 0);
                    if ($usageCount >= 20) $usageLevel = 'high';
                    elseif ($usageCount >= 5) $usageLevel = 'mid';
                    elseif ($usageCount > 0) $usageLevel = 'low';
                    else $usageLevel = 'none';
                    $barWidth = $maxSessions > 0 ? round(($usageCount / $maxSessions) * 100) : 0;
                    
                    $printData = [
                        'laptop_number' => $row['laptop_number'] ?? '',
                        'model' => $row['model'] ?? '',
                        'serial_number' => $row['serial_number'] ?? '',
                        'os' => $row['os'] ?? '',
                        'name' => $row['name'] ?? '',
                        'class' => $row['class'] ?? '',
                        'stream' => $row['stream'] ?? '',
                        'status' => $row['status'] ?? '',
                        'usage_count' => $usageCount,
                        'usage_hours' => $usageHours,
                        'avg_duration_min' => (int)($row['avg_duration_min'] ?? 0),
                        'last_used_date' => $row['last_used_date'] ?? '—',
                        'first_used_date' => $row['first_used_date'] ?? '—',
                    ];
                ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td>
                        <?php if (!empty($row['student_id'])): ?>
                            <a href="student_report.php?student_id=<?= $row['student_id'] ?>" class="rp-student-link">
                                <?= htmlspecialchars($row['name'] ?? '—') ?> <i class="fas fa-external-link-alt"></i>
                            </a>
                        <?php else: ?>
                            <strong><?= htmlspecialchars($row['name'] ?? '—') ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['class'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['stream'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['laptop_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['model'] ?? '—') ?></td>
                    <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($row['serial_number'] ?? '—') ?></code></td>
                    <td><?= htmlspecialchars($row['os'] ?? '—') ?></td>
                    <td><span class="rp-status <?= $statusClass ?>"><?= htmlspecialchars($row['status'] ?? '—') ?></span></td>
                    <td class="usage-cell">
                        <span style="color:<?= $usageLevel === 'high' ? 'var(--usage-high)' : ($usageLevel === 'mid' ? 'var(--usage-mid)' : 'var(--usage-low)') ?>"><?= $usageCount ?></span>
                        <div class="usage-bar-mini usage-<?= $usageLevel ?>" style="width:<?= max($barWidth, $usageCount > 0 ? 8 : 20) ?>px; margin:4px auto 0;"></div>
                    </td>
                    <td class="usage-cell"><?= number_format($usageHours, 1) ?>h</td>
                    <td style="font-size:12px;color:var(--rp-text-soft);"><?= htmlspecialchars($row['last_used_date'] ?? '—') ?></td>
                    <td>
                        <button type="button" class="rp-btn-print" onclick="printLaptopDetail('<?= htmlspecialchars($qrPath, ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($printData), ENT_QUOTES) ?>)">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Mobile Cards -->
<div class="d-mobile mt-3">
    <?php foreach($resultRows as $r): 
        $qrPath = isset($r['qr_code_path']) && $r['qr_code_path'] ? ('../' . $r['qr_code_path']) : '';
        $statusLower = strtolower(trim($r['status'] ?? ''));
        $statusClass = ($statusLower === 'issued') ? 'rp-status-issued' : (($statusLower === 'returned') ? 'rp-status-returned' : 'rp-status-default');
        $usageCount = (int)($r['usage_count'] ?? 0);
        $usageHours = (float)($r['usage_hours'] ?? 0);
        if ($usageCount >= 20) $usageBadge = 'rp-mc-usage-high';
        elseif ($usageCount >= 5) $usageBadge = 'rp-mc-usage-mid';
        elseif ($usageCount > 0) $usageBadge = 'rp-mc-usage-low';
        else $usageBadge = 'rp-mc-usage-none';
        
        $printData = [
            'laptop_number' => $r['laptop_number'] ?? '',
            'model' => $r['model'] ?? '',
            'serial_number' => $r['serial_number'] ?? '',
            'os' => $r['os'] ?? '',
            'name' => $r['name'] ?? '',
            'class' => $r['class'] ?? '',
            'stream' => $r['stream'] ?? '',
            'status' => $r['status'] ?? '',
            'usage_count' => $usageCount,
            'usage_hours' => $usageHours,
            'avg_duration_min' => (int)($r['avg_duration_min'] ?? 0),
            'last_used_date' => $r['last_used_date'] ?? '—',
            'first_used_date' => $r['first_used_date'] ?? '—',
        ];
    ?>
    <div class="rp-mobile-card">
        <div class="rp-mc-header">
            <div>
                <?php if (!empty($r['student_id'])): ?>
                    <a href="student_report.php?student_id=<?= $r['student_id'] ?>" class="rp-student-link" style="font-size:15px;">
                        <?= htmlspecialchars($r['name'] ?? '—') ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                <?php else: ?>
                    <div class="rp-mc-name"><?= htmlspecialchars($r['name'] ?? '—') ?></div>
                <?php endif; ?>
                <div class="rp-mc-model"><?= htmlspecialchars($r['model'] ?? '—') ?></div>
            </div>
            <span class="rp-status <?= $statusClass ?>"><?= htmlspecialchars($r['status'] ?? '—') ?></span>
        </div>
        <div class="rp-mc-details">
            <div><span class="rp-mc-label">Class</span><span class="rp-mc-value"><?= htmlspecialchars($r['class'] ?? '—') ?></span></div>
            <div><span class="rp-mc-label">Stream</span><span class="rp-mc-value"><?= htmlspecialchars($r['stream'] ?? '—') ?></span></div>
            <div><span class="rp-mc-label">Laptop #</span><span class="rp-mc-value"><?= htmlspecialchars($r['laptop_number'] ?? '—') ?></span></div>
            <div><span class="rp-mc-label">Serial</span><span class="rp-mc-value"><code><?= htmlspecialchars($r['serial_number'] ?? '—') ?></code></span></div>
            <div><span class="rp-mc-label">OS</span><span class="rp-mc-value"><?= htmlspecialchars($r['os'] ?? '—') ?></span></div>
            <div><span class="rp-mc-label">Usage</span><span class="rp-mc-value"><span class="rp-mc-usage-badge <?= $usageBadge ?>"><?= $usageCount ?> sessions · <?= number_format($usageHours, 1) ?>h</span></span></div>
            <div><span class="rp-mc-label">Last Used</span><span class="rp-mc-value"><?= htmlspecialchars($r['last_used_date'] ?? '—') ?></span></div>
            <div>
                <span class="rp-mc-label">Print</span>
                <span class="rp-mc-value">
                    <button type="button" class="rp-btn-print" onclick="printLaptopDetail('<?= htmlspecialchars($qrPath, ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($printData), ENT_QUOTES) ?>)"><i class="fas fa-print"></i> Print</button>
                </span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])): ?>
<div class="rp-empty">
    <div class="rp-empty-icon"><i class="fas fa-search"></i></div>
    <h4 style="color:var(--rp-text); margin-bottom:6px;">No results found</h4>
    <p>Try adjusting your filters or selecting different criteria.</p>
</div>
<?php else: ?>
<div class="rp-empty">
    <div class="rp-empty-icon"><i class="fas fa-file-lines"></i></div>
    <h4 style="color:var(--rp-text); margin-bottom:6px;">Generate a Report</h4>
    <p>Use the filters above and click <strong>Generate Report</strong> to view results with usage analytics.</p>
    <p style="font-size:12px; color:#94a3b8; margin-top:4px;">Click a student's name in the results to view their individual detailed report.</p>
</div>
<?php endif; ?>

</div>

<?php include('../includes/footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const students = <?= json_encode($studentsArr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
    const searchInput = document.getElementById('student_search');
    const select = document.getElementById('student_select');

    if (searchInput && select) {
        const normalize = s => (s || '').toString().trim().toLowerCase();

        function setSelectToStudent(student) {
            if (student) { select.value = student.student_id; searchInput.value = student.name; }
            else { select.value = ''; }
        }

        searchInput.addEventListener('input', function () {
            const v = normalize(this.value);
            if (!v) { setSelectToStudent(null); return; }
            const matches = students.filter(s => normalize(s.name).includes(v));
            const exact = students.find(s => normalize(s.name) === v);
            if (exact) { setSelectToStudent(exact); return; }
            if (matches.length === 1) { setSelectToStudent(matches[0]); return; }
            setSelectToStudent(null);
        });

        searchInput.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                const v = normalize(this.value);
                if (!v) return;
                let match = students.find(s => normalize(s.name) === v);
                if (!match) {
                    const partials = students.filter(s => normalize(s.name).includes(v));
                    if (partials.length > 0) match = partials[0];
                }
                if (match) {
                    setSelectToStudent(match);
                    const form = this.closest('form');
                    if (form) {
                        const genBtn = form.querySelector('button[name="generate_report"]');
                        if (genBtn) genBtn.click();
                        else form.submit();
                    }
                }
            }
        });

        select.addEventListener('change', function () {
            const id = this.value;
            const found = students.find(s => String(s.student_id) === String(id));
            if (found) searchInput.value = found.name;
            else if (id === "all" || id === "") searchInput.value = '';
        });

        (function(){
            const sel = select.value;
            if (sel) {
                const found = students.find(s => String(s.student_id) === String(sel));
                if (found) searchInput.value = found.name;
            }
        })();
    }
});

// ─── PRINT: Full Student Laptop Detail Document ───────────────────────
function printLaptopDetail(qrPath, rowData) {
    const cleanPath = qrPath && String(qrPath).trim() !== '' ? String(qrPath).trim() : '';
    rowData = rowData || {};

    const printWindow = window.open('', '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    
    if (!printWindow) {
        alert('🚫 Popup blocked!\n\nPlease allow popups for this site to print.\n\n👉 Look for a popup-blocked icon in your browser address bar and click "Always allow".');
        return;
    }

    const statusClass = (rowData.status || '').toLowerCase().replace(/\s+/g, '_');
    const usageCount = parseInt(rowData.usage_count) || 0;
    const usageHours = parseFloat(rowData.usage_hours) || 0;
    const maxSess = Math.max(usageCount, 1);
    const barPercent = Math.round((usageCount / maxSess) * 100);
    const barColor = usageCount >= 20 ? '#059669' : (usageCount >= 5 ? '#d97706' : '#94a3b8');

    const html = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laptop Detail — St Mark's College</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            color: #1e293b;
            font-size: 13px;
            line-height: 1.5;
            padding: 0;
        }
        .document {
            max-width: 210mm;
            margin: 0 auto;
            padding: 15mm 12mm;
            position: relative;
        }
        .doc-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 3px solid #1f3c88;
            position: relative;
        }
        .doc-header .school-name { font-size: 20pt; font-weight: 800; color: #1f3c88; letter-spacing: -0.01em; }
        .doc-header .doc-title { font-size: 13pt; font-weight: 600; color: #4f46e5; margin-top: 4px; }
        .doc-header .doc-date { font-size: 9pt; color: #94a3b8; margin-top: 6px; }
        
        .qr-corner { position: absolute; top: 0; right: 0; text-align: center; z-index: 10; }
        .qr-corner img { width: 90px; height: 90px; border: 2px solid #e2e8f0; border-radius: 10px; padding: 4px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .qr-corner .qr-label { font-size: 7pt; color: #94a3b8; margin-top: 2px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .qr-corner .qr-na { font-size: 7pt; color: #cbd5e1; margin-top: 2px; }
        
        .section { margin-bottom: 18px; }
        .section-title { font-size: 11pt; font-weight: 700; color: #1f3c88; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; }
        .section-title .icon { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; }
        .icon-laptop { background: #eef2ff; }
        .icon-student { background: #fef3c7; }
        .icon-usage { background: #ecfdf5; }
        .icon-history { background: #fce7f3; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
        .info-grid.col-3 { grid-template-columns: 1fr 1fr 1fr; }
        .info-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9; }
        .info-item .label { font-size: 10pt; color: #64748b; font-weight: 600; white-space: nowrap; }
        .info-item .value { font-weight: 700; color: #1e293b; text-align: right; word-break: break-word; }
        .info-item .value.code { font-family: 'SF Mono', 'Consolas', 'Monaco', monospace; font-size: 10pt; background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 10pt; font-weight: 700; letter-spacing: 0.01em; }
        .status-issued { background: #ecfdf5; color: #065f46; }
        .status-returned { background: #fef2f2; color: #991b1b; }
        .status-back_to_school { background: #d1fae5; color: #065f46; }
        .status-taken_home { background: #fee2e2; color: #991b1b; }
        .status-out_for_project { background: #ede9fe; color: #5b21b6; }
        .status- { background: #f8fafc; color: #64748b; }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .stat-box { text-align: center; padding: 12px 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
        .stat-box .stat-value { font-size: 18pt; font-weight: 800; letter-spacing: -0.02em; }
        .stat-box .stat-label { font-size: 8pt; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-top: 2px; }
        .stat-primary { color: #4f46e5; }
        .stat-success { color: #059669; }
        .stat-warning { color: #d97706; }
        .stat-purple { color: #7c3aed; }
        
        .mini-table { width: 100%; border-collapse: collapse; font-size: 10pt; }
        .mini-table thead th { background: #f8fafc; padding: 9px 10px; text-align: left; font-size: 8pt; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e2e8f0; }
        .mini-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        
        .usage-bar-wrap { display: flex; align-items: center; gap: 8px; }
        .usage-bar { height: 8px; border-radius: 4px; flex: 1; min-width: 40px; background: #e2e8f0; overflow: hidden; }
        .usage-bar-fill { height: 100%; border-radius: 4px; }
        
        .doc-footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 8pt; color: #94a3b8; }
        
        .no-print { text-align: center; margin-top: 20px; }
        .no-print button { padding: 12px 32px; background: #4f46e5; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
        .no-print button:hover { background: #4338ca; }
        
        @media print {
            body { background: #fff; }
            .document { padding: 10mm 12mm; max-width: none; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="document">
        
        <div class="qr-corner">
            ${cleanPath ? '<img src="' + cleanPath + '" alt="QR Code" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';"><div class="qr-na" style="display:none;">QR not available</div><div class="qr-label">Scan for details</div>' : '<div class="qr-na">No QR Code</div>'}
        </div>
        
        <div class="doc-header">
            <div class="school-name">St Mark's College Namagoma</div>
            <div class="doc-title">📋 Laptop Detail Report</div>
            <div class="doc-date">Generated: ${new Date().toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})} at ${new Date().toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit'})}</div>
        </div>
        
        <div class="section">
            <div class="section-title"><span class="icon icon-laptop">💻</span> Laptop Information</div>
            <div class="info-grid col-3">
                <div class="info-item"><span class="label">Laptop #</span><span class="value">${rowData.laptop_number || '—'}</span></div>
                <div class="info-item"><span class="label">Model</span><span class="value">${rowData.model || '—'}</span></div>
                <div class="info-item"><span class="label">Serial Number</span><span class="value code">${rowData.serial_number || '—'}</span></div>
                <div class="info-item"><span class="label">Operating System</span><span class="value">${rowData.os || '—'}</span></div>
                <div class="info-item"><span class="label">First Used</span><span class="value">${rowData.first_used_date || '—'}</span></div>
                <div class="info-item"><span class="label">Last Used</span><span class="value">${rowData.last_used_date || '—'}</span></div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title"><span class="icon icon-student">👤</span> Student Information</div>
            <div class="info-grid">
                <div class="info-item"><span class="label">Student Name</span><span class="value">${rowData.name || 'No student assigned'}</span></div>
                <div class="info-item"><span class="label">Status</span><span class="value"><span class="status-badge status-${statusClass}">${rowData.status || '—'}</span></span></div>
                <div class="info-item"><span class="label">Class</span><span class="value">${rowData.class || '—'}</span></div>
                <div class="info-item"><span class="label">Stream</span><span class="value">${rowData.stream || '—'}</span></div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title"><span class="icon icon-usage">📊</span> Usage Statistics</div>
            <div class="stats-row">
                <div class="stat-box"><div class="stat-value stat-primary">${usageCount}</div><div class="stat-label">Total Sessions</div></div>
                <div class="stat-box"><div class="stat-value stat-success">${usageHours.toFixed(1)}h</div><div class="stat-label">Total Hours</div></div>
                <div class="stat-box"><div class="stat-value stat-warning">${rowData.avg_duration_min || 0}m</div><div class="stat-label">Avg Duration</div></div>
                <div class="stat-box"><div class="stat-value stat-purple">${rowData.last_used_date || '—'}</div><div class="stat-label">Last Used</div></div>
            </div>
            <div style="margin-top:12px; padding:10px 14px; background:#f8fafc; border-radius:8px; border:1px solid #f1f5f9;">
                <div class="usage-bar-wrap">
                    <span style="font-size:10pt;font-weight:600;color:#64748b;">Usage Intensity:</span>
                    <div class="usage-bar"><div class="usage-bar-fill" style="width:${Math.max(barPercent, 5)}%; background:${barColor};"></div></div>
                    <span style="font-size:10pt;font-weight:700;color:${barColor};">${usageCount >= 20 ? 'Heavy' : (usageCount >= 5 ? 'Moderate' : (usageCount > 0 ? 'Light' : 'None'))}</span>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title"><span class="icon icon-history">📝</span> Recent Usage History</div>
            <div id="historyTableContent">
                <p style="color:#94a3b8; font-style:italic; text-align:center; padding:20px;">Loading usage history…</p>
            </div>
        </div>
        
        <div class="doc-footer">
            <p>St Mark's College Namagoma — Laptop Tracking System</p>
            <p>This document was auto-generated. For official records, contact the school administration.</p>
        </div>
        
        <div class="no-print">
            <button onclick="window.print(); setTimeout(function(){ window.close(); }, 500);">🖨️  Print This Document</button>
            <p style="font-size:11px; color:#94a3b8; margin-top:8px;">Or press <strong>Ctrl+P</strong> / <strong>⌘+P</strong></p>
        </div>
        
    </div>
    
    <script>
        (function() {
            var container = document.getElementById('historyTableContent');
            var serial = '${(rowData.serial_number || '').replace(/'/g, "\\'")}';
            var laptopNumber = '${(rowData.laptop_number || '').replace(/'/g, "\\'")}';
            
            if (!serial && !laptopNumber) {
                container.innerHTML = '<p style="color:#94a3b8; text-align:center; padding:20px;">No usage data available.</p>';
                return;
            }
            
            fetch('../modules/get_laptop_usage.php?serial=' + encodeURIComponent(serial || laptopNumber))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.current_student_history || !data.current_student_history.length) {
                        container.innerHTML = '<p style="color:#94a3b8; text-align:center; padding:20px;">No usage history recorded for this laptop.</p>';
                        return;
                    }
                    
                    var actionLabels = {
                        'issued': '📤 Issued',
                        'returned': '↩️ Returned',
                        'taken_home': '🏠 Taken Home',
                        'back_to_school': '🏫 Back to School',
                        'out_for_project': '📋 Project Use'
                    };
                    
                    var tableHTML = '<table class="mini-table"><thead><tr><th>Date</th><th>Time</th><th>Action</th><th>Duration</th><th>Staff</th></tr></thead><tbody>';
                    
                    data.current_student_history.forEach(function(h) {
                        var duration = h.duration_minutes 
                            ? (h.duration_minutes >= 60 
                                ? Math.floor(h.duration_minutes / 60) + 'h ' + (h.duration_minutes % 60) + 'm'
                                : h.duration_minutes + 'm')
                            : '—';
                            
                        tableHTML += '<tr>' +
                            '<td>' + (h.usage_date || '—') + '</td>' +
                            '<td>' + (h.usage_time || '—') + '</td>' +
                            '<td>' + (actionLabels[h.action] || h.action) + '</td>' +
                            '<td>' + duration + '</td>' +
                            '<td>' + (h.staff_name || '—') + '</td>' +
                            '</tr>';
                    });
                    
                    tableHTML += '</tbody></table>';
                    tableHTML += '<p style="font-size:9pt; color:#94a3b8; margin-top:8px; text-align:right;">Showing last ' + data.current_student_history.length + ' entries</p>';
                    
                    container.innerHTML = tableHTML;
                })
                .catch(function() {
                    container.innerHTML = '<p style="color:#94a3b8; text-align:center; padding:20px;">Could not load usage history.</p>';
                });
        })();
    <\/script>
</body>
</html>`;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    if (printWindow.focus) printWindow.focus();
}
</script>