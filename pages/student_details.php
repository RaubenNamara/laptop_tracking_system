<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');
include('../includes/sidebar.php');

$data = null;

// Handle search
if (isset($_GET['query']) && !empty(trim($_GET['query']))) {
    $search = $conn->real_escape_string(trim($_GET['query']));
    $stmt = $conn->prepare("
        SELECT l.*, s.name, s.passport, s.fingerprint_template
        FROM laptops l
        JOIN students s ON l.student_id = s.student_id
        WHERE l.laptop_number = ? OR s.name LIKE ?
        LIMIT 1
    ");
    $likeSearch = "%$search%";
    $stmt->bind_param("ss", $search, $likeSearch);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
    } else {
        $error = "No laptop/student found matching '$search'.";
    }
}
?>
<?php $page_title = "Student Details"; include('../includes/header.php'); ?>
<div class="page-header">
        <h2>Student & Laptop Details</h2>
        <div style="color:var(--muted);font-size:14px;">Search results</div>
    </div>

    <?php if(isset($error)): ?>
        <p class="error"><?= $error; ?></p>
    <?php endif; ?>

    <?php if($data): ?>
        <div class="card-wrapper">
            <!-- Student Info -->
            <div class="card">
                <h3>Student Information</h3>
                <p><strong>Name:</strong> <?= htmlspecialchars($data['name']); ?></p>
                <?php if ($data['passport']): ?>
                    <img src="../uploads/passports/<?= htmlspecialchars($data['passport']); ?>" alt="Passport">
                <?php else: ?>
                    <p>No passport uploaded</p>
                <?php endif; ?>
                <p><strong>Fingerprint:</strong> <?= htmlspecialchars($data['fingerprint_template']); ?></p>
            </div>

            <!-- Laptop Info -->
            <div class="card">
                <h3>Laptop Information</h3>
                <p><strong>Laptop No:</strong> <?= htmlspecialchars($data['laptop_number']); ?></p>
                <p><strong>Serial No:</strong> <?= htmlspecialchars($data['serial_number']); ?></p>
                <p><strong>Model:</strong> <?= htmlspecialchars($data['model']); ?></p>
                <p><strong>Processor:</strong> <?= htmlspecialchars($data['core']); ?></p>
                <p><strong>Gen:</strong> <?= htmlspecialchars($data['generation']); ?></p>
                <p><strong>RAM:</strong> <?= htmlspecialchars($data['ram']); ?></p>
                <p><strong>ROM:</strong> <?= htmlspecialchars($data['rom']); ?></p>
                <p><strong>Remarks:</strong> <?= htmlspecialchars($data['notes']); ?></p>
                <?php if ($data['qr_code_path']): ?>
                    <img src="../<?= htmlspecialchars($data['qr_code_path']); ?>" alt="QR Code">
                <?php endif; ?>
            </div>

            <!-- Laptop Images -->
            <div class="card">
                <h3>Laptop Images</h3>
                <?php if ($data['laptop_image1']): ?><img src="../uploads/laptops/<?= htmlspecialchars($data['laptop_image1']); ?>"><?php endif; ?>
                <?php if ($data['laptop_image2']): ?><img src="../uploads/laptops/<?= htmlspecialchars($data['laptop_image2']); ?>"><?php endif; ?>
                <?php if ($data['laptop_image3']): ?><img src="../uploads/laptops/<?= htmlspecialchars($data['laptop_image3']); ?>"><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php include('../includes/footer.php'); ?>
