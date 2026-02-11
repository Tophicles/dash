<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$serverId = $input['serverId'] ?? '';
$userId = $input['userId'] ?? '';
$newPassword = $input['newPassword'] ?? '';

if (!$serverId || !$userId || $newPassword === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$serversFile = DB_DIR . 'servers.json';
if (!file_exists($serversFile)) {
    echo json_encode(['success' => false, 'error' => 'Servers configuration not found']);
    exit;
}

$config = json_decode(file_get_contents($serversFile), true);
$server = null;
foreach ($config['servers'] as $s) {
    if ((string)($s['id'] ?? '') === (string)$serverId) {
        $server = $s;
        break;
    }
}

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server not found']);
    exit;
}

if ($server['type'] !== 'emby' && $server['type'] !== 'jellyfin') {
    echo json_encode(['success' => false, 'error' => 'Password change only supported for Emby/Jellyfin']);
    exit;
}

$type = $server['type'];

// Ensure URL has protocol
function ensureProtocol($url) {
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        return "http://" . $url;
    }
    return $url;
}

$baseUrl = rtrim(ensureProtocol($server['url']), '/');
$apiKey = isset($server['apiKey']) ? decrypt($server['apiKey']) : '';

// Jellyfin/Emby Password Change API
// Use urlencode for userId just in case
$url = $baseUrl . '/Users/' . urlencode($userId) . '/Password';

$postData = json_encode([
    'CurrentPassword' => null,
    'NewPassword' => $newPassword,
    'ResetPassword' => true
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-Emby-Token: $apiKey",
    "X-MediaBrowser-Token: $apiKey",
    "Content-Type: application/json",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 204 || $httpCode === 200) {
    writeLog("Admin '" . getCurrentUser()['username'] . "' changed password for media user ID '$userId' on '{$server['name']}'", "INFO");
    echo json_encode(['success' => true]);
} else {
    $error = "HTTP $httpCode";
    if ($curlError) {
        $error = "Connection Error: $curlError";
    } else {
        $data = json_decode($res, true);
        if (isset($data['Message'])) {
            $error = $data['Message'];
        } else if (!empty($res)) {
            $error = "HTTP $httpCode: " . (strlen($res) > 100 ? substr($res, 0, 100) . "..." : $res);
        }
    }

    writeLog("Failed to change password for media user ID '$userId' on '{$server['name']}': $error. Full Response: " . ($res ?: 'Empty'), "ERROR");
    echo json_encode(['success' => false, 'error' => $error]);
}
