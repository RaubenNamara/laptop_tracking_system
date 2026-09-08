<?php
// Shared layout header — emits <head>, topbar, and opens .app-shell + .app-main + .page
// Pages that include this should also include footer.php at the end.
//
// Optional: set $page_title before including this file.

if (session_status() === PHP_SESSION_NONE) session_start();

// Run migrations once per request (idempotent, fast). Ensures schema, indexes, dirs are ready.
if (isset($conn) && $conn instanceof mysqli) {
    if (!function_exists('lts_run_migrations')) {
        @include_once __DIR__ . '/migrate.php';
    }
    if (function_exists('lts_run_migrations')) { @lts_run_migrations($conn); }
}

$current_page = basename($_SERVER['PHP_SELF']);
$page_title   = $page_title ?? 'Laptop Tracking System';
$username     = $_SESSION['username'] ?? 'Guest';
$initial      = strtoupper(substr($username, 0, 1));

// Pull any flash for toast surfacing
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#312e81">
<title><?php echo htmlspecialchars($page_title); ?> · St. Mark's Laptop Tracking</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/theme.css">
<script src="../assets/js/theme.js"></script>
<script defer src="../assets/js/table-tools.js"></script>
</head>
<body>

<!-- Toast container -->
<div id="lts-toasts" class="lts-toasts" aria-live="polite" aria-atomic="true"></div>
<?php if ($flash && !empty($flash['msg'])): ?>
<script>
window.addEventListener('DOMContentLoaded', function(){
    if (window.ltsToast) ltsToast(<?= json_encode($flash['msg']) ?>, <?= json_encode($flash['type'] ?? 'info') ?>);
});
</script>
<?php endif; ?>

<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop"></div>

    <div class="app-main">
        <header class="app-topbar">
            <button class="menu-toggle" id="menuToggleBtn" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="page-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <div class="topbar-actions">
                <a href="import.php" class="btn-upload d-none d-sm-inline-flex" title="Upload Excel or CSV">
                    <i class="fas fa-file-arrow-up"></i>
                    <span class="d-none d-md-inline">Upload Excel / CSV</span>
                    <span class="d-inline d-md-none">Upload</span>
                </a>
                <a href="scan.php" class="icon-btn d-none d-md-inline-flex" title="Quick Scan">
                    <i class="fas fa-qrcode"></i>
                </a>
                <button class="icon-btn" id="themeToggleBtn" type="button" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <i id="themeToggleIcon" class="fas fa-moon"></i>
                </button>
                <a href="add_admin.php" class="icon-btn d-none d-md-inline-flex" title="Manage admins">
                    <i class="fas fa-user-shield"></i>
                </a>
                <a href="../auth/logout.php" class="icon-btn d-none d-md-inline-flex" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
                <span class="user-pill">
                    <span class="avatar"><?php echo htmlspecialchars($initial); ?></span>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($username); ?></span>
                </span>
            </div>
        </header>

        <main class="page fade-in">
