<?php
$current_page = basename($_SERVER['PHP_SELF']);

if (!function_exists('nav_active')) {
    function nav_active($p) {
        global $current_page;
        return $current_page === $p ? ' active' : '';
    }
}
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="brand">
        <div class="logo">S</div>
        <div class="brand-text">
            <span class="name">St. Mark's College</span>
            <span class="tag">Laptop Tracking</span>
        </div>
    </div>

    <nav class="nav-scroll">
        <div class="nav-section">Overview</div>
        <a href="dashboard.php" class="nav-link<?php echo nav_active('dashboard.php'); ?>">
            <i class="fas fa-gauge-high"></i><span class="label">Dashboard</span>
        </a>
        <a href="scan.php" class="nav-link<?php echo nav_active('scan.php'); ?>">
            <i class="fas fa-qrcode"></i><span class="label">Quick Scan</span>
        </a>
        <a href="overdue.php" class="nav-link<?php echo nav_active('overdue.php'); ?>">
            <i class="fas fa-triangle-exclamation"></i><span class="label">Overdue</span>
        </a>

        <div class="nav-section">Records</div>
        <a href="laptops.php" class="nav-link<?php echo nav_active('laptops.php'); ?>">
            <i class="fas fa-laptop"></i><span class="label">Laptops</span>
        </a>
        <a href="students.php" class="nav-link<?php echo nav_active('students.php'); ?>">
            <i class="fas fa-user-graduate"></i><span class="label">Students</span>
        </a>
        <a href="parents.php" class="nav-link<?php echo nav_active('parents.php'); ?>">
            <i class="fas fa-users"></i><span class="label">Parents</span>
        </a>
        <a href="staff.php" class="nav-link<?php echo nav_active('staff.php'); ?>">
            <i class="fas fa-user-tie"></i><span class="label">Staff</span>
        </a>

        <div class="nav-section">Cyber Labs</div>
        <a href="labs.php" class="nav-link<?php echo nav_active('labs.php'); ?>">
            <i class="fas fa-building-columns"></i><span class="label">Lab Overview</span>
        </a>
        <a href="labs.php?lab=olevel" class="nav-link<?php echo (nav_active('labs.php') && ($_GET['lab'] ?? '') === 'olevel') ? ' active' : ''; ?>">
            <i class="fas fa-o"></i><span class="label">O-Level Lab</span>
        </a>
        <a href="labs.php?lab=alevel" class="nav-link<?php echo (nav_active('labs.php') && ($_GET['lab'] ?? '') === 'alevel') ? ' active' : ''; ?>">
            <i class="fas fa-a"></i><span class="label">A-Level Lab</span>
        </a>

        <div class="nav-section">Status</div>
        <a href="issued.php" class="nav-link<?php echo nav_active('issued.php'); ?>">
            <i class="fas fa-paper-plane"></i><span class="label">Issued</span>
        </a>
        <a href="returned.php" class="nav-link<?php echo nav_active('returned.php'); ?>">
            <i class="fas fa-rotate-left"></i><span class="label">Returned</span>
        </a>
        <a href="out.php" class="nav-link<?php echo nav_active('out.php'); ?>">
            <i class="fas fa-diagram-project"></i><span class="label">Out for Project</span>
        </a>
        <a href="back_to_school.php" class="nav-link<?php echo nav_active('back_to_school.php'); ?>">
            <i class="fas fa-school"></i><span class="label">Back to School</span>
        </a>
        <a href="taken_home.php" class="nav-link<?php echo nav_active('taken_home.php'); ?>">
            <i class="fas fa-house"></i><span class="label">Taken Home</span>
        </a>

        <div class="nav-section">Tools</div>
        <a href="logs.php" class="nav-link<?php echo nav_active('logs.php'); ?>">
            <i class="fas fa-clock-rotate-left"></i><span class="label">Activity Logs</span>
        </a>
        <a href="reports.php" class="nav-link<?php echo nav_active('reports.php'); ?>">
            <i class="fas fa-file-lines"></i><span class="label">Reports</span>
        </a>
        <a href="import.php" class="nav-link<?php echo nav_active('import.php'); ?>">
            <i class="fas fa-file-csv"></i><span class="label">Bulk Import</span>
        </a>
        <a href="sms_settings.php" class="nav-link<?php echo nav_active('sms_settings.php'); ?>">
            <i class="fas fa-comment-sms"></i><span class="label">SMS Settings</span>
        </a>

        <div class="nav-section">Account</div>
        <a href="add_admin.php" class="nav-link<?php echo nav_active('add_admin.php'); ?>">
            <i class="fas fa-user-shield"></i><span class="label">Admins</span>
        </a>
        <a href="../auth/logout.php" class="nav-link">
            <i class="fas fa-arrow-right-from-bracket"></i><span class="label">Sign out</span>
        </a>
    </nav>
</aside>
