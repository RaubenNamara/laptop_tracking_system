<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

$_lts_response_sent = false;

register_shutdown_function(function () {
    global $_lts_response_sent;
    if ($_lts_response_sent) return;

    $err = error_get_last();
    while (ob_get_level()) ob_end_clean();

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

function json_out(array $data, int $status = 200): void {
    global $_lts_response_sent;
    $_lts_response_sent = true;
    while (ob_get_level()) ob_end_clean(); // clear ALL levels (incl. zlib)
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

session_start();
include('../config/config.php');

// NOTE: migrate.php is NOT included here — run it from regular pages only.
// This keeps the AJAX endpoint lean and avoids stray output from includes.

if (!isset($_SESSION['user_id'])) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['laptop_id']) || !isset($input['status'])) {
    json_out(['success' => false, 'message' => 'Invalid request: missing laptop_id or status'], 400);
}

$laptop_id  = intval($input['laptop_id']);
$raw_status = (string)$input['status'];
$due_date   = (isset($input['due_date']) && $input['due_date'] !== '') ? $input['due_date'] : null;

function normalise_status(string $s): string {
    $s = trim(strtolower($s));
    $s = str_replace([' ', '-', '/'], '_', $s);
    return preg_replace('/[^a-z0-9_]/', '', $s);
}

$allowed   = ['issued', 'returned', 'out_for_project', 'back_to_school', 'taken_home'];
$canonical = normalise_status($raw_status);
if (!in_array($canonical, $allowed, true)) {
    json_out(['success' => false, 'message' => 'Invalid status: ' . htmlspecialchars($raw_status)], 400);
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    json_out(['success' => false, 'message' => 'Database connection failed'], 500);
}

$sets  = ['status = ?'];
$types = 's';
$vals  = [$canonical];

if (in_array($canonical, ['issued', 'out_for_project'], true)) {
    if ($due_date !== null) {
        $dt = str_replace('T', ' ', $due_date);
        if (strlen($dt) === 16) $dt .= ':00';
        $sets[]  = 'due_date = ?';
        $types  .= 's';
        $vals[]  = $dt;
    }
    $sets[] = 'issued_at = NOW()';
} elseif (in_array($canonical, ['returned', 'back_to_school'], true)) {
    $sets[] = 'due_date = NULL';
    $sets[] = 'returned_at = NOW()';
} elseif ($canonical === 'taken_home') {
    $sets[] = 'issued_at = NOW()';
}

$sql    = 'UPDATE laptops SET ' . implode(', ', $sets) . ' WHERE laptop_id = ?';
$types .= 'i';
$vals[] = $laptop_id;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    json_out(['success' => false, 'message' => 'Prepare failed: ' . $conn->error], 500);
}
if (!$stmt->bind_param($types, ...$vals)) {
    $stmt->close();
    json_out(['success' => false, 'message' => 'Bind failed'], 500);
}
if ($stmt->execute()) {
    $stmt->close();
    json_out(['success' => true, 'status' => $canonical]);
} else {
    $err = $stmt->error;
    $stmt->close();
    json_out(['success' => false, 'message' => 'Update failed: ' . $err], 500);
}
