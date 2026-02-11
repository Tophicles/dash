<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

header('Content-Type: application/json');

$serverId = $_GET['serverId'] ?? '';
$userId = $_GET['userId'] ?? '';

if (!$serverId || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Missing serverId or userId']);
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
    if ((string)$s['id'] === (string)$serverId) {
        $server = $s;
        break;
    }
}

if (!$server) {
    echo json_encode(['success' => false, 'error' => 'Server not found']);
    exit;
}

$type = $server['type'];
$baseUrl = rtrim($server['url'], '/');
if (!preg_match('/^https?:\/\//', $baseUrl)) {
    $baseUrl = 'http://' . $baseUrl;
}

$response = [
    'success' => true,
    'details' => [],
    'history' => []
];

if ($type === 'emby' || $type === 'jellyfin') {
    $apiKey = isset($server['apiKey']) ? decrypt($server['apiKey']) : '';

    // 1. Get User Details
    $url = "$baseUrl/Users/$userId";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: $apiKey",
        "X-MediaBrowser-Token: $apiKey",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200 && $res) {
        $response['details'] = json_decode($res, true) ?: [];
    }
    curl_close($ch);

    // 2. Get Watch History
    // Added UserData to fields to get LastPlayedDate from it
    $url = "$baseUrl/Users/$userId/Items?Recursive=true&IncludeItemTypes=Movie,Episode&SortBy=DatePlayed&SortOrder=Descending&Filters=IsPlayed&Limit=10&Fields=PrimaryImageAspectRatio,DateCreated,LastPlayedDate,DateLastPlayed,UserData";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: $apiKey",
        "X-MediaBrowser-Token: $apiKey",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200 && $res) {
        $data = json_decode($res, true) ?: [];
        $items = $data['Items'] ?? [];
        foreach ($items as $item) {
            $title = $item['Name'] ?? 'Unknown';
            if (isset($item['SeriesName'])) {
                $title = $item['SeriesName'] . ' - ' . $title;
            }
            // Check UserData first, then top level
            $playedDate = $item['UserData']['LastPlayedDate'] ?? $item['LastPlayedDate'] ?? $item['DateLastPlayed'] ?? null;
            $response['history'][] = [
                'id' => $item['Id'],
                'title' => $title,
                'type' => $item['Type'],
                'date' => $playedDate,
                'image' => "get_image.php?serverId=$serverId&serverType=$type&id=" . ($item['Id']) . "&type=Primary"
            ];
        }
    }
    curl_close($ch);

} elseif ($type === 'plex') {
    $token = isset($server['token']) ? decrypt($server['token']) : '';

    // 2. Get Watch History
    $url = "$baseUrl/status/sessions/history/all?accountID=$userId&sort=viewedAt:desc&container-start=0&container-size=10";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Plex-Token: $token",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200 && $res) {
        $data = json_decode($res, true) ?: [];
        $metadata = $data['MediaContainer']['Metadata'] ?? [];
        foreach ($metadata as $item) {
            $title = $item['title'] ?? 'Unknown';
            if (isset($item['grandparentTitle'])) {
                $title = $item['grandparentTitle'] . ' - ' . $title;
            }

            $imgId = $item['ratingKey'] ?? '';
            if (isset($item['grandparentRatingKey'])) {
                $imgId = $item['grandparentRatingKey'];
            }

            $response['history'][] = [
                'id' => $item['ratingKey'],
                'title' => $title,
                'type' => $item['type'],
                'date' => isset($item['viewedAt']) ? date('c', (int)$item['viewedAt']) : null,
                'image' => "get_image.php?serverId=$serverId&serverType=$type&id=$imgId&type=thumb"
            ];
        }
    }
    curl_close($ch);
}

echo json_encode($response);
