<?php
// AJAX endpoint: write a log entry + optional SMS parent notification
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

session_start();
include('../config/config.php');

@include_once('../includes/migrate.php');
if (function_exists('lts_run_migrations')) { @lts_run_migrations($conn); }

@include_once('../includes/sms.php');

// ── Helper ───────────────────────────────────────────────────────────────────
function json_out($data, $status = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Shutdown handler: catches fatal errors and returns JSON ──────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    } elseif (ob_get_level() > 0 && ob_get_length() > 0) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unexpected server output']);
    }
});

// ── Auth check ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}

// ── Verify DB connection ─────────────────────────────────────────────────────
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    json_out(['success' => false, 'message' => 'Database connection failed'], 500);
}

$input      = json_decode(file_get_contents('php://input'), true) ?: [];
$laptop_id  = intval($input['laptop_id']  ?? 0);
$student_id = intval($input['student_id'] ?? 0);
$action_raw = trim($input['action'] ?? '');
$note       = trim($input['note']   ?? '');
$staff_name = $_SESSION['username'] ?? 'system';

$ACTION_LABELS = [
    'issued'          => 'Issued Out',
    'returned'        => 'Returned',
    'out_for_project' => 'Out for Project',
    'back_to_school'  => 'Back to School',
    'taken_home'      => 'Taken Home',
];

$key          = strtolower(str_replace([' ', '-', '/'], '_', $action_raw));
$action_label = $ACTION_LABELS[$key] ?? null;
if (!$action_label) {
    json_out(['success' => false, 'message' => 'Invalid action: ' . $action_raw], 400);
}

// ── Insert main log ──────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO logs (laptop_id, student_id, action, staff_name, timestamp) VALUES (?, ?, ?, ?, NOW())"
);
if (!$stmt) {
    json_out(['success' => false, 'message' => 'Prepare failed: ' . $conn->error], 500);
}
$sid_for_log = $student_id > 0 ? $student_id : null;
$stmt->bind_param("iiss", $laptop_id, $sid_for_log, $key, $staff_name);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    json_out(['success' => false, 'message' => $err], 500);
}
$log_id = $stmt->insert_id;
$stmt->close();

// ── Auto-notify parent via SMS (optional) ────────────────────────────────────
$sms_sent = false;
$sms_msg  = null;
$auto = function_exists('lts_get_setting') ? lts_get_setting($conn, 'auto_notify_parents', '1') : '1';
if ($auto === '1' && array_key_exists($key, $ACTION_LABELS)) {
    $info = $conn->prepare(
        "SELECT l.laptop_number, s.student_id, s.name AS student_name,
                p.contact AS parent_contact, p.name AS parent_name
         FROM laptops l
         LEFT JOIN students s ON s.student_id = l.student_id
         LEFT JOIN parents  p ON p.parent_id  = s.parent_id
         WHERE l.laptop_id = ?"
    );
    if ($info) {
        $info->bind_param('i', $laptop_id);
        $info->execute();
        $gr = $info->get_result();           // safe: check before fetch
        $r  = $gr ? $gr->fetch_assoc() : null;
        $info->close();
        if ($r && !empty($r['parent_contact']) && function_exists('lts_send_sms')) {
            $verbs = [
                'issued'          => 'has been issued a school laptop',
                'returned'        => 'has returned the school laptop',
                'out_for_project' => 'has taken the school laptop out for a project',
                'back_to_school'  => 'has brought the school laptop back to school',
                'taken_home'      => 'has taken the school laptop home',
            ];
            $student  = $r['student_name'] ?? 'Your child';
            $laptopNo = '#' . $r['laptop_number'];
            $when     = date('D d M Y, H:i');
            $msg      = "St. Mark's College: $student {$verbs[$key]} (Laptop $laptopNo) on $when. Reply if not authorised.";
            $send     = lts_send_sms($conn, $r['parent_contact'], $msg, $laptop_id, (int)($r['student_id'] ?? 0));
            $sms_sent = $send['success'];
            $sms_msg  = $send['message'];
        } else {
            $sms_msg = 'No parent contact on file.';
        }
    }
}

// ── Usage duration (for return-type actions) ──────────────────────────────────
$duration_minutes = null;
$usage_logged     = false;
$usage_log_id     = null;

if (in_array($key, ['returned', 'back_to_school'], true)) {
    $tbl = $conn->query("SHOW TABLES LIKE 'laptop_usage_log'");
    if ($tbl && $tbl->num_rows > 0) {
        $dur_stmt = $conn->prepare(
            "SELECT created_at FROM laptop_usage_log
             WHERE laptop_id = ? AND action IN ('issued','taken_home','out_for_project')
             ORDER BY created_at DESC LIMIT 1"
        );
        if ($dur_stmt) {
            $dur_stmt->bind_param("i", $laptop_id);
            $dur_stmt->execute();
            $dgr        = $dur_stmt->get_result();   // safe: check before fetch
            $last_issue = $dgr ? $dgr->fetch_assoc() : null;
            $dur_stmt->close();
            if ($last_issue) {
                $duration_minutes = round((time() - strtotime($last_issue['created_at'])) / 60);
            }
        }
    } else {
        $dur_stmt = $conn->prepare(
            "SELECT timestamp FROM logs
             WHERE laptop_id = ? AND LOWER(action) IN ('issued','taken_home','out_for_project')
             ORDER BY timestamp DESC LIMIT 1"
        );
        if ($dur_stmt) {
            $dur_stmt->bind_param("i", $laptop_id);
            $dur_stmt->execute();
            $dgr        = $dur_stmt->get_result();   // safe: check before fetch
            $last_issue = $dgr ? $dgr->fetch_assoc() : null;
            $dur_stmt->close();
            if ($last_issue) {
                $duration_minutes = round((time() - strtotime($last_issue['timestamp'])) / 60);
            }
        }
    }
}

// ── Optional: insert into laptop_usage_log if table exists ───────────────────
$tbl2 = $conn->query("SHOW TABLES LIKE 'laptop_usage_log'");
if ($tbl2 && $tbl2->num_rows > 0) {
    $usage_stmt = $conn->prepare(
        "INSERT INTO laptop_usage_log (laptop_id, student_id, action, usage_date, usage_time, duration_minutes, condition_notes, staff_id)
         VALUES (?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?)"
    );
    if ($usage_stmt) {
        $notes_for_log = $note ?: null;
        $usage_stmt->bind_param("iisisi",
            $laptop_id, $sid_for_log, $key,
            $duration_minutes, $notes_for_log, $_SESSION['user_id']
        );
        if ($usage_stmt->execute()) {
            $usage_log_id = $usage_stmt->insert_id;
            $usage_logged = true;
        }
        $usage_stmt->close();
    }
}

json_out([
    'success'          => true,
    'log_id'           => $log_id,
    'action'           => $action_label,
    'sms_sent'         => $sms_sent,
    'sms_info'         => $sms_msg,
    'usage_logged'     => $usage_logged,
    'usage_log_id'     => $usage_log_id,
    'duration_minutes' => $duration_minutes,
]);
