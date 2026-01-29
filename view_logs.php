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
    let lastContent = '';

    function formatLine(line) {
        if (!line.trim()) return '';
        // Parse standard format: [TIMESTAMP] [LEVEL] Message
        const match = line.match(/^\[(.*?)\] \[(.*?)\] (.*)$/);
        if (match) {
            const [_, ts, level, msg] = match;
            return `<div class="log-line"><span class="timestamp">${ts}</span><span class="level-${level}">[${level}]</span> ${esc(msg)}</div>`;
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
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;

                const lines = text.split('\n');
                container.innerHTML = lines.map(formatLine).join('');

                lastContent = text;

                if (autoscrollCb.checked && (isScrolledToBottom || container.scrollTop === 0)) {
                    container.scrollTop = container.scrollHeight;
                }
            }
        } catch (e) {
            console.error('Failed to fetch logs', e);
        }
    }

    function clearLogs() {
        container.innerHTML = '';
        lastContent = '';
    }

    // Initial fetch
    fetchLogs();

    // Poll every 2 seconds
    setInterval(fetchLogs, 2000);
</script>
</body>
</html>
