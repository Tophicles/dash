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
<title>Setup - MultiDash</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/auth.css">
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
    <p class="subtitle">Let's set up your MultiDash</p>
    
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