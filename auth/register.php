<?php
session_start();
include("../config/config.php");

if ($_SESSION['role'] != 'admin') {
    die("Access denied. Only admin can register users.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];

    $sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $password, $role);
    
    if ($stmt->execute()) {
        $success = "User registered successfully!";
    } else {
        $error = "Error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register User</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="register-box">
        <h2>Register New User</h2>
        <?php if(isset($success)) echo "<p style='color:green;'>$success</p>"; ?>
        <?php if(isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required><br><br>
            <input type="password" name="password" placeholder="Password" required><br><br>
            <select name="role">
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select><br><br>
            <button type="submit">Register</button>
        </form>
    </div>
</body>
</html>
