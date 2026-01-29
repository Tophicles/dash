<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';

requireLogin();
header('Content-Type: application/json');

// Only admins should manage libraries
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Close session to prevent locking the dashboard while waiting for external APIs
session_write_close();

$action = $_GET['action'] ?? '';
$serverName = $_GET['server'] ?? '';
$libraryId = $_GET['library_id'] ?? '';
$libraryName = $_GET['library_name'] ?? 'Unknown Library';

if (!$serverName || !$action) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Load server config
$serversFile = 'servers.json';
if (!file_exists($serversFile)) {
    echo json_encode(['error' => 'No servers configured']);
    exit;
}

$serversConfig = json_decode(file_get_contents($serversFile), true);
$servers = $serversConfig['servers'] ?? [];
$server = null;

foreach ($servers as $s) {
    if ($s['name'] === $serverName) {
        $server = $s;
        break;
    }
}

if (!$server) {
    echo json_encode(['error' => 'Server not found']);
    exit;
}

// Helper for protocol
function ensureProtocol($url) {
    if (!preg_match('/^https?:\/\//', $url)) {
        return 'http://' . $url;
    }
    return $url;
}

$baseUrl = ensureProtocol($server['url']);
$type = $server['type'];

// Decrypt credentials
$token = '';
if ($type === 'plex' && isset($server['token'])) {
    $token = decrypt($server['token']);
} elseif (($type === 'emby' || $type === 'jellyfin') && isset($server['apiKey'])) {
    $token = decrypt($server['apiKey']);
}

// --- PLEX LOGIC ---
if ($type === 'plex') {
    if ($action === 'list') {
        $url = rtrim($baseUrl, '/') . '/library/sections';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Plex-Token: $token",
            "Accept: application/json"
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo json_encode(['error' => "Plex API Error: HTTP $httpCode"]);
            exit;
        }

        $data = json_decode($res, true);
        $libraries = [];

        if (isset($data['MediaContainer']['Directory'])) {
            foreach ($data['MediaContainer']['Directory'] as $dir) {
                $libraries[] = [
                    'id' => $dir['key'],
                    'name' => $dir['title'],
                    'type' => $dir['type']
                ];
            }
        }

        echo json_encode(['success' => true, 'libraries' => $libraries]);
        exit;
    }
    elseif ($action === 'scan') {
        if (!$libraryId) {
            echo json_encode(['error' => 'Missing library ID']);
            exit;
        }

        $url = rtrim($baseUrl, '/') . "/library/sections/$libraryId/refresh";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Plex-Token: $token"
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $user = getCurrentUser()['username'] ?? 'Unknown';
            writeLog("Library Scan Initiated: Plex server '$serverName', Library '$libraryName' (ID: $libraryId) by user '$user'", "INFO");
            echo json_encode(['success' => true, 'message' => 'Scan started']);
        } else {
            writeLog("Library Scan Failed: Plex server '$serverName', Library '$libraryName', HTTP $httpCode", "ERROR");
            echo json_encode(['error' => "Plex API Error: HTTP $httpCode"]);
        }
        exit;
    }
}

// --- EMBY/JELLYFIN LOGIC ---
if ($type === 'emby' || $type === 'jellyfin') {
    $headers = [
        "X-Emby-Token: $token",
        "X-MediaBrowser-Token: $token",
        "Accept: application/json"
    ];

    if ($action === 'list') {
        // Use /Library/VirtualFolders/Query to get real libraries
        $url = rtrim($baseUrl, '/') . '/Library/VirtualFolders/Query';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo json_encode(['error' => "Emby API Error: HTTP $httpCode"]);
            exit;
        }

        $data = json_decode($res, true);
        $libraries = [];

        $items = $data['Items'] ?? [];
        foreach ($items as $item) {
            $libraries[] = [
                'id' => $item['ItemId'],
                'name' => $item['Name'],
                'type' => $item['CollectionType'] ?? 'library'
            ];
        }

        echo json_encode(['success' => true, 'libraries' => $libraries]);
        exit;
    }
    elseif ($action === 'scan') {
        // Emby Global Scan or Specific Library Scan
        if ($libraryId === 'all') {
             $url = rtrim($baseUrl, '/') . "/Library/Refresh";
        } elseif ($libraryId) {
             $url = rtrim($baseUrl, '/') . "/Items/$libraryId/Refresh?Recursive=true&ImageRefreshMode=Default&MetadataRefreshMode=Default&ReplaceAllMetadata=false&ReplaceAllImages=false";
        } else {
             echo json_encode(['error' => 'Missing library ID']);
             exit;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true); // POST for scanning
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Emby usually returns 204 for success
        if ($httpCode === 200 || $httpCode === 204) {
            $user = getCurrentUser()['username'] ?? 'Unknown';
            writeLog("Library Scan Initiated: Emby server '$serverName', Library '$libraryName' (ID: $libraryId) by user '$user'", "INFO");
            echo json_encode(['success' => true, 'message' => 'Scan started']);
        } else {
            writeLog("Library Scan Failed: Emby server '$serverName', Library '$libraryName', HTTP $httpCode", "ERROR");
            echo json_encode(['error' => "Emby API Error: HTTP $httpCode"]);
        }
        exit;
    }
}

echo json_encode(['error' => 'Invalid parameters or server type']);
?>
