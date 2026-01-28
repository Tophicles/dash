<?php
require_once 'auth.php';
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

// Find and remove the server by ID
$servers['servers'] = array_filter($servers['servers'], function($s) use ($serverId) {
    return isset($s['id']) && $s['id'] !== $serverId;
});

// Re-index array
$servers['servers'] = array_values($servers['servers']);

if(file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT))){
    echo json_encode(['success'=>true,'id'=>$serverId]);
} else {
    echo json_encode(['success'=>false,'error'=>'Failed to write servers.json']);
}
?>