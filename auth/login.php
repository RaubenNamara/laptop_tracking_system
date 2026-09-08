<?php
session_start();
include("../config/config.php");

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $sql = "SELECT * FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'] ?? 'admin';
            header("Location: ../index.php");
            exit();
        } else {
            $error = "Wrong password. Please try again.";
        }
    } else {
        $error = "We couldn't find an account with that username.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#312e81">
<title>Sign in · St. Mark's Laptop Tracking</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/theme.css">
<script src="../assets/js/theme.js"></script>
<style>
    body {
        min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
        background:
            radial-gradient(1200px 600px at 10% -10%, rgba(20,184,166,.18), transparent 60%),
            radial-gradient(900px 500px at 110% 110%, rgba(79,70,229,.22), transparent 60%),
            var(--bg);
        padding: 24px;
    }
    .auth-wrap { width: 100%; max-width: 980px; display: grid; grid-template-columns: 1.1fr 1fr; gap: 0;
        background: var(--bg-elev); border: 1px solid var(--border); border-radius: 22px; overflow: hidden; box-shadow: var(--shadow-lg); }
    .auth-side {
        background: linear-gradient(155deg, #1e1b4b 0%, #312e81 45%, #0f766e 100%);
        color: #fff;
        padding: 44px 38px;
        display: flex; flex-direction: column; justify-content: space-between;
        position: relative; overflow: hidden;
    }
    .auth-side::after {
        content: ""; position: absolute; right: -80px; bottom: -80px;
        width: 320px; height: 320px; border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.18), transparent 60%);
    }
    .auth-side .logo {
        width: 56px; height: 56px; border-radius: 16px;
        background: rgba(255,255,255,.95); color: #312e81;
        display: inline-flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 24px; box-shadow: 0 12px 24px rgba(0,0,0,.25);
    }
    .auth-side h1 { font-size: 30px; font-weight: 800; line-height: 1.15; margin: 22px 0 14px; letter-spacing: -.02em; }
    .auth-side p  { color: rgba(255,255,255,.85); font-size: 15px; line-height: 1.55; max-width: 36ch; }
    .auth-side .footer-note { font-size: 12px; color: rgba(255,255,255,.65); position: relative; z-index: 2; }

    .auth-form { padding: 50px 44px; display: flex; flex-direction: column; justify-content: center; }
    .auth-form h2 { font-size: 24px; margin: 0 0 6px; }
    .auth-form .lead { color: var(--text-muted); margin: 0 0 26px; font-size: 14px; }
    .auth-form label { font-size: 13px; color: var(--text); font-weight: 600; margin: 14px 0 6px; display: block; }
    .auth-form .input-wrap { position: relative; }
    .auth-form .input-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 14px; }
    .auth-form .input-wrap input { padding-left: 40px; height: 46px; }
    .auth-form .submit { margin-top: 22px; height: 48px; font-size: 15px; }
    .auth-form .error  { background: rgba(239,68,68,.08); color: var(--danger); border: 1px solid rgba(239,68,68,.25); padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-top: 8px; }
    .auth-form .meta-row { display: flex; justify-content: space-between; align-items: center; margin-top: 22px; font-size: 13px; color: var(--text-muted); }
    .auth-form .theme-toggle { background: var(--bg-soft); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; cursor: pointer; }

    @media (max-width: 820px) {
        .auth-wrap { grid-template-columns: 1fr; }
        .auth-side { padding: 36px 30px; }
        .auth-form { padding: 32px 24px; }
    }
</style>
</head>
<body>

<div class="auth-wrap">
    <div class="auth-side">
        <div>
            <div class="logo">S</div>
            <h1>Track every laptop. Trust every issue.</h1>
            <p>Modern laptop check-in/out for St. Mark's College — QR scan, parent SMS alerts, overdue tracking, and bulk import. Built for the lab, the classroom, and the parking lot.</p>
        </div>
        <div class="footer-note">© <?php echo date('Y'); ?> St. Mark's College — Laptop Tracking System</div>
    </div>

    <form method="POST" class="auth-form" autocomplete="on">
        <h2>Welcome back</h2>
        <p class="lead">Sign in to manage laptops, issues, and reports.</p>

        <?php if ($error): ?>
            <div class="error"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <label for="username">Username</label>
        <div class="input-wrap">
            <i class="fas fa-user"></i>
            <input id="username" type="text" name="username" placeholder="e.g. admin" required autofocus>
        </div>

        <label for="password">Password</label>
        <div class="input-wrap">
            <i class="fas fa-lock"></i>
            <input id="password" type="password" name="password" placeholder="••••••••" required>
        </div>

        <button class="btn btn-primary submit" type="submit">
            <i class="fas fa-arrow-right-to-bracket"></i> Sign in
        </button>

        <div class="meta-row">
            <span>Need help? Contact the lab administrator.</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" title="Toggle dark mode">
                <i id="themeToggleIcon" class="fas fa-moon"></i>
            </button>
        </div>
    </form>
</div>

</body>
</html>
