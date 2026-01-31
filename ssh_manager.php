<?php
require_once 'auth.php';
require_once 'logging.php';
require_once 'ssh_helper.php';

requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_public_key') {
    $key = getSSHPublicKey();
    echo json_encode(['success' => true, 'key' => $key]);
    exit;
}

if ($action === 'generate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $agreed = $input['agreed'] ?? false;

    if (!$agreed) {
        echo json_encode(['success' => false, 'error' => 'Security agreement required']);
        exit;
    }

    if (generateSSHKeyPair()) {
        writeLog("SSH Key Generation: Admin user '" . getCurrentUser()['username'] . "' agreed to risks and generated a new key pair.", "WARNING");
        echo json_encode(['success' => true, 'key' => getSSHPublicKey()]);
    } else {
        writeLog("SSH Key Generation Failed", "ERROR");
        echo json_encode(['success' => false, 'error' => 'Failed to generate key']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>