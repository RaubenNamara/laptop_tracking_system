<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "cybermonitor";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error
      . "<br><br>Check <code>config/config.php</code> and make sure the database exists. "
      . "If you're using XAMPP, open phpMyAdmin and import <code>config/database.sql</code>.");
}

$conn->set_charset("utf8mb4");
