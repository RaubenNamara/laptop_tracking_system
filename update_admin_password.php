<?php
include("config/config.php"); // Make sure path is correct

// Hash the new password securely
$new_password = password_hash("admin123", PASSWORD_DEFAULT);

// Update admin password in the database
$sql = "UPDATE users SET password='$new_password' WHERE username='admin'";
if($conn->query($sql) === TRUE){
    echo "Admin password updated successfully!";
} else {
    echo "Error updating password: " . $conn->error;
}
?>
