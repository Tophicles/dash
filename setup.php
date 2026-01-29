<?php
require_once 'logging.php';
session_start();

// Check if users.json already exists
$usersFile = 'users.json';
$setupComplete = file_exists($usersFile);

if ($setupComplete) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Create initial admin user
        $users = [
            $username => [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created' => date('Y-m-d H:i:s')
            ]
        ];
        
        // Save to users.json
        $jsonData = json_encode($users, JSON_PRETTY_PRINT);
        $result = file_put_contents($usersFile, $jsonData);
        if ($result !== false) {
            @chmod($usersFile, 0666);
            writeLog("Setup: Created users.json");
        }
        
        // Initialize servers.json if it doesn't exist
        $serversFile = 'servers.json';
        if (!file_exists($serversFile)) {
            $initialServers = [
                'refreshSeconds' => 5,
                'servers' => []
            ];
            file_put_contents($serversFile, json_encode($initialServers, JSON_PRETTY_PRINT));
            @chmod($serversFile, 0666);
        }

        // Initialize activity.json if it doesn't exist
        $activityFile = 'activity.json';
        if (!file_exists($activityFile)) {
            file_put_contents($activityFile, '{}');
            @chmod($activityFile, 0666);
        }
        
        if ($result !== false) {
            // Verify the file was created
            if (file_exists($usersFile)) {
                $success = true;
                writeLog("Setup completed successfully by user: $username", "INFO");
                // Auto-login the new admin
                $_SESSION['user'] = [
                    'username' => $username,
                    'role' => 'admin'
                ];
                // Redirect to dashboard after 2 seconds
                header('Refresh: 2; URL=index.php');
            } else {
                $error = 'File write succeeded but file does not exist. Path: ' . realpath('.') . '/' . $usersFile;
                writeLog("Setup Error: $error", "ERROR");
            }
        } else {
            $error = 'Failed to write to users.json. Directory: ' . realpath('.') . ' - Check permissions.';
            writeLog("Setup Error: $error", "ERROR");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup - Media Server Dashboard</title>
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
.setup-container {
  background: #2a2a2a;
  border: 1px solid #444;
  border-radius: 8px;
  padding: 40px;
  width: 100%;
  max-width: 500px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
h1 {
  text-align: center;
  margin-bottom: 10px;
  color: #fff;
  font-size: 2rem;
}
.subtitle {
  text-align: center;
  color: #aaa;
  margin-bottom: 30px;
  font-size: 0.95rem;
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
.form-help {
  font-size: 0.85rem;
  color: #888;
  margin-top: 4px;
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
.success {
  background: rgba(76, 175, 80, 0.2);
  border: 1px solid #4caf50;
  color: #4caf50;
  padding: 20px;
  border-radius: 4px;
  text-align: center;
  font-size: 1.1rem;
}
.success-icon {
  font-size: 3rem;
  margin-bottom: 10px;
}
.info-box {
  background: rgba(33, 150, 243, 0.1);
  border: 1px solid rgba(33, 150, 243, 0.3);
  border-radius: 4px;
  padding: 15px;
  margin-bottom: 20px;
}
.info-box h3 {
  color: #2196f3;
  margin-bottom: 10px;
  font-size: 1rem;
}
.info-box ul {
  margin-left: 20px;
  color: #aaa;
  font-size: 0.9rem;
}
.info-box li {
  margin: 5px 0;
}
</style>
</head>
<body>
<div class="setup-container">
  <?php if ($success): ?>
    <div class="success">
      <div class="success-icon" style="color: #4caf50;">&#10004;</div>
      <strong>Setup Complete!</strong>
      <p style="margin-top: 10px;">Redirecting to dashboard...</p>
    </div>
  <?php else: ?>
    <h1>Welcome!</h1>
    <p class="subtitle">Let's set up your Media Server Dashboard</p>
    
    <div class="info-box">
      <h3>First Time Setup</h3>
      <ul>
        <li>Create your administrator account</li>
        <li>You'll be able to add more users later</li>
        <li>This account will have full access</li>
      </ul>
    </div>
    
    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST">
      <div class="form-group">
        <label>Admin Username</label>
        <input type="text" name="username" required autofocus minlength="3">
        <div class="form-help">At least 3 characters</div>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required minlength="6">
        <div class="form-help">At least 6 characters</div>
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password" required minlength="6">
      </div>
      <button type="submit" class="btn">Create Admin Account</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>