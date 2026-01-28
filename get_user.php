<?php
require_once 'auth.php';
requireLogin();

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'user' => getCurrentUser()
]);