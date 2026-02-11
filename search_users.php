<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

header('Content-Type: application/json');

$serversFile = DB_DIR . 'servers.json';
if (!file_exists($serversFile)) {
    echo json_encode(['success' => false, 'error' => 'Servers configuration not found']);
    exit;
}

$config = json_decode(file_get_contents($serversFile), true);
$servers = $config['servers'] ?? [];

$allUsers = [];

foreach ($servers as $server) {
    if (!$server['enabled']) continue;

    $type = $server['type'];
    if ($type !== 'emby' && $type !== 'jellyfin') continue;

    $baseUrl = $server['url'];
    if (!preg_match('/^https?:\/\//', $baseUrl)) {
        $baseUrl = 'http://' . $baseUrl;
    }
    $baseUrl = rtrim($baseUrl, '/');

    $apiKey = isset($server['apiKey']) ? decrypt($server['apiKey']) : '';
    if (empty($apiKey)) continue;

    $url = $baseUrl . '/Users';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: $apiKey",
        "X-MediaBrowser-Token: $apiKey",
        "Accept: application/json"
    ]);

    // Disable SSL verification if needed (common in local setups)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $users = json_decode($response, true);
        if (is_array($users)) {
            foreach ($users as $u) {
                $allUsers[] = [
                    'name' => $u['Name'] ?? 'Unknown',
                    'id' => $u['Id'] ?? '',
                    'serverId' => $server['id'],
                    'serverName' => $server['name'],
                    'serverType' => $type
                ];
            }
        }
    }
}

// Sort users by name
usort($allUsers, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode(['success' => true, 'users' => $allUsers]);
