<?php
// Start output buffering immediately to capture any accidental output
ob_start();

// Disable display_errors to ensure JSON output is not corrupted by warnings
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// Always return JSON header
header('Content-Type: application/json');

try {
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

    $baseUrl = rtrim(ensureProtocol($server['url']), '/');

    $item = []; // initialize empty item array

    // ----------------------------
    // Emby / Jellyfin
    // ----------------------------
    if ($server['type'] === 'emby' || $server['type'] === 'jellyfin') {
        $url = $baseUrl . '/Items/' . urlencode($itemId) . '?Fields=People,Overview,MediaSources,Studios,OfficialRating,Genres,ProductionYear,RunTimeTicks,Path';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_ENCODING, ""); // Handle gzip/deflate automatically
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $apiKey = $server['apiKey'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Emby-Token: $apiKey",
            "X-MediaBrowser-Token: $apiKey",
            "Accept: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => "Failed to fetch from {$server['type']} (HTTP $httpCode)"]);
            exit;
        }

        $data = json_decode($response, true);

        if (!$data) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON response']);
            exit;
        }

        $item = [
            'title' => $data['Name'] ?? 'Unknown',
            'subtitle' => $data['SeriesName'] ?? '',
            'overview' => $data['Overview'] ?? '',
            'year' => $data['ProductionYear'] ?? '',
            'rating' => isset($data['CommunityRating']) ? number_format((float)$data['CommunityRating'], 1) : '',
            'runtime' => isset($data['RunTimeTicks']) ? formatRuntime((float)$data['RunTimeTicks'] / 10000000 / 60) : '',
            'genres' => isset($data['Genres']) ? implode(', ', $data['Genres']) : '',
            'director' => '',
            'studio' => isset($data['Studios'][0]['Name']) ? $data['Studios'][0]['Name'] : '',
            'contentRating' => $data['OfficialRating'] ?? '',
            'poster' => 'get_image.php?server=' . urlencode($serverName) . '&itemId=' . urlencode($itemId) . '&type=Primary',
            'season' => $data['ParentIndexNumber'] ?? '',
            'episode' => $data['IndexNumber'] ?? '',
            'videoCodec' => '',
            'audioCodec' => '',
            'audioChannels' => '',
            'resolution' => '',
            'container' => $data['Container'] ?? '',
            'path' => $data['Path'] ?? ''
        ];

        // Director
        if (isset($data['People'])) {
            $directors = [];
            foreach ($data['People'] as $person) {
                if (($person['Type'] ?? '') === 'Director') {
                    $directors[] = $person['Name'];
                }
            }
            $item['director'] = implode(', ', $directors);
        }

        // Tech Info
        if (isset($data['MediaSources'][0]['MediaStreams'])) {
            foreach ($data['MediaSources'][0]['MediaStreams'] as $stream) {
                if (($stream['Type'] ?? '') === 'Video') {
                    $item['videoCodec'] = $stream['Codec'] ?? '';
                    if (isset($stream['Width']) && isset($stream['Height'])) {
                        $item['resolution'] = $stream['Width'] . 'x' . $stream['Height'];
                    }
                } elseif (($stream['Type'] ?? '') === 'Audio') {
                    // Capture first audio stream
                    if (empty($item['audioCodec'])) {
                        $item['audioCodec'] = $stream['Codec'] ?? '';
                        $item['audioChannels'] = $stream['Channels'] ?? '';
                    }
                }
            }
        }

    // ----------------------------
    // Plex
    // ----------------------------
    } else {
        // Plex API call
        $url = $baseUrl . '/library/metadata/' . urlencode($itemId);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'X-Plex-Token: ' . $server['token']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => 'Failed to fetch from Plex']);
            exit;
        }
        
        $data = json_decode($response, true);
        $metadata = $data['MediaContainer']['Metadata'][0] ?? null;
        
        if (!$metadata) {
            echo json_encode(['success' => false, 'error' => 'No metadata found']);
            exit;
        }
        
        // Build item details
        $item = [
            'title' => $metadata['title'] ?? 'Unknown',
            'subtitle' => $metadata['grandparentTitle'] ?? '',
            'overview' => $metadata['summary'] ?? '',
            'year' => $metadata['year'] ?? '',
            'rating' => isset($metadata['rating']) ? number_format((float)$metadata['rating'], 1) : '',
            'runtime' => isset($metadata['duration']) ? formatRuntime((float)$metadata['duration'] / 1000 / 60) : '',
            'genres' => '',
            'director' => '',
            'studio' => $metadata['studio'] ?? '',
            'contentRating' => $metadata['contentRating'] ?? '',
            'poster' => '',
            'season' => $metadata['parentIndex'] ?? '',
            'episode' => $metadata['index'] ?? '',
            'videoCodec' => '',
            'audioCodec' => '',
            'audioChannels' => '',
            'resolution' => '',
            'container' => '',
            'path' => ''
        ];
        
        // Get genres
        if (isset($metadata['Genre'])) {
            $genres = array_map(function($g) { return $g['tag']; }, $metadata['Genre']);
            $item['genres'] = implode(', ', $genres);
        }
        
        // Get director
        if (isset($metadata['Director'])) {
            $directors = array_map(function($d) { return $d['tag']; }, $metadata['Director']);
            $item['director'] = implode(', ', $directors);
        }
        
        // Get poster image - use relative URL to avoid mixed content
        // For TV episodes, use the series poster (grandparentThumb) instead of episode thumbnail
        $posterPath = null;
        if ($metadata['type'] === 'episode' && isset($metadata['grandparentThumb'])) {
            // Use series poster for TV episodes
            $posterPath = $metadata['grandparentThumb'];
        } elseif (isset($metadata['thumb'])) {
            $posterPath = $metadata['thumb'];
        }
        
        if ($posterPath) {
            $item['poster'] = 'get_image.php?server=' . urlencode($serverName) . '&path=' . urlencode($posterPath);
        }

        // Extract Media Info
        if (isset($metadata['Media'][0])) {
            $media = $metadata['Media'][0];
            $item['videoCodec'] = $media['videoCodec'] ?? '';
            $item['audioCodec'] = $media['audioCodec'] ?? '';
            $item['audioChannels'] = $media['audioChannels'] ?? '';

            // Prefer exact dimensions if available
            if (isset($media['width']) && isset($media['height'])) {
                $item['resolution'] = $media['width'] . 'x' . $media['height'];
            } else {
                $item['resolution'] = $media['videoResolution'] ?? '';
            }

            $item['container'] = $media['container'] ?? '';

            if (isset($media['Part'][0]['file'])) {
                $item['path'] = $media['Part'][0]['file'];
            }
        }
    }

    // ----------------------------
    // Output JSON safely
    // ----------------------------
    $json = json_encode(['success' => true, 'item' => $item], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    if ($json === false) {
        throw new Exception("JSON Encoding Error: " . json_last_error_msg());
    }

    echo $json;

} catch (Exception $e) {
    // Return any exception as JSON
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
