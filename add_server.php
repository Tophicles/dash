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
    'order' => $maxOrder + 1,
    'color' => randomMutedColor()
];

// Add optional fields with encryption
if (!empty($data['apiKey'])) {
    $newServer['apiKey'] = encrypt($data['apiKey']);
}
if (!empty($data['token'])) {
    $newServer['token'] = encrypt($data['token']);
}

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