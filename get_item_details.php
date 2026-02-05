<?php
// ----------------------------
// get_item_details.php PATCH
// ----------------------------

// Start output buffering to capture accidental output
ob_start();

// Disable display errors and suppress warnings/notices
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

    // ----------------------------
    // Fetch item details
    // ----------------------------
    $item = []; // initialize empty
    if ($server['type'] === 'emby' || $server['type'] === 'jellyfin') {
        // Your existing Emby/Jellyfin logic here
        // ...
        // $item = [...] final item array
    } else {
        // Plex logic
        // ...
        // $item = [...] final item array
    }

    // Output JSON
    echo json_encode(['success' => true, 'item' => $item]);

} catch (Exception $e) {
    // Catch any unexpected errors and return as JSON
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function formatRuntime($minutes) {
    if (!is_numeric($minutes)) return '';
    $minutes = (float)$minutes;
    $hours = floor($minutes / 60);
    $mins = round(fmod($minutes, 60));
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
}
?>
