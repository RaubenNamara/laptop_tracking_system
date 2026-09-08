<?php
// pages/issue_laptop.php — quick issue endpoint (POST). Real management UI lives in laptops.php / scan.php.
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

require_once "../config/config.php";
require_once "../includes/migrate.php";
lts_run_migrations($conn);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $laptop_id  = intval($_POST['laptop_id']  ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    $staff_name = trim($_POST['staff_name']   ?? ($_SESSION['username'] ?? 'system'));

    if ($laptop_id > 0 && $student_id > 0) {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE laptops SET status='issued', student_id=?, issued_at=? WHERE laptop_id=?");
        $stmt->bind_param("isi", $student_id, $now, $laptop_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO logs (laptop_id, student_id, action, staff_name, timestamp) VALUES (?, ?, 'issued', ?, ?)");
        $stmt->bind_param("iiss", $laptop_id, $student_id, $staff_name, $now);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Laptop issued successfully.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Missing laptop or student.'];
    }
}
header("Location: laptops.php");
exit;
