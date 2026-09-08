<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');

$status_filter = 'issued';
$page_title    = 'Issued Laptops';
$page_subtitle = 'Laptops that are currently issued out to students.';
include('../includes/status_page.php');
