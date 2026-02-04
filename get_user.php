<?php
require_once 'auth.php';
// Default requireLogin(true) updates the activity timestamp
requireLogin();

header('Content-Type: application/json');
$user = getCurrentUser();
echo json_encode(['success' => true, 'username' => $user['username']]);
?>
