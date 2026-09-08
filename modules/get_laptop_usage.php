<?php
// modules/get_laptop_usage.php — AJAX endpoint to fetch laptop usage history
// Returns JSON with current student's usage data for a specific laptop

ob_start();
session_start();
include('../config/config.php');

// ── Helper: send clean JSON ───────────────────────────────────────────────────
function json_out($data, $status = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Get serial or laptop number from query string ──────────────────────────────
$serial = isset($_GET['serial']) ? trim($_GET['serial']) : '';
if (empty($serial)) {
    json_out(['success' => false, 'message' => 'Missing serial number'], 400);
}

// ── Lookup laptop by serial_number or laptop_number ───────────────────────────
$stmt = $conn->prepare("
    SELECT laptop_id, serial_number, laptop_number, student_id
    FROM laptops
    WHERE serial_number = ? OR laptop_number = ?
    LIMIT 1
");
if (!$stmt) {
    json_out(['success' => false, 'message' => 'Database error'], 500);
}
$stmt->bind_param('ss', $serial, $serial);
$stmt->execute();
$laptop_result = $stmt->get_result();
$laptop = $laptop_result->fetch_assoc();
$stmt->close();

if (!$laptop) {
    json_out(['success' => false, 'message' => 'Laptop not found', 'current_student_history' => []]);
}

$laptop_id = intval($laptop['laptop_id']);
$student_id = intval($laptop['student_id'] ?? 0);

// ── Query usage history for this laptop ────────────────────────────────────────
$history_query = "
    SELECT 
        lul.usage_date,
        lul.usage_time,
        lul.action,
        lul.duration_minutes,
        lul.condition_notes,
        COALESCE(u.username, 'N/A') AS staff_name
    FROM laptop_usage_log lul
    LEFT JOIN users u ON lul.staff_id = u.user_id
    WHERE lul.laptop_id = ?
    ORDER BY lul.usage_date DESC, lul.usage_time DESC
    LIMIT 50
";

$stmt2 = $conn->prepare($history_query);
if (!$stmt2) {
    json_out(['success' => false, 'message' => 'Query failed'], 500);
}
$stmt2->bind_param('i', $laptop_id);
$stmt2->execute();
$result = $stmt2->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = [
        'usage_date'       => $row['usage_date'] ?? '—',
        'usage_time'       => $row['usage_time'] ?? '—',
        'action'           => $row['action'] ?? 'unknown',
        'duration_minutes' => intval($row['duration_minutes'] ?? 0),
        'condition_notes'  => $row['condition_notes'] ?? null,
        'staff_name'       => $row['staff_name'] ?? '—',
    ];
}
$stmt2->close();

json_out([
    'success'                  => true,
    'laptop_id'                => $laptop_id,
    'laptop_number'            => $laptop['laptop_number'],
    'serial_number'            => $laptop['serial_number'],
    'student_id'               => $student_id,
    'current_student_history'  => $history,
]);
?>
