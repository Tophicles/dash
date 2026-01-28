<?php
require_once 'auth.php';
requireLogin();

header('Content-Type: application/json');

$activityFile = 'activity.json';
$activeUsers = [];

if (file_exists($activityFile)) {
    $json = file_get_contents($activityFile);
    $activity = json_decode($json, true) ?: [];

    $now = time();
    $threshold = 5 * 60; // 5 minutes

    foreach ($activity as $user => $timestamp) {
        if ($now - $timestamp <= $threshold) {
            $activeUsers[] = $user;
        }
    }
}

// Sort alphabetically
sort($activeUsers);

echo json_encode(['users' => $activeUsers]);
?>