<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
require_once 'auth.php';
requireLogin();

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'user' => getCurrentUser()
]);