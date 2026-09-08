<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');

// Fetch staff from users table
$sql = "SELECT user_id, username FROM users ORDER BY user_id ASC";
$result = $conn->query($sql);

$page_title = "Staff";
include('../includes/header.php');
include_once('../includes/sidebar.php'); // Move sidebar AFTER header and use include_once
?>

<div class="page-header">
    <h2>Staff Members</h2>
    <div style="color:var(--muted);font-size:14px;">All staff registered in the system</div>
</div>

<div class="table-wrapper">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:70px;">S/N</th>
                    <th>Username</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sn = 1;
            if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
                    $username = htmlspecialchars($row['username'] ?? '');
            ?>
                <tr>
                    <td><?= $sn++; ?></td>
                    <td><?= $username; ?></td>
                </tr>
            <?php
                endwhile;
            else:
            ?>
                <tr>
                    <td colspan="2" class="text-center" style="padding:18px 12px;">No staff found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include('../includes/footer.php'); ?>