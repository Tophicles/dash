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

// Update server data (preserve existing apiKey/token if not provided, keep ID unchanged)
$existingServer = $servers['servers'][$serverIndex];
$servers['servers'][$serverIndex] = [
    "id" => $existingServer['id'], // Keep the original ID
    "name" => $newName,
    "type" => $data['type'] ?? $existingServer['type'],
    "url" => $data['url'] ?? $existingServer['url'],
    "apiKey" => isset($data['apiKey']) && $data['apiKey'] !== '' ? encrypt($data['apiKey']) : ($existingServer['apiKey'] ?? ''),
    "token" => isset($data['token']) && $data['token'] !== '' ? encrypt($data['token']) : ($existingServer['token'] ?? ''),
    "ssh_host" => $data['ssh_host'] ?? $existingServer['ssh_host'] ?? '',
    "ssh_port" => $data['ssh_port'] ?? $existingServer['ssh_port'] ?? '22',
    "ssh_user" => $data['ssh_user'] ?? $existingServer['ssh_user'] ?? '',
    "ssh_service" => $data['ssh_service'] ?? $existingServer['ssh_service'] ?? '',
    "enabled" => true,
    "order" => $existingServer['order'] ?? (count($servers['servers']) + 1)
];

if(file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT))){
    $user = getCurrentUser()['username'];
    writeLog("User '$user' updated server '$newName' (ID: $serverId)", "AUTH");
    echo json_encode(['success'=>true,'server'=>$servers['servers'][$serverIndex]]);
} else {
    writeLog("Failed to update server '$newName': Write permission denied", "ERROR");
    echo json_encode(['success'=>false,'error'=>'Failed to write servers.json']);
}
?>