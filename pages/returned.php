<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');

$status_filter = 'returned';
$page_title    = 'Returned Laptops';
$page_subtitle = 'Laptops that have been returned to the school.';
include('../includes/status_page.php');
