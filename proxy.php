<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();

// Close session to prevent locking while waiting for external APIs
session_write_close();

header('Content-Type: application/json');

$serverName = $_GET['server'] ?? '';
$servers = json_decode(file_get_contents('servers.json'), true)['servers'];

$server = array_filter($servers, fn($s) => $s['name'] === $serverName);
if (!$server) { echo json_encode([]); exit; }

$server = array_values($server)[0];
$action = $_GET['action'] ?? 'sessions';

// Helper function to ensure URL has protocol
function ensureProtocol($url) {
    if (!preg_match('/^https?:\/\//', $url)) {
        return 'http://' . $url;
    }
    return $url;
}

if ($server['type'] === 'plex') {
    $baseUrl = ensureProtocol($server['url']);
    $token = isset($server['token']) ? decrypt($server['token']) : '';

    if ($action === 'info') {
        // Fetch server info
        $urlInfo = rtrim($baseUrl, '/') . '/';
        $urlUpdate = rtrim($baseUrl, '/') . '/updater/status';

        // Helper to fetch JSON
        $fetch = function($u) use ($token) {
            $ch = curl_init($u);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Plex-Token: $token", "Accept: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $r = curl_exec($ch);
            curl_close($ch);
            return json_decode($r, true);
        };

        $info = $fetch($urlInfo);
        $update = $fetch($urlUpdate);

        $response = [
            'version' => $info['MediaContainer']['version'] ?? 'Unknown',
            'updateAvailable' => (bool)($update['MediaContainer']['checkForUpdate'] ?? false) // Plex often uses checkForUpdate or updateAvailable
        ];
        // Note: Plex API structure for updates varies, sometimes checks 'downloadURL' or 'updateAvailable'
        if (isset($update['MediaContainer']['downloadURL'])) {
             $response['updateAvailable'] = true;
        }

        // Test flag to simulate update availability
        if (isset($_GET['test_update'])) {
            $response['updateAvailable'] = true;
            writeLog("[TEST] Simulated update availability for server: {$server['name']}", "INFO");
        }

        echo json_encode($response);
        exit;
    } else {
        $url = rtrim($baseUrl, '/') . '/status/sessions';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Plex-Token: $token",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $startTime = microtime(true);
    $res = curl_exec($ch);
    $duration = microtime(true) - $startTime;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Log slow requests (> 2 seconds)
    if ($duration > 2.0) {
        writeLog("Slow Plex response from {$server['name']}: " . round($duration, 2) . "s", "WARN");
    }

    if ($error) {
        writeLog("Plex API Error for {$server['name']}: $error", "ERROR");
    }
    if ($httpCode !== 200) {
        writeLog("Plex API HTTP $httpCode for {$server['name']}", "ERROR");
    }

    if ($res && $httpCode === 200) {
        logWatchers($server['name'], 'plex', $res);
    }

    echo $res ?: json_encode(['MediaContainer'=>['Metadata'=>[]]]);
    exit;
}

// Emby/Jellyfin proxy logic
if ($server['type'] === 'emby' || $server['type'] === 'jellyfin') {
    $baseUrl = ensureProtocol($server['url']);
    $apiKey = isset($server['apiKey']) ? decrypt($server['apiKey']) : '';

    if ($action === 'info') {
        $url = rtrim($baseUrl, '/') . '/System/Info';

        $headers = [
            "X-Emby-Token: $apiKey",
            "X-MediaBrowser-Token: $apiKey"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);
        $response = [
            'version' => $data['Version'] ?? 'Unknown',
            'updateAvailable' => (bool)($data['HasUpdateAvailable'] ?? false)
        ];

        // Test flag to simulate update availability
        if (isset($_GET['test_update'])) {
            $response['updateAvailable'] = true;
            writeLog("[TEST] Simulated update availability for server: {$server['name']}", "INFO");
        }

        echo json_encode($response);
        exit;
    } else {
        $url = rtrim($baseUrl, '/') . '/Sessions';
    }

    $headers = [
        "X-Emby-Token: $apiKey",
        "X-MediaBrowser-Token: $apiKey" // Jellyfin compatibility
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $startTime = microtime(true);
    $res = curl_exec($ch);
    $duration = microtime(true) - $startTime;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($duration > 2.0) {
        writeLog("Slow Emby response from {$server['name']}: " . round($duration, 2) . "s", "WARN");
    }

    if ($error) {
        writeLog("Emby API Error for {$server['name']}: $error", "ERROR");
    }
    if ($httpCode !== 200) {
        writeLog("Emby API HTTP $httpCode for {$server['name']}", "ERROR");
    }

    if ($res && $httpCode === 200) {
        logWatchers($server['name'], 'emby', $res);
    }

    echo $res ?: json_encode([]);
    exit;
}

// Watcher Logging Helper
function logWatchers($serverName, $type, $jsonResponse) {
    $data = json_decode($jsonResponse, true);
    if (!$data) return;

    $watchers = [];

    // Parse response based on type
    if ($type === 'plex') {
        $sessions = $data['MediaContainer']['Metadata'] ?? [];
        foreach ($sessions as $s) {
            $user = $s['User']['title'] ?? 'Unknown';
            $title = $s['title'] ?? 'Unknown Title';
            if (isset($s['grandparentTitle'])) {
                $title = $s['grandparentTitle'] . " - " . $title;
            }
            $watchers[$user] = $title;
        }
    } elseif ($type === 'emby') {
        foreach ($data as $s) {
            if (!isset($s['NowPlayingItem'])) continue;
            $user = $s['UserName'] ?? 'Unknown';
            $title = $s['NowPlayingItem']['Name'] ?? 'Unknown Title';
            if (isset($s['NowPlayingItem']['SeriesName'])) {
                $title = $s['NowPlayingItem']['SeriesName'] . " - " . $title;
            }
            $watchers[$user] = $title;
        }
    }

    // Load state
    $stateFile = 'watcher_state.json';
    $state = [];
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true) ?: [];
    }

    // Check for changes
    $serverState = $state[$serverName] ?? [];
    $hasChanges = false;

    // Detect new watchers or media changes
    foreach ($watchers as $user => $title) {
        $oldTitle = $serverState[$user] ?? null;
        if ($oldTitle !== $title) {
            writeLog("[WATCH] User '$user' started watching '$title' on '$serverName'", "INFO");
            $hasChanges = true;
        }
    }

    // Update state only if changed or users left
    // We also need to remove users who stopped watching
    $diff = array_diff_key($serverState, $watchers);
    if (!empty($diff)) {
        foreach (array_keys($diff) as $user) {
             writeLog("[WATCH] User '$user' stopped watching on '$serverName'", "INFO");
        }
        $hasChanges = true;
    }

    if ($hasChanges) {
        $state[$serverName] = $watchers;
        file_put_contents($stateFile, json_encode($state));
        @chmod($stateFile, 0666);
    }
}
?>
