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
    $baseUrl = $server['url'];
    if (!preg_match('/^https?:\/\//', $baseUrl)) {
        $baseUrl = 'http://' . $baseUrl;
    }
    $baseUrl = rtrim($baseUrl, '/');

    if ($type === 'emby' || $type === 'jellyfin') {
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
                        'id' => $u['Id'] ?? '',
                        'name' => $u['Name'] ?? 'Unknown',
                        'lastLogin' => $u['LastLoginDate'] ?? null,
                        'serverId' => $server['id'],
                        'serverName' => $server['name'],
                        'serverType' => $type
                    ];
                }
            }
        }
    } elseif ($type === 'plex') {
        $token = isset($server['token']) ? decrypt($server['token']) : '';
        if (empty($token)) continue;

        // Plex local accounts endpoint
        $url = $baseUrl . '/accounts';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Plex-Token: $token",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $accounts = $data['MediaContainer']['Account'] ?? [];
            foreach ($accounts as $acc) {
                $allUsers[] = [
                    'id' => $acc['id'] ?? '',
                    'name' => $acc['name'] ?? 'Unknown',
                    'lastLogin' => null, // Plex doesn't provide this here
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

// Remove duplicates (same name and ID on same server)
$allUsers = array_values(array_filter($allUsers, function($v, $k) use ($allUsers) {
    for ($i = 0; $i < $k; $i++) {
        if ($allUsers[$i]['name'] === $v['name'] && $allUsers[$i]['id'] === $v['id'] && $allUsers[$i]['serverId'] === $v['serverId']) {
            return false;
        }
    }
    return true;
}, ARRAY_FILTER_USE_BOTH));

echo json_encode(['success' => true, 'users' => $allUsers]);
