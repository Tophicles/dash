<?php
require_once 'auth.php';
requireAdmin();

// AJAX request to fetch logs
if (isset($_GET['fetch'])) {
    header('Content-Type: text/plain');
    $logFile = 'dashboard.log';
    if (file_exists($logFile)) {
        // Efficiently read last 20KB
        $fp = fopen($logFile, 'r');
        if ($fp) {
            $size = filesize($logFile);
            $chunkSize = 20480; // 20KB
            if ($size > $chunkSize) {
                fseek($fp, -$chunkSize, SEEK_END);
                // Discard partial line
                fgets($fp);
            }
            while (!feof($fp)) {
                echo fgets($fp);
            }
            fclose($fp);
        }
    } else {
        echo "No logs found.";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Logs - Media Dashboard</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
<style>
    body { font-family: monospace; overflow: hidden; background: var(--bg); color: var(--text); }
    .header {
        padding: 10px 20px;
        background: var(--card);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 50px;
        box-sizing: border-box;
    }
    .title { font-weight: bold; font-size: 1.1rem; }
    .controls { display: flex; gap: 15px; align-items: center; }

    /* Override inputs for logs specifically if needed, but inheriting style.css is better */
    input[type="text"], select {
        padding: 5px 8px;
    }

    #log-container {
        padding: 20px;
        height: calc(100vh - 50px);
        overflow-y: auto;
        box-sizing: border-box;
        white-space: pre-wrap;
        word-wrap: break-word;
        font-size: 0.9rem;
        line-height: 1.4;
        color: var(--text);
    }
    .log-line { margin-bottom: 2px; }
    .log-line:hover { background: var(--bg-hover); }

    /* Syntax highlighting - Theme Aware */
    .level-INFO { color: #81c784; }
    [data-theme="light"] .level-INFO { color: #2e7d32; }

    .level-WARN { color: #ffb74d; }
    [data-theme="light"] .level-WARN { color: #ef6c00; }

    .level-ERROR { color: #e57373; font-weight: bold; }
    [data-theme="light"] .level-ERROR { color: #c62828; }

    .level-AUTH { color: #64b5f6; }
    [data-theme="light"] .level-AUTH { color: #1565c0; }

    .timestamp { color: var(--muted); margin-right: 10px; }
</style>
<script>
    // Apply theme immediately
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
</script>
</head>
<body>

<div class="header">
    <div class="title">System Logs</div>
    <div class="controls">
        <input type="text" id="search" placeholder="Search..." oninput="renderLogs()">
        <select id="level-filter" onchange="renderLogs()">
            <option value="">All Levels</option>
            <option value="INFO">INFO</option>
            <option value="WARN">WARN</option>
            <option value="ERROR">ERROR</option>
            <option value="AUTH">AUTH</option>
        </select>
        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px; cursor: pointer;">
            <input type="checkbox" id="autoscroll" checked> Auto-scroll
        </label>
        <button class="btn" onclick="clearLogs()">Clear View</button>
        <button class="btn" onclick="window.close()">Close</button>
    </div>
</div>

<div id="log-container">Loading logs...</div>

<script>
    const container = document.getElementById('log-container');
    const autoscrollCb = document.getElementById('autoscroll');
    const searchInput = document.getElementById('search');
    const levelSelect = document.getElementById('level-filter');
    let allLogLines = [];
    let lastContent = '';

    function formatLine(line) {
        if (!line.trim()) return '';
        // Parse standard format: [TIMESTAMP] [LEVEL] Message
        const match = line.match(/^\[(.*?)\] \[(.*?)\] (.*)$/);
        if (match) {
            const [_, ts, level, msg] = match;
            return `<div class="log-line" data-level="${level}"><span class="timestamp">${ts}</span><span class="level-${level}">[${level}]</span> ${esc(msg)}</div>`;
        }
        return `<div class="log-line">${esc(line)}</div>`;
    }

    function esc(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    async function fetchLogs() {
        try {
            const res = await fetch('view_logs.php?fetch=1');
            const text = await res.text();

            if (text !== lastContent) {
                lastContent = text;
                allLogLines = text.split('\n').filter(line => line.trim() !== '');
                renderLogs();
            }
        } catch (e) {
            console.error('Failed to fetch logs', e);
        }
    }

    function renderLogs() {
        const searchTerm = searchInput.value.toLowerCase();
        const levelFilter = levelSelect.value;
        const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;

        const filteredLines = allLogLines.filter(line => {
            // Level Filter
            if (levelFilter) {
                const match = line.match(/^\[.*?\] \[(.*?)\]/);
                if (!match || match[1] !== levelFilter) return false;
            }

            // Search Filter
            if (searchTerm && !line.toLowerCase().includes(searchTerm)) {
                return false;
            }

            return true;
        });

        container.innerHTML = filteredLines.map(formatLine).join('');

        if (autoscrollCb.checked && (isScrolledToBottom || container.scrollTop === 0)) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function clearLogs() {
        container.innerHTML = '';
        lastContent = '';
        allLogLines = [];
    }

    // Initial fetch
    fetchLogs();

    // Poll every 2 seconds
    setInterval(fetchLogs, 2000);
</script>
</body>
</html>
