<?php
require_once 'auth.php';

// Check if setup is needed
if (!file_exists(DB_DIR . 'users.json')) {
    header('Location: setup.php');
    exit;
}

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if (isset($_GET['timeout'])) {
    $error = 'Session timed out due to inactivity';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - MultiDash</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/auth.css?v=<?= time() ?>">
</head>
<body>
<div class="auth-wrapper">
  <div class="login-container">
    <img src="assets/img/logo_splash.png" alt="MultiDash Logo" class="login-logo">
    <h1>MultiDash</h1>

    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button type="submit" class="btn">Login</button>
    </form>
  </div>

  <div class="donation-container">
    <div class="donation-header">
      <i class="fa-solid fa-heart" style="color: #e91e63; font-size: 2rem; margin-bottom: 15px;"></i>
      <h2>Support Project</h2>
    </div>
    <p class="donation-plea">
      Hello! This dashboard is a labor of love, maintained to keep your media experience smooth and organized.
      If you find it useful, consider tossing a few coins in the jar to help cover server costs and fuel future updates.
      Every bit helps and is deeply appreciated!
    </p>

    <div class="donation-form">
      <label>Donation Amount (USD)</label>
      <div class="donate-input-wrapper">
          <span class="currency-symbol">$</span>
          <input type="number" id="donate-amount" value="5" min="1" step="1" class="donate-input">
      </div>
      <button class="btn paypal-btn" onclick="processDonation()">
        <i class="fa-brands fa-paypal"></i> Donate via PayPal
      </button>
    </div>
  </div>
</div>

<script>
function processDonation() {
    const amount = document.getElementById('donate-amount').value || 5;
    window.open(`https://paypal.me/tophicles/${amount}`, '_blank');
}
</script>
</body>
</html>