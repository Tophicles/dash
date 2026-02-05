<?php
// Disable display_errors to ensure JSON output is not corrupted by warnings
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'logging.php';
requireLogin(false); // Do not update activity on polling

// Close session early to release lock
session_write_close();

header('Content-Type: application/json');

$activityFile = DB_DIR . 'activity.json';
$activeUsers = [];

if (file_exists($activityFile)) {
    $json = file_get_contents($activityFile);
    $activity = json_decode($json, true) ?: [];

    // Load user details for roles
    $allUsers = loadUsers();

    $now = time();
    $threshold = 5 * 60; // 5 minutes

    foreach ($activity as $user => $timestamp) {
        if ($now - $timestamp <= $threshold) {
            $role = isset($allUsers[$user]['role']) ? $allUsers[$user]['role'] : 'viewer';
            $activeUsers[] = [
                'username' => $user,
                'role' => $role
            ];
        }
    }
}

// Sort alphabetically by username
usort($activeUsers, function($a, $b) {
    return strcasecmp($a['username'], $b['username']);
});

echo json_encode(['users' => $activeUsers]);
?>
