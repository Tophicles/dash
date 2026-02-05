<?php
// ----------------------------
// get_item_details.php - Patched for JSON safety
// ----------------------------

// Start output buffering to capture any accidental output
ob_start();

// Disable PHP errors from breaking JSON
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// Always return JSON header
header('Content-Type: application/json');

try {
    // Load authentication and helpers
    require_once 'auth.php';
    require_once 'encryption_helper.php';
    requireLogin();

    $serverName = $_GET['server'] ?? '';
    $itemId = $_GET['itemId'] ?? '';

    if (empty($serverName) || empty($itemId)) {
        echo json_encode(['success' => false, 'error' => 'Missing server or itemId']);
        exit;
    }

    // Load server configuration
    $serversFile = DB_DIR . 'servers.json';
    if (!file_exists($serversFile)) {
        echo json_encode(['success' => false, 'error' => 'servers.json not found']);
        exit;
    }

    $config = json_decode(file_get_contents($serversFile), true);
    $server = null;
    foreach ($config['servers'] as $s) {
        if ($s['name'] === $serverName) {
            $server = $s;
            break;
        }
    }

    if (!$server) {
        echo json_encode(['success' => false, 'error' => 'Server not found']);
        exit;
    }

    // Decrypt keys if present
    if (isset($server['apiKey'])) $server['apiKey'] = decrypt($server['apiKey']);
    if (isset($server['token'])) $server['token'] = decrypt($server['token']);

    // Ensure URL has protocol
    function ensureProtocol($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) return "http://" . $url;
        return $url;
    }

    $baseUrl = ensureProtocol($server['url']);

    $item = []; // initialize empty item array

    // ----------------------------
    // Emby / Jellyfin
    // ----------------------------
    if ($server['type'] === 'emby' || $server['type'] === 'jellyfin') {
        // Your existing Emby/Jellyfin fetching logic goes here
        // Make sure $item array is built as before
        // Example placeholder:
        $item = [
            'title' => 'Example Title',
            'subtitle' => '',
            'overview' => '',
            'year' => '',
            'rating' => '',
            'runtime' => '',
            'genres' => '',
            'director' => '',
            'studio' => '',
            'contentRating' => '',
            'poster' => '',
            'season' => '',
            'episode' => '',
            'videoCodec' => '',
            'audioCodec' => '',
            'audioChannels' => '',
            'resolution' => '',
            'container' => '',
            'path' => ''
        ];

    // ----------------------------
    // Plex
    // ----------------------------
    } else {
        // Your existing Plex logic goes here
        // Make sure $item array is built as before
    }

    // ----------------------------
    // Output JSON safely
    // ----------------------------
    echo json_encode(['success' => true, 'item' => $item]);

} catch (Exception $e) {
    // Return any exception as JSON
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// Flush buffer and send JSON to browser
ob_end_flush();
?>
