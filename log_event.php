<?php
require_once 'auth.php';
require_once 'logging.php';

requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$level = $input['level'] ?? 'INFO';

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message required']);
    exit;
}

writeLog($message, $level);
echo json_encode(['success' => true]);
?>