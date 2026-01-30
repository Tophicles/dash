<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Read the input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
if (empty($data['name']) || empty($data['type']) || empty($data['url'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Load existing servers
$serversFile = 'servers.json';
if (!file_exists($serversFile)) {
    echo json_encode(['success' => false, 'error' => 'servers.json not found']);
    exit;
}

$config = json_decode(file_get_contents($serversFile), true);
if (!$config) {
    echo json_encode(['success' => false, 'error' => 'Failed to parse servers.json']);
    exit;
}

// Get the next order number
$maxOrder = 0;
foreach ($config['servers'] as $server) {
    if (isset($server['order']) && $server['order'] > $maxOrder) {
        $maxOrder = $server['order'];
    }
}

// Add new server with unique ID and muted color
$newServer = [
    'id' => uniqid('srv_', true),
    'name' => $data['name'],
    'type' => $data['type'],
    'url' => $data['url'],
    'enabled' => true,
    'order' => $maxOrder + 1
];

// Add optional fields with encryption
if (!empty($data['apiKey'])) {
    $newServer['apiKey'] = encrypt($data['apiKey']);
}
if (!empty($data['token'])) {
    $newServer['token'] = encrypt($data['token']);
}

// Add optional SSH fields
if (!empty($data['ssh_host'])) $newServer['ssh_host'] = $data['ssh_host'];
if (!empty($data['ssh_port'])) $newServer['ssh_port'] = $data['ssh_port'];
if (!empty($data['ssh_user'])) $newServer['ssh_user'] = $data['ssh_user'];
if (!empty($data['ssh_service'])) $newServer['ssh_service'] = $data['ssh_service'];

$config['servers'][] = $newServer;

error_log("Adding new server: " . json_encode($newServer));
error_log("Total servers count before save: " . count($config['servers']));

// Save to file
$writeResult = file_put_contents($serversFile, json_encode($config, JSON_PRETTY_PRINT));
if ($writeResult === false) {
    writeLog("Failed to add server '{$newServer['name']}': Write permission denied", "ERROR");
    error_log("Failed to write servers.json. File permissions: " . substr(sprintf('%o', fileperms($serversFile)), -4));
    echo json_encode(['success' => false, 'error' => 'Failed to write servers.json - check file permissions']);
    exit;
}

$user = getCurrentUser()['username'];
writeLog("User '$user' added server '{$newServer['name']}' (Type: {$newServer['type']})", "AUTH");
echo json_encode(['success' => true, 'server' => $newServer]);
?>