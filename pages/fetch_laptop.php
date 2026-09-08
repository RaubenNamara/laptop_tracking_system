<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

// Track whether we have already sent a JSON response so the shutdown
// handler does not wrongly overwrite it (e.g. when zlib output compression
// creates an extra buffer level that we are not aware of).
$_lts_response_sent = false;

register_shutdown_function(function () {
    global $_lts_response_sent;
    if ($_lts_response_sent) return; // already done — do nothing

    $err = error_get_last();
    while (ob_get_level()) ob_end_clean(); // clear ALL levels (incl. zlib)

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unexpected server output']);
    }
});

function lts_fetch_respond(array $data): void {
    global $_lts_response_sent;
    $_lts_response_sent = true;
    while (ob_get_level()) ob_end_clean(); // clear ALL levels
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

session_start();
include('../config/config.php');

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    lts_fetch_respond(['success' => false, 'message' => 'Database connection failed']);
}

if (!isset($_GET['serial']) || trim($_GET['serial']) === '') {
    lts_fetch_respond(['success' => false, 'message' => 'Missing serial / laptop number']);
}
$serial = trim($_GET['serial']);

$stmt = $conn->prepare("
    SELECT l.*, s.name AS student_name, s.class AS student_class, s.stream AS student_stream
    FROM laptops l
    LEFT JOIN students s ON l.student_id = s.student_id
    WHERE l.serial_number = ? OR l.laptop_number = ?
    LIMIT 1
");
if (!$stmt) {
    lts_fetch_respond(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
}
$stmt->bind_param('ss', $serial, $serial);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $stmt->close();
    lts_fetch_respond(['success' => false, 'message' => 'No laptop found for "' . htmlspecialchars($serial) . '"']);
}

$laptop = $result->fetch_assoc();
$stmt->close();

if (!function_exists('lts_canon_action')) {
    function lts_canon_action($a) {
        $a = strtolower(trim((string)$a));
        $a = str_replace([' ', '-', '/'], '_', $a);
        $a = preg_replace('/[^a-z0-9_]/', '', $a);
        $map = ['issued_out' => 'issued', 'returned_in' => 'returned',
                'out_for_project' => 'out_for_project', 'back_to_school' => 'back_to_school',
                'taken_home' => 'taken_home'];
        return $map[$a] ?? $a;
    }
}

$history = [];
$lid = (int)$laptop['laptop_id'];
$h = $conn->prepare("
    SELECT lg.action, lg.staff_name, lg.timestamp, s.name AS student_name
    FROM logs lg
    LEFT JOIN students s ON lg.student_id = s.student_id
    WHERE lg.laptop_id = ?
    ORDER BY lg.timestamp DESC
    LIMIT 25
");
if ($h) {
    $h->bind_param('i', $lid);
    $h->execute();
    $hr = $h->get_result();
    if ($hr) {
        while ($r = $hr->fetch_assoc()) {
            $r['action'] = lts_canon_action($r['action']);
            $history[] = $r;
        }
    }
    $h->close();
}

$stats = ['total' => 0, 'issued' => 0, 'returned' => 0,
          'taken_home' => 0, 'out_for_project' => 0, 'back_to_school' => 0];
$s2 = $conn->prepare("SELECT action, COUNT(*) c FROM logs WHERE laptop_id = ? GROUP BY action");
if ($s2) {
    $s2->bind_param('i', $lid);
    $s2->execute();
    $sr = $s2->get_result();
    if ($sr) {
        while ($r = $sr->fetch_assoc()) {
            $k = lts_canon_action($r['action']);
            $stats['total'] += (int)$r['c'];
            if (isset($stats[$k])) $stats[$k] += (int)$r['c'];
        }
    }
    $s2->close();
}

lts_fetch_respond(['success' => true, 'laptop' => $laptop,
                   'history' => $history, 'stats' => $stats]);
