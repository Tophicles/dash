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
<style>
    body { margin: 0; background: #0f0f0f; color: #eee; font-family: monospace; overflow: hidden; }
    .header {
        padding: 10px 20px;
        background: #1b1b1b;
        border-bottom: 1px solid #333;
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 50px;
        box-sizing: border-box;
    }
    .title { font-weight: bold; font-size: 1.1rem; }
    .controls { display: flex; gap: 15px; align-items: center; }
    .btn {
        background: #333;
        color: #eee;
        border: 1px solid #555;
        padding: 5px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85rem;
    }
    .btn:hover { background: #444; }
    .btn.active { background: #4caf50; border-color: #4caf50; color: white; }
    input[type="text"], select {
        background: #333;
        color: #eee;
        border: 1px solid #555;
        padding: 5px 8px;
        border-radius: 4px;
        font-size: 0.85rem;
    }
    input[type="text"]:focus, select:focus { outline: none; border-color: #4caf50; }
    #log-container {
        padding: 20px;
        height: calc(100vh - 50px);
        overflow-y: auto;
        box-sizing: border-box;
        white-space: pre-wrap;
        word-wrap: break-word;
        font-size: 0.9rem;
        line-height: 1.4;
        color: #a5d6a7; /* Terminal green */
    }
    .log-line { margin-bottom: 2px; }
    .log-line:hover { background: rgba(255,255,255,0.05); }
    /* Syntax highlighting */
    .level-INFO { color: #81c784; }
    .level-WARN { color: #ffb74d; }
    .level-ERROR { color: #e57373; font-weight: bold; }
    .level-AUTH { color: #64b5f6; }
    .timestamp { color: #666; margin-right: 10px; }
</style>
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
