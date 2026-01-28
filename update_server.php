<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
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
    "color" => $existingServer['color'] ?? randomMutedColor(),
    "enabled" => true,
    "order" => $existingServer['order'] ?? (count($servers['servers']) + 1)
];

if(file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT))){
    echo json_encode(['success'=>true,'server'=>$servers['servers'][$serverIndex]]);
} else {
    echo json_encode(['success'=>false,'error'=>'Failed to write servers.json']);
}

function randomMutedColor() {
    $hue = rand(0, 360);
    $sat = rand(25, 45);
    $light = rand(18, 28);
    return hslToHex($hue, $sat, $light);
}

function hslToHex($h, $s, $l) {
    $s /= 100;
    $l /= 100;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    if ($h < 60)       [$r,$g,$b] = [$c,$x,0];
    elseif ($h < 120)  [$r,$g,$b] = [$x,$c,0];
    elseif ($h < 180)  [$r,$g,$b] = [0,$c,$x];
    elseif ($h < 240)  [$r,$g,$b] = [0,$x,$c];
    elseif ($h < 300)  [$r,$g,$b] = [$x,0,$c];
    else               [$r,$g,$b] = [$c,0,$x];
    $r = dechex((int)(($r + $m) * 255));
    $g = dechex((int)(($g + $m) * 255));
    $b = dechex((int)(($b + $m) * 255));
    return "#" .
        str_pad($r, 2, "0", STR_PAD_LEFT) .
        str_pad($g, 2, "0", STR_PAD_LEFT) .
        str_pad($b, 2, "0", STR_PAD_LEFT);
}
?>