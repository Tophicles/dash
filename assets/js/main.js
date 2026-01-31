// Helper to escape HTML and prevent XSS
function esc(str) {
    if (str === null || str === undefined) return '';
    const temp = document.createElement('div');
    temp.textContent = str;
    return temp.innerHTML.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

let SERVERS = [];
let ALL_SESSIONS = {};
let refreshTimer = null;
let currentView = 'servers'; // 'servers', 'sessions', or 'all'
let selectedServerId = null;
let reorderMode = false;
let showActiveOnly = false;
// const IS_ADMIN = ... (This is defined in index.php)

// Server Modal Logic (admin only)
function updateServerFormFields() {
    const typeSelect = document.getElementById('server-type-select');
    const apiKeyGroup = document.getElementById('group-apikey');
    const tokenGroup = document.getElementById('group-token');
    const urlInput = document.getElementById('server-url-input');
    const osSelect = document.getElementById('server-os-select');
    const sshPortGroup = document.getElementById('ssh-port-group');

    if (!typeSelect || !apiKeyGroup || !tokenGroup) return;

    // Type Logic
    if (typeSelect.value === 'plex') {
        apiKeyGroup.style.display = 'none';
        tokenGroup.style.display = 'flex';
        // Remove required from hidden field to allow submission
        apiKeyGroup.querySelector('input').removeAttribute('required');
        if (urlInput) urlInput.placeholder = "192.168.1.10:32400";
    } else {
        apiKeyGroup.style.display = 'flex';
        tokenGroup.style.display = 'none';
        // Remove required from hidden field
        tokenGroup.querySelector('input').removeAttribute('required');
        if (urlInput) urlInput.placeholder = "192.168.1.10:8096";
    }

    // OS Logic
    if (osSelect && sshPortGroup) {
        if (osSelect.value === 'linux') {
            sshPortGroup.style.display = 'flex';
        } else {
            sshPortGroup.style.display = 'none';
        }
    }
}

// Add event listener for server type change
const serverTypeSelect = document.getElementById('server-type-select');
if (serverTypeSelect) {
    serverTypeSelect.addEventListener('change', updateServerFormFields);
}

const serverOsSelect = document.getElementById('server-os-select');
if (serverOsSelect) {
    serverOsSelect.addEventListener('change', updateServerFormFields);
}

// Input listener for stripping protocol
const urlInput = document.getElementById('server-url-input');
if (urlInput) {
    urlInput.addEventListener('input', function(e) {
        let val = e.target.value;
        if (val.match(/^https?:\/\//)) {
            e.target.value = val.replace(/^https?:\/\//, '');
        }
        // Clear custom validity on input
        e.target.setCustomValidity('');
    });
}

function openServerModal(isEdit = false) {
    const modal = document.getElementById('server-modal');
    const title = document.getElementById('server-modal-title');
    const btn = document.getElementById('server-submit-btn');
    const form = document.getElementById('add-server-form');

    if (isEdit) {
        title.textContent = 'Edit Server';
        btn.textContent = 'Save Changes';
    } else {
        title.textContent = 'Add Server';
        btn.textContent = 'Add Server';
        form.reset();
        delete form.dataset.originalName;
        // Reset visibility for new form
        updateServerFormFields();
    }

    modal.classList.add('visible');
}

function closeServerModal() {
    document.getElementById('server-modal').classList.remove('visible');
    document.getElementById('add-server-form').reset();
}

// Close server modal when clicking outside
document.getElementById('server-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeServerModal();
    }
});

if (IS_ADMIN) {
    document.getElementById('toggle-form').addEventListener('click', function() {
        openServerModal(false);
    });
}

// Show All button (toggleable)
document.getElementById('showall-btn').addEventListener('click', function() {
    if (currentView === 'all') {
        // Currently showing all, go back to server grid
        showServerView();
        this.textContent = 'Show All';
        this.classList.remove('hideall');
        window.scrollTo(0, 0);
    } else {
        // Show all sessions
        showAllSessions();
        this.textContent = 'Hide All';
        this.classList.add('hideall');
    }
});

// Active Only button (toggleable)
document.getElementById('activeonly-btn').addEventListener('click', function() {
    showActiveOnly = !showActiveOnly;
    this.classList.toggle('active');

    // Change button text
    this.textContent = showActiveOnly ? 'All Servers' : 'Active Only';

    // Hide/Show "Show All" button
    const showAllBtn = document.getElementById('showall-btn');
    if (showActiveOnly) {
        showAllBtn.style.display = 'none';
    } else {
        showAllBtn.style.display = '';
    }

    // Re-render server grid with filter applied
    if (currentView === 'servers') {
        renderServerGrid();
    }
});

// Back button
document.getElementById('back-btn').addEventListener('click', function() {
    showServerView();
});

// Load config via PHP to handle decryption and permissions
async function loadConfig() {
    const res = await fetch('get_config.php?_=' + Date.now());
    const config = await res.json();
    SERVERS = config.servers.filter(s=>s.enabled).sort((a,b)=> {
        // First sort by type (emby before plex)
        if (a.type !== b.type) {
            return a.type.localeCompare(b.type);
        }
        // Then sort by order
        return (a.order||0) - (b.order||0);
    });
    return config.refreshSeconds || 5;
}

// Convert ms to H:MM:SS
function msToTime(ms){
    const s = Math.floor(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sec=s%60;
    return (h?h+":":"")+String(m).padStart(2,"0")+":"+String(sec).padStart(2,"0");
}

// Get quality badge based on resolution
function getQualityBadge(width, height) {
    if (height >= 2160 || width >= 3840) return '4K';
    if (height >= 1080 || width >= 1920) return '1080p';
    if (height >= 720 || width >= 1280) return '720p';
    if (height >= 480) return '480p';
    if (height > 0) return height + 'p';
    return '';
}

// Helper to format audio channels
function formatAudioChannels(channels) {
    if (!channels) return '';
    const ch = parseFloat(channels);
    if (ch === 6) return '5.1';
    if (ch === 8) return '7.1';
    if (ch === 2) return '2.0';
    if (ch === 1) return '1.0';
    return channels;
}

// Get play method icon
function getPlayMethodIcon(playMethod) {
    if (!playMethod) return '';
    const method = playMethod.toLowerCase();
    if (method.includes('direct')) return '<i class="fa-solid fa-bolt"></i>'; // Direct play
    if (method.includes('transcode')) return '<i class="fa-solid fa-arrows-rotate"></i>'; // Transcoding
    return '';
}

// Fetch via PHP proxy
async function fetchServer(server){
    try {
        // Add 3-second timeout to prevent slow servers from blocking
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000);

        const res = await fetch(`proxy.php?server=${encodeURIComponent(server.name)}`, {
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        if(!res.ok) return [];
        const data = await res.json();
        if(server.type==="emby" || server.type==="jellyfin"){
            return data.filter(s=>s.NowPlayingItem).map(s=>({
                server: server.name,
                user: s.UserName,
                title: s.NowPlayingItem.Name,
                series: s.NowPlayingItem.SeriesName||"",
                position: s.PlayState.PositionTicks/10000,
                duration: s.NowPlayingItem.RunTimeTicks/10000,
                paused: s.PlayState.IsPaused,
                itemId: s.NowPlayingItem.Id,
                season: s.NowPlayingItem.ParentIndexNumber,
                episode: s.NowPlayingItem.IndexNumber,
                playMethod: s.PlayState.PlayMethod||"",
                width: s.NowPlayingItem.Width||0,
                height: s.NowPlayingItem.Height||0,
                device: s.DeviceName||"",
                client: s.Client||""
            }));
        } else { // Plex
            const meta = data.MediaContainer?.Metadata || [];
            return meta.map(m=>({
                server: server.name,
                user: m.User?.title||"Unknown",
                title: m.title,
                series: m.grandparentTitle||"",
                position: m.viewOffset||0,
                duration: m.duration||0,
                paused: m.Player?.state!=="playing",
                itemId: m.ratingKey,
                season: m.parentIndex,
                episode: m.index,
                playMethod: (() => {
                    // Check if transcoding is happening
                    if (m.TranscodeSession) return "Transcode";
                    // Check media decision
                    const decision = m.Session?.transcodeDecision || m.Media?.[0]?.selected;
                    if (decision === "transcode") return "Transcode";
                    if (decision === "copy" || decision === "directplay") return "Direct Play";
                    // Fallback: if no transcode session and playing, assume direct play
                    return m.Player?.state === "playing" ? "Direct Play" : "";
                })(),
                width: m.Media?.[0]?.width||0,
                height: m.Media?.[0]?.height||0,
                device: m.Player?.title||"",
                client: m.Player?.product||""
            }));
        }
    } catch(e){
        console.error('Server fetch error', e);
        return [];
    }
}

// Fetch server info (version and updates)
async function fetchServerInfo(server) {
    try {
        const res = await fetch(`proxy.php?server=${encodeURIComponent(server.name)}&action=info`);
        if (!res.ok) return null;
        const data = await res.json();

        // Return object with version and update status
        return {
            version: data.version || 'Unknown',
            hasUpdate: !!data.updateAvailable
        };
    } catch (e) {
        console.error('Server info fetch error', e);
        return null;
    }
}

// Test server update simulation
async function testServerUpdate(serverId) {
    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=info&test_update=1`);
        if (res.ok) {
            const data = await res.json();
            if (data.updateAvailable) {
                // Find server and update state
                const server = SERVERS.find(s => s.id === serverId);
                if (server) {
                    server.hasUpdate = true;
                    renderServerGrid();
                    openServerAdminModal(); // Refresh modal
                    alert(`Update simulation triggered for ${server.name}`);
                }
            }
        }
    } catch (e) {
        console.error('Test update failed', e);
    }
}

// Server Admin Logic
if (IS_ADMIN) {
    const adminBtn = document.getElementById('server-admin-btn');
    if (adminBtn) {
        adminBtn.addEventListener('click', openServerAdminModal);
    }

    // SSH Keys Button (inside Admin Modal)
    const sshBtn = document.getElementById('ssh-keys-btn');
    if (sshBtn) {
        sshBtn.addEventListener('click', openSSHModal);
    }
}

function openServerAdminModal() {
    const modal = document.getElementById('server-admin-modal');
    modal.classList.add('visible');
    renderServerAdminList();
}

function closeServerAdminModal() {
    document.getElementById('server-admin-modal').classList.remove('visible');
}

// Close on click outside
document.getElementById('server-admin-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeServerAdminModal();
    }
});

// SSH Manager Logic
async function openSSHModal() {
    // Hide Admin Modal temporarily (or stack on top, but hiding is cleaner)
    closeServerAdminModal();

    const modal = document.getElementById('ssh-modal');
    modal.classList.add('visible');

    // Load current key
    const keyDisplay = document.getElementById('ssh-public-key');
    keyDisplay.value = 'Loading...';

    try {
        const res = await fetch('ssh_manager.php?action=get_public_key');
        const data = await res.json();
        if (data.success && data.key) {
            keyDisplay.value = data.key;
        } else {
            keyDisplay.value = 'No public key found. Generate one below.';
        }
    } catch (e) {
        keyDisplay.value = 'Error loading key.';
    }

    // Reset form
    document.getElementById('ssh-agree-chk').checked = false;
    document.getElementById('ssh-generate-btn').disabled = true;
}

function closeSSHModal() {
    document.getElementById('ssh-modal').classList.remove('visible');
    // Re-open Admin Modal
    openServerAdminModal();
}

document.getElementById('ssh-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSSHModal();
    }
});

const sshAgreeChk = document.getElementById('ssh-agree-chk');
if (sshAgreeChk) {
    sshAgreeChk.addEventListener('change', function() {
        document.getElementById('ssh-generate-btn').disabled = !this.checked;
    });
}

const sshGenBtn = document.getElementById('ssh-generate-btn');
if (sshGenBtn) {
    sshGenBtn.addEventListener('click', async function() {
        if (!confirm('Are you sure you want to generate a new key pair? This will invalidate existing connections.')) return;

        this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
        this.disabled = true;

        try {
            const res = await fetch('ssh_manager.php?action=generate', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ agreed: true })
            });
            const data = await res.json();

            if (data.success) {
                document.getElementById('ssh-public-key').value = data.key;
                alert('New SSH Key Pair Generated Successfully!');
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Failed to generate key: ' + e.message);
        }

        this.innerHTML = 'Generate New Key Pair';
        this.disabled = false;
        // Uncheck agreement to force re-check for next time
        sshAgreeChk.checked = false;
        this.disabled = true;
    });
}

const sshCopyBtn = document.getElementById('ssh-copy-btn');
if (sshCopyBtn) {
    sshCopyBtn.addEventListener('click', function() {
        const copyText = document.getElementById("ssh-public-key");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value).then(() => {
            const originalText = this.innerText;
            this.innerText = 'Copied!';
            setTimeout(() => this.innerText = originalText, 2000);
        });
    });
}

function renderServerAdminList() {
    const container = document.getElementById('admin-server-list');
    container.innerHTML = '';

    SERVERS.forEach(async server => {
        const item = document.createElement('div');
        item.className = 'admin-server-item';

        const sessions = ALL_SESSIONS[server.name] || [];
        const isActive = sessions.length > 0;
        const status = isActive ? `<span style="color:#4caf50;">Online (${sessions.length} active)</span>` : '<span style="color:#aaa;">Idle</span>';
        const isLinux = !server.os_type || server.os_type === 'linux';

        let actionsHtml = `
            <button class="admin-action-btn" title="Check for Updates" onclick="checkServerUpdate('${esc(server.id)}', this)">
                <i class="fa-solid fa-rotate"></i>
            </button>
            <button class="admin-action-btn" title="Simulate Update Available" onclick="testServerUpdate('${esc(server.id)}')">
                <i class="fa-solid fa-flask"></i>
            </button>
        `;

        if (isLinux) {
            actionsHtml += `<span id="ssh-controls-${esc(server.id)}">`;
            if (server.ssh_initialized) {
                actionsHtml += `<i class="fa-solid fa-spinner fa-spin"></i>`;
                // Fetch status asynchronously
                fetchServerStatus(server.id);
            } else {
                actionsHtml += `
                    <button class="admin-action-btn" title="Check SSH Connection" onclick="deployServerKey('${esc(server.id)}')">
                        <i class="fa-solid fa-key"></i> Check SSH
                    </button>
                `;
            }
            actionsHtml += `</span>`;
        }

        if (server.type !== 'plex') {
            actionsHtml += `
                <button class="admin-action-btn danger" title="Restart Server (API)" onclick="restartServer('${esc(server.id)}', '${esc(server.name)}')">
                    <i class="fa-solid fa-power-off"></i>
                </button>
            `;
        }

        let osIcon = 'fa-server';
        if (!server.os_type || server.os_type === 'linux') osIcon = 'fa-linux';
        else if (server.os_type === 'windows') osIcon = 'fa-windows';
        else if (server.os_type === 'macos') osIcon = 'fa-apple';
        else if (server.os_type === 'other') osIcon = 'fa-server';

        item.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="admin-os-badge" title="OS: ${esc(server.os_type || 'linux')}">
                    <i class="fa-brands ${osIcon}"></i>
                </div>
                <div class="admin-server-info">
                    <div class="admin-server-name">${esc(server.name)}</div>
                    <div class="admin-server-details">
                        Type: ${esc(server.type.toUpperCase())} • Version: ${esc(server.version || 'Unknown')} • ${status}
                    </div>
                </div>
            </div>
            <div class="admin-server-actions">
                ${actionsHtml}
            </div>
        `;
        container.appendChild(item);
    });
}

async function fetchServerStatus(serverId) {
    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_status`);
        const data = await res.json();

        // Update both Admin Modal and Header controls
        const containers = document.querySelectorAll(`[id^="ssh-controls-${serverId}"], [id^="js-header-controls-${serverId}"]`);

        containers.forEach(container => {
            if (data.success) {
                // If data.success is true, SSH exited 0, so service is active/running.
                const isRunning = true;
                const server = SERVERS.find(s => s.id === serverId);
                const serverName = server ? server.name : 'Server';

                if (isRunning) {
                    container.innerHTML = `
                        <button class="admin-action-btn danger" title="Stop Service" onclick="controlServerSSH('${esc(serverId)}', '${esc(serverName)}', 'ssh_stop')">
                            <i class="fa-solid fa-stop"></i>
                        </button>
                        <button class="admin-action-btn" style="color:orange; border-color:orange; background:rgba(255, 165, 0, 0.1);" title="Restart Service" onclick="controlServerSSH('${esc(serverId)}', '${esc(serverName)}', 'ssh_restart')">
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    `;
                } else {
                    container.innerHTML = `
                        <button class="admin-action-btn success" title="Start Service" onclick="controlServerSSH('${esc(serverId)}', '${esc(serverName)}', 'ssh_start')">
                            <i class="fa-solid fa-play"></i>
                        </button>
                    `;
                }
            } else {
                container.innerHTML = `<span style="font-size:0.8rem; color:red;" title="${esc(data.error)}">Error</span>`;
            }
        });
    } catch (e) {
        console.error(e);
    }
}

async function controlServerSSH(serverId, serverName, action) {
    const actionMap = {
        'ssh_stop': 'Stop',
        'ssh_start': 'Start',
        'ssh_restart': 'Restart'
    };
    const actionName = actionMap[action] || action;

    if (!confirm(`${actionName} "${serverName}" via SSH?`)) return;

    // Log
    logSystemEvent(`SSH ${actionName} command issued for ${serverName}`);

    // Set loading state
    const containers = document.querySelectorAll(`[id^="ssh-controls-${serverId}"], [id^="js-header-controls-${serverId}"]`);
    containers.forEach(c => c.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>');

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=${action}`);
        const data = await res.json();

        if (data.success) {
            // Wait 2 seconds then refresh status
            setTimeout(() => fetchServerStatus(serverId), 2000);
        } else {
             alert(`SSH Command Failed: ${data.error}`);
             logSystemEvent(`SSH ${actionName} Failed for ${serverName}: ${data.error}`, 'ERROR');
             fetchServerStatus(serverId); // Restore buttons
        }
    } catch (e) {
        alert('Request failed: ' + e.message);
        fetchServerStatus(serverId);
    }
}

