<?php
session_start();

// If logged in, go to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: pages/dashboard.php");
    exit();
}

// If not logged in, go to login
header("Location: auth/login.php");
exit();
