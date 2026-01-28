<?php
require_once 'auth.php';

// Check if setup is needed
if (!file_exists('users.json')) {
    header('Location: setup.php');
    exit;
}

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

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
<title>Login - Media Server Dashboard</title>
<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}
body {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background: #1a1a1a;
  color: #e0e0e0;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
}
.login-container {
  background: #2a2a2a;
  border: 1px solid #444;
  border-radius: 8px;
  padding: 40px;
  width: 100%;
  max-width: 400px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
h1 {
  text-align: center;
  margin-bottom: 30px;
  color: #fff;
  font-size: 1.8rem;
}
.form-group {
  margin-bottom: 20px;
}
.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #e0e0e0;
}
.form-group input {
  width: 100%;
  padding: 12px;
  background: #1a1a1a;
  border: 1px solid #444;
  border-radius: 4px;
  color: #e0e0e0;
  font-size: 1rem;
}
.form-group input:focus {
  outline: none;
  border-color: #4caf50;
}
.btn {
  width: 100%;
  padding: 12px;
  background: #4caf50;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 1rem;
  font-weight: 600;
  transition: background 0.2s;
}
.btn:hover {
  background: #45a049;
}
.error {
  background: rgba(244, 67, 54, 0.2);
  border: 1px solid #f44336;
  color: #ff5252;
  padding: 12px;
  border-radius: 4px;
  margin-bottom: 20px;
  text-align: center;
}
.info {
  margin-top: 20px;
  padding: 15px;
  background: rgba(33, 150, 243, 0.1);
  border: 1px solid rgba(33, 150, 243, 0.3);
  border-radius: 4px;
  font-size: 0.85rem;
  color: #aaa;
}
.info strong {
  color: #e0e0e0;
  display: block;
  margin-bottom: 8px;
}
.info div {
  margin: 4px 0;
}
</style>
</head>
<body>
<div class="login-container">
  <h1>Media Dashboard</h1>
  
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
</body>
</html>