<?php
session_start();

// Load users from JSON file
function loadUsers() {
    $usersFile = 'users.json';
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
    $usersFile = 'users.json';
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
            return true;
        }
    }
    return false;
}

// Logout function
function logout() {
    session_destroy();
    unset($_SESSION['user']);
}

// Require login - redirect to login page if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
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