<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');
$serversFile = __DIR__."/servers.json";
$data = json_decode(file_get_contents('php://input'), true);

if(!$data || !isset($data['id'])) { 
    echo json_encode(['success'=>false,'error'=>'Server ID required']); 
    exit; 
}

$servers = json_decode(file_get_contents($serversFile), true);
$serverId = $data['id'];
$newName = $data['name'] ?? '';

// Find the server to update by ID
$serverIndex = null;
for($i = 0; $i < count($servers['servers']); $i++) {
    if(isset($servers['servers'][$i]['id']) && $servers['servers'][$i]['id'] === $serverId) {
        $serverIndex = $i;
        break;
    }
}

if($serverIndex === null) {
    echo json_encode(['success'=>false,'error'=>'Server not found']);
    exit;
}

// Partial Update Logic
$existingServer = $servers['servers'][$serverIndex];

// Fields to update if present in $data, otherwise keep existing
$updatableFields = ['name', 'type', 'url', 'os_type', 'ssh_port', 'order', 'enabled', 'ssh_initialized'];

foreach ($updatableFields as $field) {
    if (isset($data[$field])) {
        $existingServer[$field] = $data[$field];
    }
}

// Handle Encryption Fields
if (isset($data['apiKey']) && $data['apiKey'] !== '') {
    $existingServer['apiKey'] = encrypt($data['apiKey']);
}
if (isset($data['token']) && $data['token'] !== '') {
    $existingServer['token'] = encrypt($data['token']);
}

$servers['servers'][$serverIndex] = $existingServer;
$serverName = $existingServer['name'];

if(file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT))){
    $user = getCurrentUser()['username'];
    writeLog("User '$user' updated server '$serverName' (ID: $serverId)", "AUTH");
    echo json_encode(['success'=>true,'server'=>$existingServer]);
} else {
    writeLog("Failed to update server '$serverName': Write permission denied", "ERROR");
    echo json_encode(['success'=>false,'error'=>'Failed to write servers.json']);
}
?>