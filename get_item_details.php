<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

header('Content-Type: application/json');

$serverName = $_GET['server'] ?? '';
$itemId = $_GET['itemId'] ?? '';

if (empty($serverName) || empty($itemId)) {
    echo json_encode(['success' => false, 'error' => 'Missing server or itemId']);
    exit;
}

// Load server configuration
$serversFile = __DIR__ . '/servers.json';
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

try {
    if ($server['type'] === 'emby' || $server['type'] === 'jellyfin') {
        // Emby API call - Get data from Sessions endpoint instead
        $sessionsUrl = $baseUrl . '/emby/Sessions?api_key=' . $server['apiKey'];
        
        $ch = curl_init($sessionsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        
        $sessionsResponse = curl_exec($ch);
        curl_close($ch);
        
        $sessions = json_decode($sessionsResponse, true);
        $data = null;
        
        // Find the session with this item and use NowPlayingItem data directly
        foreach ($sessions as $session) {
            if (isset($session['NowPlayingItem']['Id']) && $session['NowPlayingItem']['Id'] === $itemId) {
                $data = $session['NowPlayingItem'];
                break;
            }
        }
        
        if (!$data) {
            echo json_encode(['success' => false, 'error' => 'Item not found in active sessions']);
            exit;
        }
        
        // For Emby, the session NowPlayingItem doesn't have full metadata
        // For TV shows: get metadata from the Series level
        // For movies: get metadata from the item itself
        
        $metadataUrl = null;
        
        // For TV shows, try to get series metadata
        // For movies, the session data should have everything we need
        if (isset($data['SeriesId']) && !empty($data['SeriesId'])) {
            // TV Show - try multiple endpoints
            $endpoints = [
                '/Shows/' . urlencode($data['SeriesId']),
                '/emby/Shows/' . urlencode($data['SeriesId']),
                '/Items/' . urlencode($data['SeriesId']),
                '/emby/Items/' . urlencode($data['SeriesId'])
            ];
            
            foreach ($endpoints as $endpoint) {
                $metadataUrl = $baseUrl . $endpoint . '?api_key=' . $server['apiKey'];
                error_log("Trying Emby endpoint: " . $metadataUrl);
                
                $ch = curl_init($metadataUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'X-Emby-Token: ' . $server['apiKey'],
                    'X-MediaBrowser-Token: ' . $server['apiKey']
                ]);
                
                $metadataResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    error_log("Success with endpoint: " . $endpoint);
                    $metadataData = json_decode($metadataResponse, true);
                    if ($metadataData) {
                        if (isset($metadataData['Genres'])) $data['Genres'] = $metadataData['Genres'];
                        if (isset($metadataData['Studios'])) $data['Studios'] = $metadataData['Studios'];
                        if (isset($metadataData['People'])) $data['People'] = $metadataData['People'];
                        if (isset($metadataData['OfficialRating'])) $data['OfficialRating'] = $metadataData['OfficialRating'];
                    }
                    break;
                }
            }
        }
        
        // Build item details
        $item = [
            'title' => $data['Name'] ?? 'Unknown',
            'subtitle' => $data['SeriesName'] ?? '',
            'overview' => $data['Overview'] ?? '',
            'year' => $data['ProductionYear'] ?? '',
            'rating' => isset($data['CommunityRating']) ? number_format($data['CommunityRating'], 1) : '',
            'runtime' => isset($data['RunTimeTicks']) ? formatRuntime($data['RunTimeTicks'] / 10000000 / 60) : '',
            'genres' => '',
            'director' => '',
            'studio' => '',
            'contentRating' => $data['OfficialRating'] ?? '',
            'poster' => '',
            'season' => $data['ParentIndexNumber'] ?? '',
            'episode' => $data['IndexNumber'] ?? '',
            'videoCodec' => '',
            'audioCodec' => '',
            'audioChannels' => '',
            'resolution' => '',
            'container' => '',
            'path' => ''
        ];
        
        // Get genres - try Genres array first, then GenreItems
        if (isset($data['Genres']) && is_array($data['Genres']) && count($data['Genres']) > 0) {
            $item['genres'] = implode(', ', $data['Genres']);
        } elseif (isset($data['GenreItems']) && is_array($data['GenreItems']) && count($data['GenreItems']) > 0) {
            // GenreItems is an array of objects with 'Name' field
            $genres = array_map(function($g) { return $g['Name'] ?? ''; }, $data['GenreItems']);
            $item['genres'] = implode(', ', array_filter($genres));
        }
        
        // Get studio (it's an array of objects in Emby)
        if (isset($data['Studios']) && is_array($data['Studios']) && count($data['Studios']) > 0) {
            if (isset($data['Studios'][0]['Name'])) {
                $item['studio'] = $data['Studios'][0]['Name'];
            } elseif (is_string($data['Studios'][0])) {
                $item['studio'] = $data['Studios'][0];
            }
        }
        
        // Get director from People
        if (isset($data['People']) && is_array($data['People'])) {
            foreach ($data['People'] as $person) {
                if (isset($person['Type']) && $person['Type'] === 'Director') {
                    $item['director'] = $person['Name'];
                    break;
                }
            }
        }
        
        // Get poster image - use relative URL to avoid mixed content
        // For TV episodes, use the series poster instead of episode thumbnail
        $posterItemId = $itemId;
        if ($data['Type'] === 'Episode' && isset($data['SeriesId'])) {
            $posterItemId = $data['SeriesId'];
        }
        
        if (isset($data['ImageTags']['Primary']) || ($data['Type'] === 'Episode' && isset($data['SeriesId']))) {
            $item['poster'] = 'get_image.php?server=' . urlencode($serverName) . '&itemId=' . urlencode($posterItemId) . '&type=Primary';
        }

        // Extract File Info (Path, Codecs)
        if (isset($data['Path'])) {
             $item['path'] = $data['Path'];
        } elseif (isset($data['MediaSources'][0]['Path'])) {
             $item['path'] = $data['MediaSources'][0]['Path'];
        }

        if (isset($data['Container'])) {
             $item['container'] = $data['Container'];
        } elseif (isset($data['MediaSources'][0]['Container'])) {
             $item['container'] = $data['MediaSources'][0]['Container'];
        }

        $streams = $data['MediaStreams'] ?? ($data['MediaSources'][0]['MediaStreams'] ?? []);
        if (!empty($streams)) {
            foreach ($streams as $stream) {
                if (($stream['Type'] ?? '') === 'Video') {
                    $item['videoCodec'] = $stream['Codec'] ?? '';
                    if (isset($stream['Width']) && isset($stream['Height'])) {
                        $item['resolution'] = $stream['Width'] . 'x' . $stream['Height'];
                    }
                } elseif (($stream['Type'] ?? '') === 'Audio') {
                     if (empty($item['audioCodec']) || ($stream['IsDefault'] ?? false)) {
                        $item['audioCodec'] = $stream['Codec'] ?? '';
                        $item['audioChannels'] = $stream['Channels'] ?? '';
                     }
                }
            }
        }
        
    } else {
        // Plex API call
        $url = $baseUrl . '/library/metadata/' . urlencode($itemId);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
            'rating' => isset($metadata['rating']) ? number_format($metadata['rating'], 1) : '',
            'runtime' => isset($metadata['duration']) ? formatRuntime($metadata['duration'] / 1000 / 60) : '',
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
            $item['resolution'] = $media['videoResolution'] ?? '';
            $item['container'] = $media['container'] ?? '';

            if (isset($media['Part'][0]['file'])) {
                $item['path'] = $media['Part'][0]['file'];
            }
        }
    }
    
    echo json_encode(['success' => true, 'item' => $item]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function formatRuntime($minutes) {
    $hours = floor($minutes / 60);
    $mins = round($minutes % 60);
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
}
?>