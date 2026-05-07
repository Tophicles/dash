<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
requireLogin();

// Close the session immediately so we don't lock the UI while processing
session_write_close();

// Since migration can take a while, allow script to run indefinitely
set_time_limit(0);
ignore_user_abort(true);

// Completely disable output buffering to ensure real-time streaming
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // For Nginx
header('Connection: keep-alive');

while (ob_get_level() > 0) {
    ob_end_flush();
}

function sendProgress($message, $type = 'info', $percent = null) {
    $data = ['message' => $message, 'type' => $type];
    if ($percent !== null) {
        $data['percent'] = $percent;
    }
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$sourceServerId = $input['sourceServerId'] ?? '';
$sourceUserId = $input['sourceUserId'] ?? '';
$targetServerId = $input['targetServerId'] ?? '';
$targetUserId = $input['targetUserId'] ?? ''; // if empty, we create new user
$newUserName = $input['newUserName'] ?? '';
$newUserPassword = $input['newUserPassword'] ?? '';

if (!$sourceServerId || !$sourceUserId || !$targetServerId) {
    sendProgress("Missing required parameters.", "error");
    exit;
}

if (!$targetUserId && !$newUserName) {
    sendProgress("Must provide target user or new user name.", "error");
    exit;
}

$serversFile = DB_DIR . 'servers.json';
if (!file_exists($serversFile)) {
    sendProgress("Servers configuration not found.", "error");
    exit;
}

$config = json_decode(file_get_contents($serversFile), true);
$servers = $config['servers'] ?? [];

$sourceServer = null;
$targetServer = null;

foreach ($servers as $s) {
    if ((string)$s['id'] === (string)$sourceServerId) {
        $sourceServer = $s;
    }
    if ((string)$s['id'] === (string)$targetServerId) {
        $targetServer = $s;
    }
}

if (!$sourceServer || !$targetServer) {
    sendProgress("Source or target server not found.", "error");
    exit;
}

if ($targetServer['type'] === 'plex') {
    sendProgress("Plex cannot be used as a target server.", "error");
    exit;
}

$targetBaseUrl = rtrim($targetServer['url'], '/');
if (!preg_match('/^https?:\/\//', $targetBaseUrl)) {
    $targetBaseUrl = 'http://' . $targetBaseUrl;
}
$targetApiKey = isset($targetServer['apiKey']) ? decrypt($targetServer['apiKey']) : '';

$sourceBaseUrl = rtrim($sourceServer['url'], '/');
if (!preg_match('/^https?:\/\//', $sourceBaseUrl)) {
    $sourceBaseUrl = 'http://' . $sourceBaseUrl;
}
$sourceType = $sourceServer['type'];
$sourceToken = '';
if ($sourceType === 'plex') {
    $sourceToken = isset($sourceServer['token']) ? decrypt($sourceServer['token']) : '';
} else {
    $sourceToken = isset($sourceServer['apiKey']) ? decrypt($sourceServer['apiKey']) : '';
}

// 1. Handle User Creation if needed
if (!$targetUserId && $newUserName) {
    sendProgress("Creating new user '$newUserName' on target server...", "info");

    $url = "$targetBaseUrl/Users/New";
    $payload = json_encode(['Name' => $newUserName]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: $targetApiKey",
        "X-MediaBrowser-Token: $targetApiKey",
        "Content-Type: application/json",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $res) {
        $newUser = json_decode($res, true);
        $targetUserId = $newUser['Id'] ?? '';
        if (!$targetUserId) {
            sendProgress("Failed to create user. Response: $res", "error");
            exit;
        }
        sendProgress("User '$newUserName' created successfully (ID: $targetUserId).", "success");

        // Set password if provided
        if ($newUserPassword) {
            $urlPw = "$targetBaseUrl/Users/$targetUserId/Password";
            $payloadPw = json_encode(['CurrentPw' => '', 'NewPw' => $newUserPassword]);
            $chPw = curl_init($urlPw);
            curl_setopt($chPw, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chPw, CURLOPT_POST, true);
            curl_setopt($chPw, CURLOPT_POSTFIELDS, $payloadPw);
            curl_setopt($chPw, CURLOPT_HTTPHEADER, [
                "X-Emby-Token: $targetApiKey",
                "X-MediaBrowser-Token: $targetApiKey",
                "Content-Type: application/json",
                "Accept: application/json"
            ]);
            curl_setopt($chPw, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($chPw);
            curl_close($chPw);
            sendProgress("Password set for new user.", "success");
        }
    } else {
        sendProgress("Failed to create user. HTTP Code: $httpCode", "error");
        exit;
    }
} else {
    sendProgress("Mapping to existing user ID: $targetUserId on target server...", "info");
}

// Helper to extract external IDs from Plex item
function getPlexItemGuids($baseUrl, $token, $ratingKey) {
    $url = "$baseUrl/library/metadata/$ratingKey";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Plex-Token: $token", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $guids = [];
    if ($res) {
        $data = json_decode($res, true);
        $metadata = $data['MediaContainer']['Metadata'][0] ?? null;
        if ($metadata && isset($metadata['Guid'])) {
            foreach ($metadata['Guid'] as $g) {
                // e.g. "imdb://tt12345", "tmdb://12345"
                if (preg_match('/^(imdb|tmdb|tvdb):\/\/(.+)$/', $g['id'], $matches)) {
                    $guids[$matches[1]] = $matches[2];
                }
            }
        }
    }
    return $guids;
}

// Helper to fetch target items by external IDs (returning all matches)
function findTargetItemsByGuids($baseUrl, $apiKey, $guids) {
    if (empty($guids)) return [];

    $queryParts = [];
    foreach ($guids as $provider => $id) {
        $queryParts[] = ucfirst($provider) . ".$id";
    }
    $queryString = "AnyProviderIdEquals=" . implode(',', $queryParts);

    // We search recursively to find movies, series, episodes
    $url = "$baseUrl/Items?Recursive=true&$queryString";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: $apiKey",
        "X-MediaBrowser-Token: $apiKey",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $items = [];
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['Items'])) {
            foreach ($data['Items'] as $item) {
                $items[] = $item['Id'];
            }
        }
    }
    return $items;
}

