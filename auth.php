<?php
require_once 'logging.php';
require_once 'path_helper.php';
session_start();

// Load users from JSON file
function loadUsers() {
    $usersFile = DB_DIR . 'users.json';
    if (!file_exists($usersFile)) {
        // No users file exists, redirect to setup
        if (basename($_SERVER['PHP_SELF']) !== 'setup.php') {
            header('Location: setup.php');
            exit;
        }
        return [];
    }
    
    $json = file_get_contents($usersFile);
    $users = json_decode($json, true);
    return $users ?: [];
}

// Save users to JSON file
function saveUsers($users) {
    $usersFile = DB_DIR . 'users.json';
    return file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT)) !== false;
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

// Get current user
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Login function
function login($username, $password) {
    $users = loadUsers();
    
    if (isset($users[$username])) {
        if (password_verify($password, $users[$username]['password'])) {
            $_SESSION['user'] = [
                'username' => $username,
                'role' => $users[$username]['role']
            ];
            $_SESSION['last_activity'] = time();
            writeLog("User logged in: $username", "AUTH");
            return true;
        }
    }
    writeLog("Failed login attempt for user: $username", "WARN");
    return false;
}

// Logout function
function logout() {
    $user = getCurrentUser()['username'] ?? 'Unknown';
    writeLog("User logged out: $user", "AUTH");
    session_destroy();
    unset($_SESSION['user']);
}

// Check session timeout (30 minutes)
function checkSessionTimeout() {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Update user activity timestamp
function updateActivity() {
    if (!isLoggedIn()) return;

    $activityFile = DB_DIR . 'activity.json';
    $user = getCurrentUser()['username'];
    $now = time();

    $activity = [];
    if (file_exists($activityFile)) {
        $json = file_get_contents($activityFile);
        $activity = json_decode($json, true) ?: [];
    }

    $activity[$user] = $now;

    // Optional: Prune very old entries (older than 1 hour) to keep file small
    foreach ($activity as $u => $time) {
        if ($now - $time > 3600) {
            unset($activity[$u]);
        }
    }

    file_put_contents($activityFile, json_encode($activity), LOCK_EX);
}

// Require login - redirect to login page if not logged in
function requireLogin($updateActivity = true) {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    checkSessionTimeout();
    if ($updateActivity) {
        updateActivity();
    }
}

// Require admin - return error if not admin
function requireAdmin() {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}