<?php
require_once 'auth.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');
$serversFile = __DIR__."/servers.json";
$data = json_decode(file_get_contents('php://input'), true);

if(!$data || !isset($data['servers'])) { 
    echo json_encode(['success'=>false,'error'=>'Servers data required']); 
    exit; 
}

$servers = json_decode(file_get_contents($serversFile), true);
$newOrder = $data['servers'];

// Update the order of servers in the config
foreach ($servers['servers'] as &$server) {
    $newIndex = array_search($server['id'], array_column($newOrder, 'id'));
    if ($newIndex !== false && isset($newOrder[$newIndex]['order'])) {
        $server['order'] = $newOrder[$newIndex]['order'];
    }
}

// Sort servers by order
usort($servers['servers'], function($a, $b) {
    return ($a['order'] ?? 0) - ($b['order'] ?? 0);
});

if(file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT))){
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false,'error'=>'Failed to write servers.json']);
}
?>