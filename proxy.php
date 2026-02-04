<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'logging.php';
require_once 'ssh_helper.php';
requireLogin(false); // Do not update activity on polling

// Close session to prevent locking while waiting for external APIs
session_write_close();

header('Content-Type: application/json');

$serverName = $_GET['server'] ?? '';
$serverId = $_GET['id'] ?? '';
$servers = json_decode(file_get_contents(DB_DIR . 'servers.json'), true)['servers'];

$server = [];
if ($serverId) {
    $server = array_filter($servers, fn($s) => (string)($s['id'] ?? '') === (string)$serverId);
} elseif ($serverName) {
    $server = array_filter($servers, fn($s) => $s['name'] === $serverName);
}

if (!$server) { echo json_encode([]); exit; }

$server = array_values($server)[0];
$action = $_GET['action'] ?? 'sessions';

// Handle SSH Actions globally (for any server type)
if (in_array($action, ['ssh_restart', 'ssh_stop', 'ssh_start', 'ssh_status', 'ssh_system_stats', 'ssh_update', 'ssh_update_log'])) {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Verify OS
    $os = $server['os_type'] ?? 'linux';
    if ($os !== 'linux') {
        echo json_encode(['success' => false, 'error' => 'SSH actions only supported on Linux']);
        exit;
    }

    // Derive Host from URL
    $parsed = parse_url($server['url']);
    $host = $parsed['host'] ?? '';
    if (!$host) {
        echo json_encode(['success' => false, 'error' => 'Could not determine host from server URL']);
        exit;
    }

    // Settings
    $port = $server['ssh_port'] ?: 22;
    $user = 'mediasvc';

    // Determine Service Name based on Type and OS
    $service = '';
    $type = $server['type'] ?? '';
    $processName = '';

    if ($os === 'linux') {
        if ($type === 'plex') { $service = 'plexmediaserver'; $processName = 'Plex Media Server'; }
        else if ($type === 'emby') { $service = 'emby-server'; $processName = 'EmbyServer'; }
        else if ($type === 'jellyfin') { $service = 'jellyfin'; $processName = 'jellyfin'; }
    }

    if (!$service) {
         echo json_encode(['success' => false, 'error' => 'Unknown server type/OS for service control']);
         exit;
    }

    // Determine Action
    $cmd = "";

    if ($os === 'linux') {
        // --- Linux Commands ---
        if ($action === 'ssh_restart') {
            $cmd = "nohup sudo systemctl restart $service > /dev/null 2>&1 &";
        } elseif ($action === 'ssh_stop') {
            $cmd = "nohup sudo systemctl stop $service > /dev/null 2>&1 &";
        } elseif ($action === 'ssh_start') {
            $cmd = "nohup sudo systemctl start $service > /dev/null 2>&1 &";
        } elseif ($action === 'ssh_status') {
            $cmd = "systemctl is-active $service || true";
        } elseif ($action === 'ssh_system_stats') {
            // Echo OS type first
            $cmd = "echo 'OS: Linux'; echo '---'; " .
                   "cat /proc/uptime; echo '---'; " .
                   "cat /proc/loadavg; echo '---'; " .
                   "free -b; echo '---'; " .
                   "cat /proc/net/dev; echo '---'; " .
                   "grep 'cpu ' /proc/stat; echo '---'; " .
                   "pid=$(pgrep -f '$processName' | head -n1); if [ -n \"\$pid\" ]; then ps -o rss,time,thcount --no-headers -p \$pid; else echo '0 0 0'; fi; echo '---'; " .
                   "sleep 1; echo '---'; " .
                   "cat /proc/net/dev; echo '---'; " .
                   "grep 'cpu ' /proc/stat";
        }
    }

    // Default timeout
    $timeout = 10;
    if ($action === 'ssh_agent_logs') $timeout = 15;

    if ($action === 'ssh_update') {
        $logFile = "/home/mediasvc/multidash_update_{$server['id']}.log";

        // Check allowed sudo paths for update file
        $sudoCheckCmd = "sudo -l";
        $sudoCheckRes = executeSSHCommand($host, $port, $user, $sudoCheckCmd, 5);

        $tmpDeb = "/home/mediasvc/multidash_update.deb"; // Default secure path

        if ($sudoCheckRes['success']) {
             if (strpos($sudoCheckRes['output'], '/tmp/multidash_update.deb') !== false) {
                 $tmpDeb = "/tmp/multidash_update.deb";
             }
        }

        // 1. Detect Architecture (via SSH sync)
        $archCmd = "uname -m";
        $archRes = executeSSHCommand($host, $port, $user, $archCmd);

        // Sanitize output (extract last valid arch from output)
        $archRaw = $archRes['success'] ? trim($archRes['output']) : 'x86_64';
        $arch = 'amd64'; // Default fallback

        if (preg_match('/(x86_64|aarch64|armv7l|i686|amd64|arm64)$/m', $archRaw, $matches)) {
            $arch = $matches[1];
        }

        // Normalize Arch
        if ($arch === 'x86_64') $arch = 'amd64';
        if ($arch === 'aarch64') $arch = 'arm64';

        // 2. Resolve URL
        $downloadUrl = '';
        $branch = $_GET['branch'] ?? 'stable';

        if ($server['type'] === 'plex') {
            $token = isset($server['token']) ? decrypt($server['token']) : '';
            $downloadUrl = getPlexDownloadUrl($server, $token, $arch, $branch);
        } elseif ($server['type'] === 'emby') {
            $downloadUrl = getEmbyDownloadUrl($arch, $branch);
        } elseif ($server['type'] === 'jellyfin') {
            $downloadUrl = getJellyfinDownloadUrl($arch, $branch);
        }

        if (!$downloadUrl) {
            echo json_encode(['success' => false, 'error' => 'Could not resolve download URL']);
            exit;
        }

        // 3. Construct Command
        // We use curl -L to follow redirects, -o to save.
        // Then sudo dpkg -i
        // Then rm

        // Use bash -c with arguments to avoid quoting issues
        // $1 = Download URL, $2 = Log File, $3 = Temp Deb, $4 = Architecture

        $script =
            "echo \"Starting update for $service...\" > \"$2\"; " .
            "echo \"Architecture: $4\" >> \"$2\"; " .
            "echo \"Downloading from: $1\" >> \"$2\"; " .
            "rm -f \"$3\"; " .
            "curl -L -s -S -A \"Mozilla/5.0\" \"$1\" -o \"$3\" >> \"$2\" 2>&1; " .
            "if [ $? -eq 0 ]; then " .
            "  echo \"Download complete. Installing...\" >> \"$2\"; " .
            "  sudo dpkg -i \"$3\" >> \"$2\" 2>&1; " .
            "  if [ $? -eq 0 ]; then " .
            "    echo \"UPDATE_COMPLETE\" >> \"$2\"; " .
            "    rm \"$3\"; " .
            "  else " .
            "    echo \"Install failed.\" >> \"$2\"; " .
            "    echo \"UPDATE_FAILED\" >> \"$2\"; " .
            "  fi; " .
            "else " .
            "  echo \"Download failed.\" >> \"$2\"; " .
            "  echo \"UPDATE_FAILED\" >> \"$2\"; " .
            "fi";

        $cmd = "nohup bash -c " . escapeshellarg($script) . " -- " .
               escapeshellarg($downloadUrl) . " " .
               escapeshellarg($logFile) . " " .
               escapeshellarg($tmpDeb) . " " .
               escapeshellarg($arch) .
               " > /dev/null 2>&1 &";

    } elseif ($action === 'ssh_update_log') {
        $logFile = "/home/mediasvc/multidash_update_{$server['id']}.log";
        // Check if file exists first to avoid error spam
        $cmd = "if [ -f $logFile ]; then cat $logFile; else echo \"Waiting for log...\"; fi";
    }

    if (!$cmd) {
        echo json_encode(['success' => false, 'error' => 'Invalid SSH action']);
        exit;
    }

    // Execute SSH
    $result = executeSSHCommand($host, $port, $user, $cmd, $timeout);

    if ($result['success']) {
        if ($action === 'ssh_status') {
            // Get last line of output to avoid SSH banners/warnings
            $lines = explode("\n", trim($result['output']));
            $status = end($lines);
            echo json_encode(['success' => true, 'status' => $status]);
        } elseif ($action === 'ssh_system_stats') {
            echo json_encode(['success' => true, 'output' => trim($result['output'])]);
        } else {
            writeLog("SSH command '$action' sent to {$server['name']} ($host)", "INFO");
            echo json_encode($result);
        }
    } else {
        writeLog("SSH command '$action' failed for {$server['name']}: {$result['error']}", "ERROR");
        echo json_encode($result);
    }
    exit;
}

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
            $currentVer = $response['version'];
            $simulatedLatest = "9.9.9.9";
            $simulatedChannel = "Beta";

            writeLog("[TEST] Starting simulated update check for {$server['name']}", "INFO");
            writeLog("[TEST] Current Version: {$currentVer}", "INFO");
            writeLog("[TEST] Update Channel: {$simulatedChannel} (Simulated)", "INFO");
            writeLog("[TEST] Latest Version: {$simulatedLatest} (Simulated)", "INFO");
            writeLog("[TEST] Update Required: Yes", "INFO");

            $response['updateAvailable'] = true;
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

    if ($action === 'restart') {
        $url = rtrim($baseUrl, '/') . '/System/Restart';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Emby-Token: $apiKey",
            "X-MediaBrowser-Token: $apiKey"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204 || $httpCode === 200) {
            writeLog("Restart command sent to {$server['name']}", "INFO");
            echo json_encode(['success' => true]);
        } else {
            writeLog("Restart failed for {$server['name']}: HTTP $httpCode", "ERROR");
            echo json_encode(['success' => false, 'error' => "HTTP $httpCode"]);
        }
        exit;
    }

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
            $currentVer = $response['version'];
            $simulatedLatest = "9.9.9.9";
            $simulatedChannel = "Stable";

            writeLog("[TEST] Starting simulated update check for {$server['name']}", "INFO");
            writeLog("[TEST] Current Version: {$currentVer}", "INFO");
            writeLog("[TEST] Update Channel: {$simulatedChannel} (Simulated)", "INFO");
            writeLog("[TEST] Latest Version: {$simulatedLatest} (Simulated)", "INFO");
            writeLog("[TEST] Update Required: Yes", "INFO");

            $response['updateAvailable'] = true;
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
    $stateFile = DB_DIR . 'watcher_state.json';
    $state = [];
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true) ?: [];
    } else {
        // Auto-recover if missing
        file_put_contents($stateFile, '[]');
        @chmod($stateFile, 0666);
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

// Update URL Resolvers

function getPlexDownloadUrl($server, $token, $arch, $branch) {
    // Always use API to ensure correct version resolution (especially for Plex Pass)
    // Channel 8 = Plex Pass (Beta), Channel 16 = Public (Stable)

    $channel = ($branch === 'stable') ? '16' : '8';

    // Map Arch
    $build = 'linux-x86_64';
    if ($arch === 'arm64') $build = 'linux-aarch64';

    return "https://plex.tv/downloads/latest/5?channel=$channel&build=$build&distro=debian&X-Plex-Token=$token";
}

function getEmbyDownloadUrl($arch, $branch) {
    // 1. Get version from GitHub API
    $url = "https://api.github.com/repos/MediaBrowser/Emby.Releases/releases";
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: MultiDash-Updater\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);

    if (!$res) return null;
    $releases = json_decode($res, true);
    if (!is_array($releases)) return null;

    $downloadUrl = '';
    foreach ($releases as $release) {
        if ($branch === 'stable' && !empty($release['prerelease'])) continue;

        // Find asset in this release
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                // Match pattern: emby-server-deb_4.9.3.0_amd64.deb
                // Note: Arch can be amd64 or arm64
                if (preg_match("/emby-server-deb_.*_{$arch}\.deb$/i", $asset['name'])) {
                    $downloadUrl = $asset['browser_download_url'];
                    break 2; // Found it, break both loops
                }
            }
        }
    }

    if (!$downloadUrl) return null;

    return $downloadUrl;
}

function getJellyfinDownloadUrl($arch, $branch) {
    // Scrape Jellyfin Repo Directory
    $repoType = ($branch === 'beta') ? 'unstable' : 'latest-stable';
    $baseUrl = "https://repo.jellyfin.org/files/server/debian/$repoType/$arch/";

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: MultiDash-Updater\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $html = @file_get_contents($baseUrl, false, $context);

    if (!$html) return null;

    // Regex to find server package
    // Looking for: jellyfin-server_10.11.6+deb12_amd64.deb
    // We prefer higher versions. Since parsing versions is hard, we'll assume the list is sorted or we grab them all and sort.
    // Simpler approach: Match all, pick the last one (apache/nginx directory listings usually sort by name/date).

    preg_match_all('/href="(jellyfin-server_[^"]+_' . $arch . '\.deb)"/i', $html, $matches);

    if (!empty($matches[1])) {
        // Sort natural to ensure higher versions are last
        natsort($matches[1]);
        $latest = end($matches[1]);
        return $baseUrl . $latest;
    }

    return null;
}
?>
