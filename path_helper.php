<?php
// Function to get the data directory path
function getDataDir() {
    $envPath = getenv('CONFIG_DIR');
    if ($envPath) {
        return rtrim($envPath, '/') . '/';
    }
    return __DIR__ . '/';
}

// Define the constant if not already defined
if (!defined('DATA_DIR')) {
    define('DATA_DIR', getDataDir());
}

if (!defined('DB_DIR')) {
    define('DB_DIR', DATA_DIR . 'db/');
}

// Ensure DATA_DIR exists
if (!file_exists(DATA_DIR)) {
    if (!@mkdir(DATA_DIR, 0777, true)) {
        $error = error_get_last();
        die("Error: Cannot create data directory " . DATA_DIR . ". " . ($error['message'] ?? 'Permission denied.'));
    }
}

// Ensure DATA_DIR is writable
if (!is_writable(DATA_DIR)) {
    // Attempt to fix permissions
    if (!@chmod(DATA_DIR, 0777)) {
        http_response_code(500);
        die("Error: Data directory " . DATA_DIR . " is not writable. Please check file permissions.");
    }
}

// Ensure DB_DIR exists
if (!file_exists(DB_DIR)) {
    if (!@mkdir(DB_DIR, 0777, true)) {
        $error = error_get_last();
        die("Error: Cannot create database directory " . DB_DIR . ". " . ($error['message'] ?? 'Permission denied.'));
    }

    // Migration: Move existing JSON files to DB_DIR
    $filesToMove = ['users.json', 'servers.json', 'activity.json', 'watcher_state.json'];
    foreach ($filesToMove as $file) {
        if (file_exists(DATA_DIR . $file)) {
            rename(DATA_DIR . $file, DB_DIR . $file);
        }
    }
}

// Ensure DB_DIR is writable
if (!is_writable(DB_DIR)) {
    // Attempt to fix permissions
    if (!@chmod(DB_DIR, 0777)) {
        http_response_code(500);
        die("Error: Database directory " . DB_DIR . " is not writable. Please check file permissions.");
    }
}
?>
