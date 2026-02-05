<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'logging.php';
require_once 'restore_helper.php';
session_start();

// Check if users.json already exists
$usersFile = DB_DIR . 'users.json';
$setupComplete = file_exists($usersFile);

if ($setupComplete) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = false;

// Handle Restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $result = performRestore($_FILES['backup_file']['tmp_name'], 'Setup (Unauthenticated)');
        if ($result['success']) {
            $success = true;
            $restoreSuccess = true;
            writeLog("Setup: System restored from backup.", "INFO");
            header('Refresh: 2; URL=index.php');
        } else {
            $error = 'Restore Failed: ' . ($result['error'] ?? 'Unknown error');
        }
    } else {
        $error = 'File upload failed';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $serversFile = DB_DIR . 'servers.json';
        if (!file_exists($serversFile)) {
            $initialServers = [
                'refreshSeconds' => 5,
                'servers' => []
            ];
            file_put_contents($serversFile, json_encode($initialServers, JSON_PRETTY_PRINT));
            @chmod($serversFile, 0666);
        }

        // Initialize activity.json if it doesn't exist
        $activityFile = DB_DIR . 'activity.json';
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
                $error = 'File write succeeded but file does not exist. Path: ' . $usersFile;
                writeLog("Setup Error: $error", "ERROR");
            }
        } else {
            $error = 'Failed to write to users.json. Directory: ' . DATA_DIR . ' - Check permissions.';
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/auth.css?v=<?= time() ?>">
</head>
<body>
<div class="setup-container">
  <?php if ($success): ?>
    <div class="success">
      <div class="success-icon" style="color: #4caf50;"><i class="fa-solid fa-check-circle"></i></div>
      <strong><?php echo isset($restoreSuccess) ? 'Restore Complete!' : 'Setup Complete!'; ?></strong>
      <p style="margin-top: 10px;">Redirecting to dashboard...</p>
    </div>
  <?php else: ?>
    <h1>Welcome!</h1>
    <p class="subtitle">Let's set up your MultiDash</p>
    
    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="setup-options">
        <!-- Option 1: Create User -->
        <div class="option-block">
            <h3><i class="fa-solid fa-user-plus"></i> New Installation</h3>
            <p>Create your administrator account to get started.</p>

            <form method="POST">
              <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="username" required minlength="3">
              </div>
              <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required minlength="6">
              </div>
              <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required minlength="6">
              </div>
              <button type="submit" class="btn">Create Account</button>
            </form>
        </div>

        <div class="divider">
            <span>OR</span>
        </div>

        <!-- Option 2: Restore -->
        <div class="option-block restore">
            <h3><i class="fa-solid fa-upload"></i> Restore from Backup</h3>
            <p>Already have a backup? Upload the .zip file to restore your configuration.</p>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <input type="file" name="backup_file" accept=".zip" required id="file-upload" class="file-input">
                    <label for="file-upload" class="file-label">
                        <i class="fa-solid fa-file-zipper"></i> Choose File
                    </label>
                    <div id="file-name" class="file-name"></div>
                </div>
                <button type="submit" class="btn" style="background: #37474f;">Restore System</button>
            </form>
        </div>
    </div>
  <?php endif; ?>
</div>

<script>
document.getElementById('file-upload')?.addEventListener('change', function(e) {
    const fileName = e.target.files[0] ? e.target.files[0].name : '';
    document.getElementById('file-name').textContent = fileName;
});
</script>

<style>
.setup-options {
    display: flex;
    flex-direction: column;
    gap: 30px;
}
.option-block {
    background: rgba(255,255,255,0.05);
    padding: 20px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.1);
}
.option-block h3 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 1.1rem;
    color: #e0e0e0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.option-block p {
    font-size: 0.9rem;
    color: #aaa;
    margin-bottom: 20px;
}
.divider {
    text-align: center;
    position: relative;
    color: #666;
    font-size: 0.9rem;
    font-weight: bold;
}
.divider::before, .divider::after {
    content: "";
    position: absolute;
    top: 50%;
    width: 45%;
    height: 1px;
    background: rgba(255,255,255,0.1);
}
.divider::before { left: 0; }
.divider::after { right: 0; }

.file-input {
    display: none;
}
.file-label {
    display: block;
    padding: 10px;
    background: rgba(255,255,255,0.1);
    text-align: center;
    border-radius: 4px;
    cursor: pointer;
    border: 1px dashed rgba(255,255,255,0.3);
    transition: all 0.2s;
    color: #ddd;
}
.file-label:hover {
    background: rgba(255,255,255,0.15);
    border-color: #aaa;
}
.file-name {
    margin-top: 8px;
    font-size: 0.85rem;
    color: #4caf50;
    text-align: center;
}
</style>
</body>
</html>