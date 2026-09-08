<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');

$status_filter = 'out_for_project';
$page_title    = 'Out for Project';
$page_subtitle = 'Laptops checked out for project work.';
include('../includes/status_page.php');
