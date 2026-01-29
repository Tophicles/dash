<?php
require_once 'auth.php';
require_once 'logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Get all users (excluding passwords)
if ($method === 'GET') {
    $users = loadUsers();
    $userList = [];
    foreach ($users as $username => $data) {
        $userList[] = [
            'username' => $username,
            'role' => $data['role'],
            'created' => $data['created'] ?? 'Unknown'
        ];
    }
    echo json_encode(['success' => true, 'users' => $userList]);
    exit;
}

// Add new user
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'add') {
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? 'viewer';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username and password required']);
        exit;
    }
    
    if (strlen($username) < 3) {
        echo json_encode(['success' => false, 'error' => 'Username must be at least 3 characters']);
        exit;
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
        exit;
    }
    
    $users = loadUsers();
    
    if (isset($users[$username])) {
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit;
    }
    
    $users[$username] = [
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => in_array($role, ['admin', 'viewer']) ? $role : 'viewer',
        'created' => date('Y-m-d H:i:s')
    ];
    
    if (saveUsers($users)) {
        $admin = getCurrentUser()['username'];
        writeLog("User '$admin' created new user '$username' with role '$role'", "AUTH");
        echo json_encode(['success' => true, 'message' => 'User added successfully']);
    } else {
        writeLog("Failed to create user '$username'", "ERROR");
        echo json_encode(['success' => false, 'error' => 'Failed to save user']);
    }
    exit;
}

// Update user
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'update') {
    $username = $input['username'] ?? '';
    $newPassword = $input['password'] ?? '';
    $newRole = $input['role'] ?? '';
    
    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => 'Username required']);
        exit;
    }
    
    $users = loadUsers();
    
    if (!isset($users[$username])) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Update password if provided
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
            exit;
        }
        $users[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $admin = getCurrentUser()['username'];
        writeLog("User '$admin' changed password for user '$username'", "AUTH");
    }
    
    // Update role if provided
    if (!empty($newRole) && in_array($newRole, ['admin', 'viewer'])) {
        // Prevent demoting the last admin
        if ($users[$username]['role'] === 'admin' && $newRole === 'viewer') {
            $adminCount = 0;
            foreach ($users as $user) {
                if ($user['role'] === 'admin') {
                    $adminCount++;
                }
            }
            
            if ($adminCount <= 1) {
                echo json_encode(['success' => false, 'error' => 'Cannot demote the last admin user']);
                exit;
            }
        }
        
        $users[$username]['role'] = $newRole;
        $admin = getCurrentUser()['username'];
        writeLog("User '$admin' changed role for user '$username' to '$newRole'", "AUTH");
    }
    
    if (saveUsers($users)) {
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    } else {
        writeLog("Failed to update user '$username'", "ERROR");
        echo json_encode(['success' => false, 'error' => 'Failed to update user']);
    }
    exit;
}

// Delete user
if ($method === 'POST' && isset($input['action']) && $input['action'] === 'delete') {
    $username = $input['username'] ?? '';
    $currentUser = getCurrentUser();
    
    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => 'Username required']);
        exit;
    }
    
    // Prevent deleting yourself
    if ($username === $currentUser['username']) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
        exit;
    }
    
    $users = loadUsers();
    
    if (!isset($users[$username])) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Prevent deleting the last admin
    $adminCount = 0;
    foreach ($users as $user) {
        if ($user['role'] === 'admin') {
            $adminCount++;
        }
    }
    
    if ($users[$username]['role'] === 'admin' && $adminCount <= 1) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete the last admin user']);
        exit;
    }
    
    unset($users[$username]);
    
    if (saveUsers($users)) {
        $admin = getCurrentUser()['username'];
        writeLog("User '$admin' deleted user '$username'", "AUTH");
        echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
    } else {
        writeLog("Failed to delete user '$username'", "ERROR");
        echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);