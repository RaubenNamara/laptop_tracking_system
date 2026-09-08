<?php
// return_laptop.php — AJAX / POST endpoint to mark a laptop as returned
ob_start();

session_start();
if (!isset($_SESSION['user_id'])) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "../config/config.php"; // fixed: was "../config.php" (wrong path)
require_once "../includes/migrate.php";
lts_run_migrations($conn);

function json_out_ret($data, $status = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_out_ret(['success' => false, 'message' => 'POST required'], 405);
}

$laptop_id  = intval($_POST['laptop_id']  ?? 0);
$student_id = intval($_POST['student_id'] ?? 0);
$staff_name = trim($_POST['staff_name']   ?? ($_SESSION['username'] ?? 'system'));

if ($laptop_id <= 0) {
    json_out_ret(['success' => false, 'message' => 'Missing laptop_id'], 400);
}

// 1. Update laptop status
$upd = $conn->prepare("UPDATE laptops SET status = 'returned', returned_at = NOW() WHERE laptop_id = ?");
if (!$upd) {
    json_out_ret(['success' => false, 'message' => 'Prepare failed: ' . $conn->error], 500);
}
$upd->bind_param("i", $laptop_id);
$upd->execute();
$upd->close();

// 2. Insert log entry
$stmt = $conn->prepare("INSERT INTO logs (laptop_id, student_id, action, staff_name, timestamp) VALUES (?, ?, 'returned', ?, NOW())");
if ($stmt) {
    $sid = $student_id > 0 ? $student_id : null;
    $stmt->bind_param("iis", $laptop_id, $sid, $staff_name);
    $stmt->execute();
    $stmt->close();
}

// If called from a redirect-based form, support legacy redirect
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    json_out_ret(['success' => true, 'message' => 'Laptop returned successfully.']);
}

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Laptop returned successfully!'];
header("Location: laptops.php");
exit;
