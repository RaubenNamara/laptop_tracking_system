<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logging Out...</title>
<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- FontAwesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #1abc9c, #3498db);
    height: 100vh;
    margin: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    color: #fff;
    text-align: center;
}
.container {
    background: rgba(255,255,255,0.1);
    padding: 40px 30px;
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    max-width: 400px;
    width: 90%;
}
h1 { font-size: 28px; margin-bottom: 20px; }
p { font-size: 16px; margin-bottom: 25px; }
i { font-size: 50px; color: #f1c40f; margin-bottom: 15px; display:block; }
.spinner-border {
    width: 3rem;
    height: 3rem;
    border-width: 0.3rem;
}

/* Mobile adjustments */
@media (max-width: 480px) {
    .container {
        padding: 25px 20px;
        max-width: 90%;
    }
    h1 { font-size: 22px; }
    p { font-size: 14px; }
    i { font-size: 40px; }
    .spinner-border {
        width: 2rem;
        height: 2rem;
        border-width: 0.25rem;
    }
}
</style>
</head>
<body>

<div class="container">
    <i class="fas fa-sign-out-alt"></i>
    <h1>Logging Out...</h1>
    <p>You are being logged out. Redirecting to login page.</p>
    <div class="spinner-border text-light" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>
</div>

<script>
// Redirect to login after 2 seconds
setTimeout(function(){
    window.location.href = 'login.php';
}, 2000);
</script>

</body>
</html>