// Helper to update watch state/favorites on target
function updateTargetItemState($baseUrl, $apiKey, $userId, $itemId, $isFavorite, $isWatched, $lastPlayedDate = null, $resumePositionTicks = 0) {
    // 1. Favorite
    if ($isFavorite) {
        $url = "$baseUrl/Users/$userId/FavoriteItems/$itemId";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $apiKey", "X-MediaBrowser-Token: $apiKey", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }

    // 2. Watched State
    if ($isWatched) {
        $url = "$baseUrl/Users/$userId/PlayedItems/$itemId";
        if ($lastPlayedDate) {
            $url .= "?DatePlayed=" . urlencode($lastPlayedDate);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $apiKey", "X-MediaBrowser-Token: $apiKey", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    } elseif ($resumePositionTicks > 0) {
        // Set resume point
        $url = "$baseUrl/Users/$userId/PlayingItems/$itemId/Progress?PositionTicks=$resumePositionTicks";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $apiKey", "X-MediaBrowser-Token: $apiKey", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }
}

// 2. Fetch source items (watched/in-progress/favorites)
sendProgress("Fetching source items...", "info");

$itemsToMigrate = [];

if ($sourceType === 'plex') {
    // Fetch from Plex
    $offset = 0;
    $limit = 1000;
    $totalFetched = 0;
    $plexItemsSeen = [];

    while (true) {
        $url = "$sourceBaseUrl/status/sessions/history/all?accountID=$sourceUserId&sort=viewedAt:desc&container-start=$offset&container-size=$limit";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Plex-Token: $sourceToken", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$res) break;

        $data = json_decode($res, true);
        $metadata = $data['MediaContainer']['Metadata'] ?? [];
        if (empty($metadata)) break;

        foreach ($metadata as $item) {
            $ratingKey = $item['ratingKey'];
            // Plex returns individual watch events. Deduplicate to save API calls
            if (!isset($plexItemsSeen[$ratingKey])) {
                $plexItemsSeen[$ratingKey] = true;
                $itemsToMigrate[] = [
                    'id' => $ratingKey,
                    'title' => $item['title'] ?? 'Unknown',
                    'isWatched' => true,
                    'isFavorite' => false,
                    'lastPlayedDate' => isset($item['viewedAt']) ? date('c', (int)$item['viewedAt']) : null,
                    'resumePositionTicks' => 0
                ];
            }
        }

        $totalFetched += count($metadata);
        sendProgress("Fetched $totalFetched watch events from Plex...", "info");

        if (count($metadata) < $limit) break;
        $offset += $limit;
    }

    // We cannot reliably get Plex user view progress (in-progress) using just the admin token and `accountID`
    // because `/library/onDeck` ignores `accountID`. It would require switching tokens which is complex.
    // So we just stick to history.

} else {
    // Fetch from Emby/Jellyfin
    // Emby/Jellyfin `Filters=A,B` does a logical AND.
    // So we fetch Played, Favorite, and Resumable separately to get an OR effect.
    $embyItemsSeen = [];

    $filtersToFetch = ['IsPlayed', 'IsFavorite', 'IsResumable'];

    foreach ($filtersToFetch as $filter) {
        // We use StartIndex and Limit to paginate just in case
        $offset = 0;
        $limit = 500; // Reduced from 2000 to stream progress faster

        while (true) {
            $url = "$sourceBaseUrl/Users/$sourceUserId/Items?Recursive=true&Fields=UserData,ProviderIds&EnableUserData=true&Filters=$filter&StartIndex=$offset&Limit=$limit";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Emby-Token: $sourceToken", "X-MediaBrowser-Token: $sourceToken", "Accept: application/json"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$res) {
                sendProgress("Error or empty response while fetching $filter items from Emby/Jellyfin source.", "error");
                break;
            }

            $data = json_decode($res, true);
            $items = $data['Items'] ?? [];
            if (empty($items)) break;

            foreach ($items as $item) {
                $itemId = $item['Id'];

                // Avoid adding the same item multiple times if it's both played and favorited
                if (isset($embyItemsSeen[$itemId])) continue;
                $embyItemsSeen[$itemId] = true;

                $userData = $item['UserData'] ?? [];

                $itemsToMigrate[] = [
                    'id' => $itemId,
                    'title' => $item['Name'] ?? 'Unknown',
                    'isWatched' => $userData['Played'] ?? false,
                    'isFavorite' => $userData['IsFavorite'] ?? false,
                    'lastPlayedDate' => $userData['LastPlayedDate'] ?? null,
                    'resumePositionTicks' => $userData['PlaybackPositionTicks'] ?? 0,
                    'providerIds' => $item['ProviderIds'] ?? []
                ];
            }

            if (count($items) < $limit) break;
            $offset += $limit;
        }
    }

    sendProgress("Fetched " . count($itemsToMigrate) . " unique items to migrate from source server.", "info");
}

// 3. Migrate items
$totalItems = count($itemsToMigrate);
if ($totalItems === 0) {
    sendProgress("No items found to migrate.", "success");
    exit;
}

sendProgress("Starting migration of $totalItems items...", "info", 0);

$migratedCount = 0;
$notFoundCount = 0;

foreach ($itemsToMigrate as $i => $item) {
    $title = $item['title'];
    $percent = round((($i + 1) / $totalItems) * 100);

    $guids = [];
    if ($sourceType === 'plex') {
        $guids = getPlexItemGuids($sourceBaseUrl, $sourceToken, $item['id']);
    } else {
        // Translate Emby/Jellyfin ProviderIds
        foreach ($item['providerIds'] as $provider => $id) {
            $guids[strtolower($provider)] = $id;
        }
    }

    if (empty($guids)) {
        // No external IDs, can't map
        $notFoundCount++;
        sendProgress("Skipping '$title' - No external IDs found.", "error", $percent);
        continue;
    }

    $targetItemIds = findTargetItemsByGuids($targetBaseUrl, $targetApiKey, $guids);

    if (!empty($targetItemIds)) {
        foreach ($targetItemIds as $targetItemId) {
            updateTargetItemState(
                $targetBaseUrl,
                $targetApiKey,
                $targetUserId,
                $targetItemId,
                $item['isFavorite'],
                $item['isWatched'],
                $item['lastPlayedDate'],
                $item['resumePositionTicks']
            );
        }
        $migratedCount++;
        sendProgress("Migrated '$title' successfully.", "info", $percent);
    } else {
        $notFoundCount++;
        sendProgress("Skipping '$title' - Not found on destination server.", "error", $percent);
    }
}

sendProgress("Migration complete! Migrated: $migratedCount, Not Found/Skipped: $notFoundCount.", "success", 100);
exit;
