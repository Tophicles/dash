<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

header('Content-Type: application/json');

$serverName = $_GET['server'] ?? '';
$servers = json_decode(file_get_contents('servers.json'), true)['servers'];

$server = array_filter($servers, fn($s) => $s['name'] === $serverName);
if (!$server) { echo json_encode([]); exit; }

$server = array_values($server)[0];

// Helper function to ensure URL has protocol
function ensureProtocol($url) {
    if (!preg_match('/^https?:\/\//', $url)) {
        return 'http://' . $url;
    }
    return $url;
}

if ($server['type'] === 'plex') {
    $baseUrl = ensureProtocol($server['url']);
    $url = rtrim($baseUrl, '/') . '/status/sessions';
    $token = isset($server['token']) ? decrypt($server['token']) : '';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Plex-Token: $token",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Debug logging (optional - remove in production)
    if ($error) {
        error_log("Plex API Error for {$server['name']}: $error");
    }
    if ($httpCode !== 200) {
        error_log("Plex API HTTP $httpCode for {$server['name']}");
    }

    echo $res ?: json_encode(['MediaContainer'=>['Metadata'=>[]]]);
    exit;
}

// Emby proxy logic
if ($server['type'] === 'emby') {
    $baseUrl = ensureProtocol($server['url']);
    $url = rtrim($baseUrl, '/') . '/Sessions';
    $apiKey = isset($server['apiKey']) ? decrypt($server['apiKey']) : '';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $apiKey"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $res = curl_exec($ch);
    curl_close($ch);
    echo $res ?: json_encode([]);
    exit;
}
?>
