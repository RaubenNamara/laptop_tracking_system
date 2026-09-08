<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');

$status_filter = 'taken_home';
$page_title    = 'Taken Home';
$page_subtitle = 'Laptops currently at students\' homes.';
include('../includes/status_page.php');
