<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

$serverName = $_GET['server'] ?? '';
$itemId = $_GET['itemId'] ?? '';
$type = $_GET['type'] ?? 'Primary';
$path = $_GET['path'] ?? ''; // For Plex direct paths

if (empty($serverName) || (empty($itemId) && empty($path))) {
    http_response_code(400);
    exit;
}

// Load server configuration
$serversFile = __DIR__ . '/servers.json';
if (!file_exists($serversFile)) {
    http_response_code(404);
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
    http_response_code(404);
    exit;
}

// Decrypt keys before use
if (isset($server['apiKey'])) $server['apiKey'] = decrypt($server['apiKey']);
if (isset($server['token'])) $server['token'] = decrypt($server['token']);

// Ensure URL has protocol
function ensureProtocol($url) {
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        return "http://" . $url;
    }
    return $url;
}

$baseUrl = ensureProtocol($server['url']);

// Build image URL
if ($server['type'] === 'emby') {
    $imageUrl = $baseUrl . '/Items/' . urlencode($itemId) . '/Images/' . $type . '?api_key=' . $server['apiKey'];
} else {
    // For Plex
    if (!empty($path)) {
        // Direct path provided (for series posters)
        $imageUrl = $baseUrl . $path . '?X-Plex-Token=' . $server['token'];
    } else {
        // Need to fetch metadata to get thumb path
        $metadataUrl = $baseUrl . '/library/metadata/' . urlencode($itemId);
        
        $ch = curl_init($metadataUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'X-Plex-Token: ' . $server['token']
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        $metadata = $data['MediaContainer']['Metadata'][0] ?? null;
        
        if (!$metadata || !isset($metadata['thumb'])) {
            http_response_code(404);
            exit;
        }
        
        $imageUrl = $baseUrl . $metadata['thumb'] . '?X-Plex-Token=' . $server['token'];
    }
}

// Fetch and proxy the image
$ch = curl_init($imageUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$imageData = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    http_response_code(404);
    exit;
}

// Output the image with proper headers
header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
header('Content-Length: ' . strlen($imageData));

echo $imageData;
?>