async function deployServerKey(serverId) {
    const containers = document.querySelectorAll(`[id^="ssh-controls-${serverId}"], [id^="js-header-controls-${serverId}"]`);
    containers.forEach(c => c.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...');

    try {
        // Try to connect (status check)
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_status`);
        const data = await res.json();

        if (data.success) {
            // Connection successful! Update server config.
            await fetch('update_server.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    id: serverId,
                    ssh_initialized: true
                })
            });

            // Update local state
            const server = SERVERS.find(s => s.id === serverId);
            if (server) server.ssh_initialized = true;

            // Refresh
            fetchServerStatus(serverId);
            alert('SSH Connection Verified! Controls enabled.');
        } else {
            // Failed
            alert('Connection Failed: ' + data.error + '\n\nPlease ensure you have run the "linux_setup.sh" script on the target server.');
            // Revert button
            const server = SERVERS.find(s => s.id === serverId);
            const containers = document.querySelectorAll(`[id^="ssh-controls-${serverId}"], [id^="js-header-controls-${serverId}"]`);
            if (server) {
                containers.forEach(c => {
                    c.innerHTML = `
                        <button class="admin-action-btn" title="Check SSH Connection" onclick="deployServerKey('${esc(server.id)}')">
                            <i class="fa-solid fa-key"></i> Check SSH
                        </button>
                    `;
                });
            }
        }
    } catch (e) {
        alert('Request failed: ' + e.message);
    }
}

async function checkServerUpdate(serverId, btn) {
    const server = SERVERS.find(s => s.id === serverId);
    if (!server) return;

    const icon = btn.querySelector('i');
    icon.classList.add('fa-spin');

    const info = await fetchServerInfo(server);
    icon.classList.remove('fa-spin');

    if (info) {
        server.version = info.version;
        server.hasUpdate = info.hasUpdate;
        renderServerGrid();
        renderServerAdminList(); // Refresh list to show new version/status if changed
    } else {
        alert('Failed to fetch server info');
    }
}

async function logSystemEvent(message, level = 'INFO') {
    try {
        await fetch('log_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, level })
        });
    } catch (e) {
        console.error('Failed to log event', e);
    }
}

async function restartServer(serverId, serverName) {
    const sessions = ALL_SESSIONS[serverName] || [];
    if (sessions.length > 0) {
        if (!confirm(`WARNING: There are ${sessions.length} active sessions on "${serverName}".\nRestarting will disconnect these users.\n\nAre you sure you want to proceed?`)) {
            return;
        }
    } else {
        if (!confirm(`Restart server "${serverName}"?`)) return;
    }

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=restart`);
        const data = await res.json();

        if (data.success) {
            alert(`Restart command sent to ${serverName}. Monitoring restart process...`);

            // Log initial request
            logSystemEvent(`Restart command issued for ${serverName}`);

            // Find server object
            const server = SERVERS.find(s => s.id === serverId);
            if (!server) return;

            // Start monitoring
            const checkInterval = setInterval(async () => {
                const info = await fetchServerInfo(server);

                if (info) {
                    // Server is back online
                    logSystemEvent(`Server ${serverName} has restarted successfully.`);
                    clearInterval(checkInterval);

                    server.version = info.version;
                    renderServerAdminList(); // Update UI
                    // alert(`Server ${serverName} is back online!`);
                } else {
                    // Server is offline
                    logSystemEvent(`Server ${serverName} unreachable. Retrying connection...`, 'WARN');
                    // Optional: Update UI to show "Restarting..." if we had a dedicated status element
                }
            }, 1000); // Check every second

        } else {
            alert(`Failed to restart: ${data.error || 'Unknown error'}`);
            logSystemEvent(`Restart failed for ${serverName}: ${data.error}`, 'ERROR');
        }
    } catch (e) {
        console.error('Restart failed', e);
        alert('Failed to communicate with server');
        logSystemEvent(`Restart communication failed for ${serverName}: ${e.message}`, 'ERROR');
    }
}

