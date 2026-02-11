<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();

header('Content-Type: application/json');

$serverId = $_GET['serverId'] ?? '';
$userId = $_GET['userId'] ?? '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

// Cap the limit to a reasonable maximum to prevent server strain
if ($limit > 50) $limit = 50;
if ($limit < 1) $limit = 1;

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

    // 1. Get User Details (only on first page)
    if ($offset === 0) {
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
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("Error decoding Emby/Jellyfin user details for $userId: " . json_last_error_msg(), "ERROR");
        }
        }
        curl_close($ch);
    }

    // 2. Get Watch History
    // Added UserData to fields and EnableUserData=true to ensure dates are returned
    $url = "$baseUrl/Users/$userId/Items?Recursive=true&IncludeItemTypes=Movie,Episode&SortBy=DatePlayed&SortOrder=Descending&Filters=IsPlayed&StartIndex=$offset&Limit=$limit&Fields=PrimaryImageAspectRatio,DateCreated,LastPlayedDate,DateLastPlayed,UserData,DatePlayed,PlayedDate&EnableUserData=true";
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
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("Error decoding Emby/Jellyfin watch history for $userId: " . json_last_error_msg(), "ERROR");
        }
        $items = $data['Items'] ?? [];
        foreach ($items as $item) {
            $title = $item['Name'] ?? 'Unknown';
            if (isset($item['SeriesName'])) {
                $title = $item['SeriesName'] . ' - ' . $title;
            }
            // Check UserData first, then top level, then other potential date fields
            $playedDate = $item['UserData']['LastPlayedDate'] ?? $item['LastPlayedDate'] ?? $item['DateLastPlayed'] ?? $item['DatePlayed'] ?? $item['PlayedDate'] ?? $item['PremiereDate'] ?? $item['DateCreated'] ?? null;
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
    $url = "$baseUrl/status/sessions/history/all?accountID=$userId&sort=viewedAt:desc&container-start=$offset&container-size=$limit";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Plex-Token: $token",
        "Accept: application/json",
        "X-Plex-Container-Start: $offset",
        "X-Plex-Container-Size: $limit"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode === 200 && $res) {
        $data = json_decode($res, true) ?: [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("Error decoding Plex watch history for $userId: " . json_last_error_msg(), "ERROR");
        }
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

            $viewedAt = $item['viewedAt'] ?? $item['lastViewedAt'] ?? null;
            $response['history'][] = [
                'id' => $item['ratingKey'],
                'title' => $title,
                'type' => $item['type'],
                'date' => $viewedAt ? date('c', (int)$viewedAt) : null,
                'image' => "get_image.php?serverId=$serverId&serverType=$type&id=$imgId&type=thumb"
            ];
        }
    }
    curl_close($ch);
}

echo json_encode($response);
