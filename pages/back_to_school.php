<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');

$status_filter = 'back_to_school';
$page_title    = 'Back to School';
$page_subtitle = 'Laptops that have been returned to the school stockroom.';
include('../includes/status_page.php');