async function fetchServerStats(serverId) {
    const statsEl = document.getElementById('server-stats');
    if (!statsEl) return;

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_system_stats`);
        const data = await res.json();

        if (data.success && data.output) {
            // Parse output parts separated by "---"
            const parts = data.output.split('---');
            const uptimeOutput = parts[0] ? parts[0].trim() : '';
            const freeOutput = parts[1] ? parts[1].trim() : '';
            const serviceOutput = parts[2] ? parts[2].trim() : '';

            // 1. Extract Uptime & Load
            const upMatch = uptimeOutput.match(/up\s+(.+?),\s+\d+\s+users?/);
            let uptime = upMatch ? upMatch[1] : 'Unknown';
            const loadMatch = uptimeOutput.match(/load average:\s+(.+)$/);
            let load = loadMatch ? loadMatch[1] : 'Unknown';

            // 2. Extract System Memory (free -m)
            // Mem: 12345 5678 ...
            let memSysTotal = 0;
            let memSysUsed = 0;
            const memMatch = freeOutput.match(/Mem:\s+(\d+)\s+(\d+)/);
            if (memMatch) {
                memSysTotal = parseInt(memMatch[1]);
                memSysUsed = parseInt(memMatch[2]);
            }

            // 3. Extract Service Stats (systemctl show)
            let svcMem = 0;
            let svcCpuNS = 0;

            const svcMemMatch = serviceOutput.match(/MemoryCurrent=(\d+)/);
            if (svcMemMatch) svcMem = parseInt(svcMemMatch[1]);

            const svcCpuMatch = serviceOutput.match(/CPUUsageNSec=(\d+)/);
            if (svcCpuMatch) svcCpuNS = parseInt(svcCpuMatch[1]); // This is NOT a constant, just current value

            // Format Helpers
            const formatBytes = (bytes) => {
                if (bytes === 0) return '0 MB';
                const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(1024));
                return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + sizes[i];
            };

            const formatTime = (ns) => {
                if (!ns || ns === 18446744073709551615) return 'N/A'; // UINT64_MAX check
                const seconds = ns / 1e9;
                const h = Math.floor(seconds / 3600);
                const m = Math.floor((seconds % 3600) / 60);
                return `${h}h ${m}m`;
            };

            // Build Display Strings
            // Line 1: Uptime & Load
            let html = `<div>Uptime: <span style="color:var(--text);">${esc(uptime)}</span> • Load: <span style="color:var(--text);">${esc(load)}</span></div>`;

            // Line 2: System Memory & Service Stats
            // Service Memory: Bytes -> Formatted
            // System Memory: MB -> Formatted
            const sysMemStr = `${(memSysUsed/1024).toFixed(1)}/${(memSysTotal/1024).toFixed(1)} GB`;

            html += `<div style="margin-top:4px; font-size:0.85rem; color:#888;">`;
            html += `Sys Mem: <span style="color:#aaa;">${sysMemStr}</span>`;

            if (svcMem > 0 || svcCpuNS > 0) {
                 html += ` • App: <span style="color:#aaa;">${formatBytes(svcMem)}</span> / <span style="color:#aaa;">${formatTime(svcCpuNS)} CPU</span>`;
            }
            html += `</div>`;

            statsEl.innerHTML = html;
            statsEl.style.display = 'block';
        }
    } catch (e) {
        console.error('Failed to fetch stats', e);
    }
}
