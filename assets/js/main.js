// Helper to check if a response was redirected to login.php (session expired)
function checkSessionExpiry(res) {
    if (res && res.url && res.url.includes('login.php')) {
        window.location.href = 'login.php?timeout=1';
        return true;
    }
    return false;
}

// Helper to escape HTML and prevent XSS
function esc(str) {
    if (str === null || str === undefined) return '';
    const temp = document.createElement('div');
    temp.textContent = str;
    return temp.innerHTML.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

// Custom Modal Helpers
let modalAlertTimer = null;

function showModalAlert(message, title = 'Notice') {
    const modal = document.getElementById('custom-modal');
    const titleEl = document.getElementById('custom-modal-title');
    const msgEl = document.getElementById('custom-modal-message');
    const actionsEl = document.getElementById('custom-modal-actions');

    if (!modal) {
        alert(message); // Fallback
        return;
    }

    // Clear existing timer to prevent premature closing
    if (modalAlertTimer) {
        clearTimeout(modalAlertTimer);
        modalAlertTimer = null;
    }

    titleEl.textContent = title;
    // Replace newlines with <br> for HTML rendering
    msgEl.innerHTML = message.replace(/\n/g, '<br>');
    actionsEl.style.display = 'none';

    modal.classList.add('visible');

    modalAlertTimer = setTimeout(() => {
        modal.classList.remove('visible');
        modalAlertTimer = null;
    }, 3000);
}

function showModalConfirm(message, title = 'Confirm Action') {
    return new Promise((resolve) => {
        const modal = document.getElementById('custom-modal');
        const titleEl = document.getElementById('custom-modal-title');
        const msgEl = document.getElementById('custom-modal-message');
        const actionsEl = document.getElementById('custom-modal-actions');
        const confirmBtn = document.getElementById('custom-modal-confirm');
        const cancelBtn = document.getElementById('custom-modal-cancel');

        if (!modal) {
            resolve(confirm(message)); // Fallback
            return;
        }

        // Clear alert timer if active to prevent it from closing this confirm modal
        if (modalAlertTimer) {
            clearTimeout(modalAlertTimer);
            modalAlertTimer = null;
        }

        titleEl.textContent = title;
        msgEl.innerHTML = message; // Use innerHTML to support <br> tags
        actionsEl.style.display = 'flex';

        // Clean up previous listeners
        const newConfirm = confirmBtn.cloneNode(true);
        const newCancel = cancelBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);
        cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);

        newConfirm.addEventListener('click', () => {
            modal.classList.remove('visible');
            resolve(true);
        });

        newCancel.addEventListener('click', () => {
            modal.classList.remove('visible');
            resolve(false);
        });

        modal.classList.add('visible');
    });
}

let SERVERS = [];
let ALL_SESSIONS = {};
let refreshTimer = null;
let currentView = 'servers'; // 'servers', 'sessions', or 'all'
let selectedServerId = null;
let reorderMode = false;
let lastHeartbeatTime = Date.now();
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


// Update Modal Logic
let updatePollInterval = null;
let currentUpdateServerId = null;

function openUpdateModal(serverId) {
    const modal = document.getElementById('update-modal');
    modal.classList.add('visible');
    currentUpdateServerId = serverId;

    // Restore UI state (in case it was used for logs)
    const title = modal.querySelector('h2');
    title.textContent = 'Update Server';
    const controls = modal.querySelector('.server-form-group');
    if (controls) controls.style.display = 'block';
    const startBtn = document.getElementById('start-update-btn');
    if (startBtn) startBtn.style.display = '';

    const logOutput = document.getElementById('update-log-output');
    logOutput.textContent = 'Ready to start update...';

    // Infer branch from version
    const server = SERVERS.find(s => s.id === serverId);
    let initialBranch = 'stable';
    if (server && server.version) {
        const isBeta = server.version.toLowerCase().includes('beta');
        initialBranch = isBeta ? 'beta' : 'stable';
    }

    // Set active button
    document.getElementById('update-branch-select').value = initialBranch;
    document.querySelectorAll('.branch-btn').forEach(btn => {
        if (btn.dataset.branch === initialBranch) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    // Enable/Disable button
    const btn = document.getElementById('start-update-btn');
    btn.disabled = false;
    btn.textContent = 'Start Update';

    // Ensure close button is visible
    const closeBtn = document.querySelector('#update-modal .btn:not(.primary)');
    if (closeBtn) closeBtn.style.display = '';
}

function closeUpdateModal() {
    const modal = document.getElementById('update-modal');
    modal.classList.remove('visible');
    if (updatePollInterval) {
        clearInterval(updatePollInterval);
        updatePollInterval = null;
    }
    currentUpdateServerId = null;
}

document.getElementById('update-modal').addEventListener('click', function(e) {
    if (e.target === this) closeUpdateModal();
});

// Branch selection logic
document.querySelectorAll('.branch-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const branch = this.dataset.branch;
        document.getElementById('update-branch-select').value = branch;

        // Update UI
        document.querySelectorAll('.branch-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
    });
});

document.getElementById('start-update-btn').addEventListener('click', async function() {
    if (!currentUpdateServerId && this.textContent !== 'Close') return;

    // If button says Close, just close modal
    if (this.textContent === 'Close') {
        closeUpdateModal();
        return;
    }

    const branch = document.getElementById('update-branch-select').value;
    const btn = this;
    const logOutput = document.getElementById('update-log-output');

    if (!await showModalConfirm('Start update process? The server service will be restarted.')) return;

    // Hide standard close button during update to prevent premature exit
    const closeBtn = document.querySelector('#update-modal .btn:not(.primary)');
    if (closeBtn) closeBtn.style.display = 'none';

    btn.disabled = true;
    btn.textContent = 'Updating...';
    logOutput.textContent = 'Initializing update sequence...\n';

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(currentUpdateServerId)}&action=ssh_update&branch=${encodeURIComponent(branch)}`);
        const data = await res.json();

        if (data.success) {
            logOutput.textContent += 'Update command sent successfully.\nMonitoring progress...\n';
            startUpdatePolling(currentUpdateServerId);
        } else {
            logOutput.textContent += 'Error: ' + (data.error || 'Unknown error') + '\n';
            btn.disabled = false;
            btn.textContent = 'Retry Update';
        }
    } catch (e) {
        logOutput.textContent += 'Request failed: ' + e.message + '\n';
        btn.disabled = false;
        btn.textContent = 'Retry Update';
    }
});

function startUpdatePolling(serverId) {
    if (updatePollInterval) clearInterval(updatePollInterval);

    updatePollInterval = setInterval(async () => {
        try {
            const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_update_log`);
            const data = await res.json();

            if (data.success && data.output) {
                const logEl = document.getElementById('update-log-output');
                logEl.textContent = data.output;
                logEl.scrollTop = logEl.scrollHeight; // Auto-scroll

                if (data.output.includes('UPDATE_COMPLETE')) {
                    clearInterval(updatePollInterval);
                    updatePollInterval = null;

                    const btn = document.getElementById('start-update-btn');
                    btn.disabled = false;
                    btn.textContent = 'Close';

                    showModalAlert('Update Completed Successfully!');
                    fetchServerStatus(serverId); // Refresh status (it might be restarting)

                    // Force refresh of version info
                    const server = SERVERS.find(s => s.id === serverId);
                    if (server) {
                        // Wait a moment for service to potentially restart before checking version
                        setTimeout(async () => {
                            const info = await fetchServerInfo(server);
                            if (info) {
                                server.version = info.version;
                                server.hasUpdate = info.hasUpdate;
                                renderServerGrid();
                                if (currentView === 'sessions' && selectedServerId === serverId) {
                                    showSessionsView(serverId, server.name);
                                }
                            }
                        }, 5000);
                    }
                } else if (data.output.includes('UPDATE_FAILED')) {
                    clearInterval(updatePollInterval);
                    updatePollInterval = null;
                    document.getElementById('start-update-btn').disabled = false;
                    document.getElementById('start-update-btn').textContent = 'Retry Update';
                    showModalAlert('Update Failed. Check logs.');
                }
            }
        } catch (e) {
            console.error('Update polling error', e);
        }
    }, 1000);
}

if (IS_ADMIN) {
    document.getElementById('toggle-form').addEventListener('click', function() {
        openServerModal(false);
    });
}

// Backup & Restore Logic (Admin)
if (IS_ADMIN) {
    const backupBtn = document.getElementById('backup-nav-btn');
    if (backupBtn) {
        backupBtn.addEventListener('click', openBackupModal);
    }
}

function openBackupModal() {
    document.getElementById('backup-modal').classList.add('visible');
    // Reset file input and button
    document.getElementById('restore-file-input').value = '';
    const fileNameDisplay = document.getElementById('restore-file-name');
    if (fileNameDisplay) fileNameDisplay.textContent = '';
    document.getElementById('restore-backup-btn').disabled = true;
}

function closeBackupModal() {
    document.getElementById('backup-modal').classList.remove('visible');
}

// Close backup modal on outside click
document.getElementById('backup-modal').addEventListener('click', function(e) {
    if (e.target === this) closeBackupModal();
});

// Download Backup
document.getElementById('download-backup-btn').addEventListener('click', async function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

    try {
        const res = await fetch('backup.php?action=generate');
        const data = await res.json();

        if (data.success && data.downloadUrl) {
            logSystemEvent('Backup generated successfully');
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Done!';

            // Trigger download
            window.location.href = data.downloadUrl;

            // Reset button after a moment
            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }, 3000);
        } else {
            showModalAlert('Backup generation failed: ' + esc(data.error));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (e) {
        console.error('Backup error:', e);
        showModalAlert('Backup failed to generate');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// Enable Restore Button
const restoreInput = document.getElementById('restore-file-input');
if (restoreInput) {
    restoreInput.addEventListener('change', function() {
        const fileName = this.files[0] ? this.files[0].name : '';
        document.getElementById('restore-file-name').textContent = fileName;
        document.getElementById('restore-backup-btn').disabled = !this.files.length;
    });
}

// Restore Action
document.getElementById('restore-backup-btn').addEventListener('click', async function() {
    const file = restoreInput.files[0];
    if (!file) return;

    if (!await showModalConfirm('DANGER: This will overwrite all users, servers, and keys.\n\nAre you absolutely sure you want to restore from this backup?')) return;

    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Restoring...';

    const formData = new FormData();
    formData.append('backup_file', file);

    try {
        const res = await fetch('backup.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showModalAlert('System Restored Successfully! Reloading...');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showModalAlert('Restore Failed: ' + esc(data.error));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (e) {
        console.error(e);
        showModalAlert('Upload failed: ' + esc(e.message));
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});


// Back button
document.getElementById('back-btn').addEventListener('click', function() {
    showServerView();
});

// Load config via PHP to handle decryption and permissions
async function loadConfig() {
    const res = await fetch('get_config.php?_=' + Date.now());
    if (checkSessionExpiry(res)) return;
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

        if (checkSessionExpiry(res)) return [];

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
        // Ignore expected AbortError during server restarts/offline
        if (e.name !== 'AbortError') {
            console.error('Server fetch error', e);
        }
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
                    // If we are viewing this server, refresh the view to show the badge
                    if (currentView === 'sessions' && selectedServerId === serverId) {
                        showSessionsView(serverId, server.name);
                    }
                    showModalAlert(`Update simulation triggered for ${esc(server.name)}`);
                }
            }
        }
    } catch (e) {
        console.error('Test update failed', e);
    }
}

// Server Admin Logic
if (IS_ADMIN) {
    // SSH Keys Button (in top menu)
    const sshBtn = document.getElementById('ssh-keys-nav-btn');
    if (sshBtn) {
        sshBtn.addEventListener('click', openSSHModal);
    }
}

// SSH Manager Logic
async function openSSHModal() {
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
        if (!await showModalConfirm('Are you sure you want to generate a new key pair? This will invalidate existing connections.')) return;

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
                showModalAlert('New SSH Key Pair Generated Successfully!');
            } else {
                showModalAlert('Error: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            showModalAlert('Failed to generate key: ' + e.message);
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

const SERVER_TRANSITIONS = {}; // { serverId: 'ssh_start'|'ssh_stop'|'ssh_restart' }

async function fetchServerStatus(serverId) {
    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_status`);
        const data = await res.json();

        // Update Header controls
        const containers = document.querySelectorAll(`[id^="js-header-controls-${serverId}"]`);

        // Update SSH Badge based on actual connection status
        const sshBadge = document.getElementById(`ssh-badge-${esc(serverId)}`);
        if (sshBadge) {
            const server = SERVERS.find(s => s.id === serverId);
            const name = server ? server.name : 'Server';
            if (data.success) {
                sshBadge.innerHTML = '<i class="fa-solid fa-check"></i> SSH';
                sshBadge.style.color = '#81c784';
                sshBadge.style.borderColor = 'rgba(76,175,80,0.3)';
                sshBadge.style.cursor = 'pointer';
                sshBadge.onclick = () => openSSHConnectedModal(serverId, name);
            } else {
                sshBadge.innerHTML = '<i class="fa-solid fa-xmark"></i> SSH';
                sshBadge.style.color = '#e57373';
                sshBadge.style.borderColor = 'rgba(229,115,115,0.3)';
                sshBadge.style.cursor = 'pointer';
                sshBadge.onclick = () => openServerSetupModal(serverId, name);
            }
        }

        containers.forEach(container => {
            if (data.success) {
                // Parse detailed status
                const status = (data.status || '').trim();
                const isActive = ['active', 'activating', 'reloading'].includes(status);
                // "Stopped" includes inactive, failed, dead, or unknown.
                const isStopped = ['inactive', 'failed', 'dead'].includes(status);
                const isDeactivating = status === 'deactivating';

                const server = SERVERS.find(s => s.id === serverId);
                const serverName = server ? server.name : 'Server';

                // Handle Pending State Transitions
                const transitionObj = SERVER_TRANSITIONS[serverId];
                if (transitionObj) {
                    const transition = transitionObj.action;
                    if (transition === 'ssh_restart' || transition === 'ssh_start') {
                        if (isActive) {
                            // Action complete
                            const duration = ((Date.now() - transitionObj.startTime) / 1000).toFixed(1);
                            const actionName = transition === 'ssh_restart' ? 'Restart' : 'Start';
                            logSystemEvent(`Action '${actionName}' for '${serverName}' completed in ${duration}s`, 'INFO');

                            delete SERVER_TRANSITIONS[serverId];
                        } else {
                            const label = transition === 'ssh_restart' ? 'Restarting' : 'Starting';
                            container.innerHTML = `<span style="color:#e5a00d;"><i class="fa-solid fa-spinner fa-spin"></i> ${label}...</span>`;
                            return;
                        }
                    } else if (transition === 'ssh_stop') {
                        // Wait until fully stopped (inactive/dead)
                        // If failed, continue polling as it might be transient or user prefers waiting
                        if (['inactive', 'dead'].includes(status)) {
                            // Action complete
                            const duration = ((Date.now() - transitionObj.startTime) / 1000).toFixed(1);
                            logSystemEvent(`Action 'Stop' for '${serverName}' completed in ${duration}s`, 'INFO');

                            delete SERVER_TRANSITIONS[serverId];
                        } else {
                            // Still deactivating, active, or failed
                            container.innerHTML = `<span style="color:#e5a00d;"><i class="fa-solid fa-spinner fa-spin"></i> Stopping...</span>`;
                            return;
                        }
                    }
                }

                // Render buttons
                if (isDeactivating) {
                    container.innerHTML = `<span style="color:#e5a00d;"><i class="fa-solid fa-spinner fa-spin"></i> Stopping...</span>`;
                } else if (!isStopped) {
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
                container.innerHTML = ''; // Clear controls
                const statsEl = document.getElementById('server-stats');
                if (statsEl) {
                    statsEl.style.display = 'block';
                    statsEl.innerHTML = '<div style="color:var(--muted); font-size:0.9rem; padding:15px; background:rgba(255,255,255,0.05); border-radius:6px; margin-top:10px;"><i class="fa-solid fa-triangle-exclamation" style="color:#e57373;"></i> Server controls and statistics depend on SSH setup.</div>';
                }
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

    if (!await showModalConfirm(`${esc(actionName)} "${esc(serverName)}" via SSH?`)) return;

    // Log
    logSystemEvent(`SSH ${actionName} command issued for ${serverName}`);

    // Set loading state
    const containers = document.querySelectorAll(`[id^="ssh-controls-${serverId}"], [id^="js-header-controls-${serverId}"]`);
    containers.forEach(c => c.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>');

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=${action}`);
        const data = await res.json();

        if (data.success) {
            // Register transition and start polling for ALL actions
            SERVER_TRANSITIONS[serverId] = { action: action, startTime: Date.now() };
            pollServerStatus(serverId);
        } else {
             showModalAlert(`SSH Command Failed: ${esc(data.error)}`);
             logSystemEvent(`SSH ${actionName} Failed for ${serverName}: ${data.error}`, 'ERROR');
             fetchServerStatus(serverId); // Restore buttons
        }
    } catch (e) {
        showModalAlert('Request failed: ' + esc(e.message));
        fetchServerStatus(serverId);
    }
}

function pollServerStatus(serverId) {
    let attempts = 0;
    const maxAttempts = 30; // 45 seconds approx
    const interval = setInterval(() => {
        attempts++;
        if (!SERVER_TRANSITIONS[serverId] || attempts >= maxAttempts) {
            clearInterval(interval);
            delete SERVER_TRANSITIONS[serverId]; // Ensure cleanup on timeout
            fetchServerStatus(serverId); // Final state check
            return;
        }
        fetchServerStatus(serverId);
    }, 1500);
}

async function openServerSetupModal(serverId, serverName) {
    const modal = document.getElementById('server-setup-modal');
    const cmdDisplay = document.getElementById('setup-command-display');
    const verifyBtn = document.getElementById('setup-verify-btn');
    const infoText = modal.querySelector('.info-text');

    modal.classList.add('visible');
    cmdDisplay.innerHTML = 'Loading...';

    // Reset button state
    verifyBtn.disabled = true;
    verifyBtn.textContent = 'Verify Connection'; // Reset text to remove spinner

    // Fetch Public Key
    try {
        const res = await fetch('ssh_manager.php?action=get_public_key');
        const data = await res.json();
        if (data.success && data.key) {
            const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
            const server = SERVERS.find(s => s.id === serverId);
            const os = server ? (server.os_type || 'linux') : 'linux';

            // Update the info text with the correct OS name
            const osName = os.charAt(0).toUpperCase() + os.slice(1);
            if (infoText) {
                infoText.innerHTML = `Run this unified command on your <strong>${esc(osName)}</strong> media server to install the agent and authorized key:`;
            }

            let cmd = '';

            // Linux Command
            const scriptUrl = `${baseUrl}/os_helpers/linux_setup.sh`;
            cmd = `wget -qO- "${scriptUrl}" | sudo bash -s install "${data.key}"`;

            cmdDisplay.innerText = cmd;
            verifyBtn.disabled = false;
            verifyBtn.onclick = () => deployServerKey(serverId, verifyBtn);

        } else {
            cmdDisplay.innerText = 'Error loading key.';
        }
    } catch (e) {
        cmdDisplay.innerText = 'Network Error.';
    }
}

function closeServerSetupModal() {
    document.getElementById('server-setup-modal').classList.remove('visible');
}

function openSSHConnectedModal(serverId, serverName) {
    currentSSHModalServerId = serverId;
    const modal = document.getElementById('ssh-connected-modal');
    const cmdDisplay = document.getElementById('uninstall-command-display');

    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const server = SERVERS.find(s => s.id === serverId);
    const os = server ? server.os_type : 'linux';

    let cmd = '';

    // Linux Command
    const scriptUrl = `${baseUrl}/os_helpers/linux_setup.sh`;
    cmd = `wget -qO- "${scriptUrl}" | sudo bash -s uninstall`;

    cmdDisplay.innerText = cmd;
    modal.classList.add('visible');
}

function closeSSHConnectedModal() {
    document.getElementById('ssh-connected-modal').classList.remove('visible');
    if (currentSSHModalServerId) {
        fetchServerStatus(currentSSHModalServerId);
        fetchServerStats(currentSSHModalServerId);
        currentSSHModalServerId = null;
    }
}

document.getElementById('ssh-connected-modal').addEventListener('click', function(e) {
    if (e.target === this) closeSSHConnectedModal();
});

function copyToClipboard(elementId, btn) {
    const el = document.getElementById(elementId);
    let text = el.value || el.innerText;

    navigator.clipboard.writeText(text).then(() => {
        const original = btn.innerText;
        btn.innerText = 'Copied!';
        setTimeout(() => btn.innerText = original, 2000);
    }).catch(err => {
        console.error('Failed to copy', err);
        // Fallback for textarea
        if (el.select) {
            el.select();
            document.execCommand('copy');
            const original = btn.innerText;
            btn.innerText = 'Copied!';
            setTimeout(() => btn.innerText = original, 2000);
        }
    });
}

async function deployServerKey(serverId, btn) {
    const originalText = btn ? btn.innerText : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
    }

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

            // Refresh UI
            fetchServerStatus(serverId);
            fetchServerStats(serverId);
            closeServerSetupModal();
            showModalAlert('SSH Connection Verified! Controls enabled.');
        } else {
            // Failed
            showModalAlert('Connection Failed: ' + esc(data.error) + '\n\nPlease ensure you have run the setup script and pasted the key.');
            if (btn) {
                btn.disabled = false;
                btn.innerText = originalText; // Reset button
                btn.innerHTML = originalText;
            }
        }
    } catch (e) {
        showModalAlert('Request failed: ' + esc(e.message));
        if (btn) {
            btn.disabled = false;
            btn.innerText = originalText;
            btn.innerHTML = originalText;
        }
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

        // Refresh single server view title/buttons
        if (currentView === 'sessions' && selectedServerId === serverId) {
            showSessionsView(serverId, server.name);
        }

        showModalAlert(`Version Checked: v${info.version}` + (info.hasUpdate ? ' (Update Available)' : ''));
    } else {
        showModalAlert('Failed to fetch server info');
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
        if (!await showModalConfirm(`WARNING: There are ${sessions.length} active sessions on "${esc(serverName)}".<br>Restarting will disconnect these users.<br><br>Are you sure you want to proceed?`)) {
            return;
        }
    } else {
        if (!await showModalConfirm(`Restart server "${esc(serverName)}"?`)) return;
    }

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=restart`);
        const data = await res.json();

        if (data.success) {
            showModalAlert(`Restart command sent to ${esc(serverName)}. Monitoring restart process...`);

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
                    // showModalAlert(`Server ${serverName} is back online!`);
                } else {
                    // Server is offline
                    logSystemEvent(`Server ${serverName} unreachable. Retrying connection...`, 'WARN');
                    // Optional: Update UI to show "Restarting..." if we had a dedicated status element
                }
            }, 1000); // Check every second

        } else {
            showModalAlert(`Failed to restart: ${esc(data.error || 'Unknown error')}`);
            logSystemEvent(`Restart failed for ${serverName}: ${data.error}`, 'ERROR');
        }
    } catch (e) {
        console.error('Restart failed', e);
        showModalAlert('Failed to communicate with server');
        logSystemEvent(`Restart communication failed for ${serverName}: ${e.message}`, 'ERROR');
    }
}

// Render online users list (Watchers)
function renderOnlineUsers(filterText = '') {
    const container = document.querySelector("#online-users .user-list-content");
    const label = document.getElementById("online-users-label");
    if (!container) return;

    let onlineUsers = [];
    const processedUsers = new Set();
    const allSessions = Object.values(ALL_SESSIONS).flat();

    // Sort by User Name only
    allSessions.sort((a, b) => {
        return a.user.localeCompare(b.user);
    });

    allSessions.forEach(session => {
        if (!processedUsers.has(session.user)) {
            processedUsers.add(session.user);
            // Find server type
            const server = SERVERS.find(s => s.name === session.server);
            const type = server ? server.type : 'emby';
            let name = session.user || 'Unknown';
            if (name === 'null' || name === null) name = 'Unknown';

            onlineUsers.push({
                name: name,
                type: type,
                serverId: server ? server.id : null,
                serverName: server ? server.name : ''
            });
        }
    });

    // Filter if text is provided
    if (filterText) {
        const lowerFilter = filterText.toLowerCase();
        onlineUsers = onlineUsers.filter(u => u.name.toLowerCase().includes(lowerFilter));

        // Auto-expand if we have results and a filter
        if (onlineUsers.length > 0) {
            container.classList.remove('hidden');
        }
    }

    if (label) {
        const isHidden = container.classList.contains('hidden');
        label.textContent = `${onlineUsers.length} NOW WATCHING ${isHidden ? '+' : '-'}`;
    }

    if (onlineUsers.length === 0) {
        container.innerHTML = filterText
            ? '<span style="color:var(--muted);font-size:0.9rem;">No matching users</span>'
            : '<span style="color:var(--muted);font-size:0.9rem;">No users online</span>';
        return;
    }

    container.innerHTML = '';
    onlineUsers.forEach(u => {
        const badge = document.createElement('div');
        badge.className = `online-user-badge server-${esc(u.type)}`;
        badge.style.cursor = 'pointer';
        badge.title = 'View User Details';
        badge.innerHTML = `<i class="fa-solid fa-user"></i> <span>${esc(u.name)}</span>`;

        if (u.serverId) {
            // Add a jump icon specifically for the server
            const jumpIcon = document.createElement('i');
            jumpIcon.className = 'fa-solid fa-external-link-alt';
            jumpIcon.style.marginLeft = '8px';
            jumpIcon.style.fontSize = '0.75rem';
            jumpIcon.style.opacity = '0.8';
            jumpIcon.title = `Jump to ${esc(u.serverName)}`;
            jumpIcon.onclick = (e) => {
                e.stopPropagation();
                showSessionsView(u.serverId, u.serverName, u.name);
            };
            badge.appendChild(jumpIcon);

            badge.onclick = (e) => {
                e.stopPropagation(); // Prevent toggling the list if clicking a badge
                // Try to find the detailed user object to open the modal
                const mediaUser = ALL_MEDIA_USERS.find(mu => mu.name === u.name && mu.serverId == u.serverId);
                if (mediaUser) {
                    openMediaUserModal(mediaUser);
                } else {
                    // Fallback to server view if user details not found/loaded
                    showSessionsView(u.serverId, u.serverName, u.name);
                }
            };
        }

        container.appendChild(badge);
    });
}

// Toggle visibility helper
function toggleSection(labelId, contentSelector) {
    const label = document.getElementById(labelId);
    const container = document.querySelector(contentSelector);

    if (label && container) {
        label.addEventListener('click', () => {
            container.classList.toggle('hidden');
            const isHidden = container.classList.contains('hidden');
            const text = label.textContent;
            // Replace the last character (+ or -)
            label.textContent = text.slice(0, -1) + (isHidden ? '+' : '-');
        });
    }
}

// Initialize toggles
toggleSection('online-users-label', '#online-users .user-list-content');

// Top Bar Header Logic
function updateClock() {
    const now = new Date();
    const options = { weekday: 'short', month: 'short', day: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    // Short time for mobile (HH:MM)
    const timeShort = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    const clockEl = document.getElementById('header-clock');
    if (clockEl) {
        clockEl.innerHTML = `
            <span class="clock-full">${dateStr} • ${timeStr}</span>
            <span class="clock-short">${timeShort}</span>
        `;
    }
}
setInterval(updateClock, 1000);
updateClock();

document.getElementById('header-reload-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    if (currentView === 'servers') {
        location.reload();
    } else {
        showServerView();
        window.scrollTo(0, 0);
    }
});

// Active Sessions Modal Logic
const userInfoBtn = document.getElementById('user-info-btn');
if (userInfoBtn) {
    userInfoBtn.addEventListener('click', () => {
        openActiveSessionsModal();
    });
}

function openActiveSessionsModal() {
    const modal = document.getElementById('active-sessions-modal');
    if (!modal) return;
    modal.classList.add('visible');
    fetchDashboardUsers();
}

function closeActiveSessionsModal() {
    const modal = document.getElementById('active-sessions-modal');
    if (modal) modal.classList.remove('visible');
}

document.getElementById('active-sessions-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeActiveSessionsModal();
});

// Fetch and render dashboard users
async function fetchDashboardUsers() {
    const container = document.getElementById("active-sessions-list");
    if (!container) return;

    // Show loading if empty
    if (!container.innerHTML.trim()) {
        container.innerHTML = '<div style="text-align:center; color: #888;">Loading...</div>';
    }

    try {
        const res = await fetch('get_active_users.php?_=' + Date.now());
        if (checkSessionExpiry(res)) return;
        if (!res.ok) throw new Error('API Error');
        const data = await res.json();
        renderDashboardUsers(data.users || []);
    } catch (e) {
        console.error('Error fetching dashboard users:', e);
        container.innerHTML = '<div style="text-align:center; color: #ef5350;">Failed to load users</div>';
    }
}

function renderDashboardUsers(users) {
    const container = document.getElementById("active-sessions-list");
    if (!container) return;

    if (!users || users.length === 0) {
        container.innerHTML = '<div style="text-align:center; color:var(--muted);">No users active</div>';
        return;
    }

    container.innerHTML = '';
    users.forEach(u => {
        // Handle both string (old API) and object (new API) formats for robustness
        const username = typeof u === 'string' ? u : u.username;
        const role = typeof u === 'object' && u.role ? u.role : 'viewer';

        const roleBadge = role === 'admin'
            ? '<span style="background:#4caf50; color:white; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700; margin-left:auto; text-transform:uppercase;">ADMIN</span>'
            : '<span style="background:#546e7a; color:white; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:700; margin-left:auto; text-transform:uppercase;">VIEWER</span>';

        const item = document.createElement('div');
        item.style.cssText = 'background: rgba(255,255,255,0.05); padding: 10px 15px; border-radius: 6px; display: flex; align-items: center; gap: 10px; border: 1px solid rgba(255,255,255,0.1);';
        item.innerHTML = `<i class="fa-solid fa-user" style="color: #2196f3;"></i> <span style="font-weight: 500;">${esc(username)}</span> ${roleBadge}`;
        container.appendChild(item);
    });
}

// User Search Modal Logic
let ALL_MEDIA_USERS = [];

async function fetchMediaUsers() {
    const container = document.getElementById("user-search-results");
    if (!container) return;

    // Show loading state
    container.innerHTML = '<div style="text-align:center; color: #888; padding: 20px;"><i class="fa-solid fa-spinner fa-spin"></i> Fetching users from all servers...</div>';

    try {
        const res = await fetch('search_users.php?_=' + Date.now());
        if (checkSessionExpiry(res)) return;
        if (!res.ok) throw new Error('API Error');
        const data = await res.json();

        if (data.success) {
            ALL_MEDIA_USERS = data.users || [];

            // Apply current filter if user already started typing while fetching
            const input = document.getElementById('user-search-input');
            const query = input ? input.value.toLowerCase().trim() : '';

            if (query.length >= 2) {
                const filtered = ALL_MEDIA_USERS.filter(u =>
                    u.name.toLowerCase().includes(query) ||
                    u.serverName.toLowerCase().includes(query)
                );
                renderUserSearchResults(filtered);
            } else {
                renderUserSearchResults([]);
            }
        } else {
            container.innerHTML = `<div style="text-align:center; color: #ef5350; padding: 20px;">Error: ${esc(data.error)}</div>`;
        }
    } catch (e) {
        console.error('Error fetching media users:', e);
        container.innerHTML = '<div style="text-align:center; color: #ef5350; padding: 20px;">Failed to connect to backend</div>';
    }
}

function renderUserSearchResults(users) {
    const container = document.getElementById("user-search-results");
    if (!container) return;

    if (!users || users.length === 0) {
        const query = document.getElementById('user-search-input')?.value || '';
        const msg = query.length < 2
            ? 'Type at least 2 characters to search...'
            : 'No matching users found';
        container.innerHTML = `<div style="text-align:center; color:var(--muted); padding: 20px;">${msg}</div>`;
        return;
    }

    container.innerHTML = '';
    users.forEach(u => {
        const item = document.createElement('div');
        item.className = 'user-search-item';

        // Determine if watching
        const serverName = u.serverName || 'Unknown Server';
        const serverType = u.serverType || 'emby';
        const sessions = ALL_SESSIONS[serverName] || [];
        const isWatching = sessions.some(s => s.user === u.name);

        const serverBadgeClass = `badge server-${esc(serverType)}`;
        let badgeColor = '#4caf50';
        if (serverType === 'plex') badgeColor = '#ffc107';
        if (serverType === 'jellyfin') badgeColor = '#aa00aa';

        item.innerHTML = `
            <i class="fa-solid fa-user user-search-icon"></i>
            <div class="user-search-name">${esc(u.name)}</div>
            <div class="user-search-server-badge">
                <span class="${serverBadgeClass}" style="background: ${badgeColor}; color: ${u.serverType === 'plex' ? 'black' : 'white'}; cursor: pointer;" title="Jump to Server">${esc(u.serverName)}</span>
            </div>
            <div class="user-search-status">
                <span class="user-status-badge ${isWatching ? 'watching' : 'idle'}">
                    <i class="fa-solid fa-circle"></i> ${isWatching ? 'Watching' : 'Idle'}
                </span>
            </div>
        `;

        // Server badge specific click
        const badgeSpan = item.querySelector('.user-search-server-badge span');
        if (badgeSpan) {
            badgeSpan.onclick = (e) => {
                e.stopPropagation();
                closeUserSearchModal();
                showSessionsView(u.serverId, u.serverName);
            };
        }

        item.onclick = () => {
            closeUserSearchModal();
            openMediaUserModal(u);
        };

        container.appendChild(item);
    });
}

function openUserSearchModal() {
    const modal = document.getElementById('user-search-modal');
    if (!modal) return;
    modal.classList.add('visible');

    // Clear and focus input
    const input = document.getElementById('user-search-input');
    if (input) {
        input.value = '';
        setTimeout(() => input.focus(), 100);
    }

    fetchMediaUsers();
}

function closeUserSearchModal() {
    const modal = document.getElementById('user-search-modal');
    if (modal) modal.classList.remove('visible');
}

let currentHistoryOffset = 0;
const historyLimit = 10;

function openMediaUserModal(u) {
    const modal = document.getElementById('media-user-modal');
    const body = document.getElementById('media-user-modal-body');
    if (!modal || !body) return;

    modal.classList.add('visible');
    currentHistoryOffset = 0;

    // Initial loading state with basic info
    const sessions = ALL_SESSIONS[u.serverName] || [];
    const activeSession = sessions.find(s => s.user === u.name);
    const isWatching = !!activeSession;

    let lastSeenStr = 'Never';
    if (u.lastLogin) {
        lastSeenStr = new Date(u.lastLogin).toLocaleString();
    }

    body.innerHTML = `
        <div class="user-detail-header">
            <div class="user-detail-avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <div class="user-detail-info">
                <h2>${esc(u.name)}</h2>
                <p>${esc(u.serverName)} (${esc(u.serverType)})</p>
                <div style="margin-top: 8px;">
                    <span class="user-status-badge ${isWatching ? 'watching' : 'idle'}">
                        <i class="fa-solid fa-circle"></i> ${isWatching ? 'Watching' : 'Idle'}
                    </span>
                </div>
            </div>
        </div>

        ${activeSession ? `
        <div class="user-detail-section">
            <h3><i class="fa-solid fa-play"></i> Currently Watching</h3>
            <div class="history-item" style="background: var(--bg-hover);">
                <div class="history-item-details">
                    <div class="history-item-title">${esc(activeSession.title)}</div>
                    <div class="history-item-meta">${activeSession.progress || '0'}% complete</div>
                </div>
                <button class="btn primary" id="user-modal-jump-btn">
                    <i class="fa-solid fa-external-link-alt"></i> Jump to Server
                </button>
            </div>
        </div>
        ` : ''}

        <div class="user-detail-section">
            <h3><i class="fa-solid fa-clock"></i> Last Activity</h3>
            <p style="margin:0; color: var(--text);">${lastSeenStr}</p>
        </div>

        <div class="user-detail-section" id="user-history-section">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Watch History</h3>
            <div style="text-align:center; padding: 20px; color: var(--muted);">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading history...
            </div>
        </div>

    `;

    // Set up jump button listener to avoid inline JS escaping issues
    const jumpBtn = document.getElementById('user-modal-jump-btn');
    if (jumpBtn) {
        jumpBtn.onclick = () => {
            closeMediaUserModal();
            closeUserSearchModal();
            showSessionsView(u.serverId, u.serverName);
        };
    }


    // Fetch full details and history
    fetchHistory(u);
}

function fetchHistory(u) {
    const section = document.getElementById('user-history-section');
    const limit = historyLimit;
    const offset = currentHistoryOffset;

    fetch(`get_media_user_details.php?serverId=${u.serverId}&userId=${u.id}&offset=${offset}&limit=${limit}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderMediaUserHistory(data.history, offset > 0, u);
            } else {
                if (offset === 0) {
                    section.innerHTML = `
                        <h3><i class="fa-solid fa-clock-rotate-left"></i> Watch History</h3>
                        <p style="color: var(--danger);">${esc(data.error || 'Failed to load history')}</p>
                    `;
                } else {
                    showModalAlert('Failed to load more history');
                    const btn = document.getElementById('load-more-history-btn');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Load More';
                    }
                }
            }
        })
        .catch(err => {
            console.error('Failed to fetch user details:', err);
            if (offset === 0) {
                document.getElementById('user-history-section').innerHTML = `
                    <h3><i class="fa-solid fa-clock-rotate-left"></i> Watch History</h3>
                    <p style="color: var(--danger);">Failed to connect to dashboard API</p>
                `;
            } else {
                const btn = document.getElementById('load-more-history-btn');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Load More';
                }
            }
        });
}

function renderMediaUserHistory(history, append = false, u = null) {
    const section = document.getElementById('user-history-section');
    if (!section) return;

    if (!append && (!history || history.length === 0)) {
        section.innerHTML = `
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Watch History</h3>
            <p style="color: var(--muted); text-align: center; padding: 10px;">No recent history found.</p>
        `;
        return;
    }

    let listContainer = section.querySelector('.history-list');

    if (!append || !listContainer) {
        section.innerHTML = `
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Watch History</h3>
            <div class="history-list-container">
                <div class="history-list"></div>
                <div id="history-load-more-container" style="text-align: center; margin-top: 15px;"></div>
            </div>
        `;
        listContainer = section.querySelector('.history-list');
    }

    history.forEach(item => {
        let dateStr = 'Unknown Date';
        if (item.date) {
            const date = new Date(item.date);
            if (!isNaN(date.getTime())) {
                dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            }
        }

        const itemEl = document.createElement('div');
        itemEl.className = 'history-item';
        itemEl.innerHTML = `
            <img src="${esc(item.image)}" class="history-item-image" onerror="this.src='assets/img/favicon.svg';">
            <div class="history-item-details">
                <div class="history-item-title">${esc(item.title)}</div>
                <div class="history-item-meta">${esc(item.type)} • ${dateStr}</div>
            </div>
        `;
        listContainer.appendChild(itemEl);
    });

    const loadMoreContainer = document.getElementById('history-load-more-container');
    if (loadMoreContainer) {
        if (history.length === historyLimit) {
            loadMoreContainer.innerHTML = `
                <button class="btn" id="load-more-history-btn" style="width: 100%; background: var(--bg-hover); border: 1px solid var(--border);">
                    Load More
                </button>
            `;
            document.getElementById('load-more-history-btn').onclick = () => {
                currentHistoryOffset += historyLimit;
                const btn = document.getElementById('load-more-history-btn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
                fetchHistory(u);
            };
        } else {
            loadMoreContainer.innerHTML = '';
        }
    }
}

function closeMediaUserModal() {
    const modal = document.getElementById('media-user-modal');
    if (modal) modal.classList.remove('visible');
}


// Render server cards
function renderServerGrid() {
    // Get search filter
    const searchInput = document.getElementById('server-search');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

    try {
        renderOnlineUsers(query);
    } catch(e) {
        console.error("Error in user rendering:", e);
    }

    const container = document.getElementById("server-grid");
    container.innerHTML = "";

    let currentType = null;
    let hasActiveServers = false;

    SERVERS.forEach(server => {
        const sessions = ALL_SESSIONS[server.name] || [];
        const isActive = sessions.length > 0;

        // Apply Search Filter: Match user, title, or server name
        let matchPreview = null;
        if (query) {
            const serverNameMatch = server.name.toLowerCase().includes(query);
            let sessionMatch = false;

            // Find specific match to display
            const matchingSession = sessions.find(s =>
                (s.user && s.user.toLowerCase().includes(query)) ||
                (s.title && s.title.toLowerCase().includes(query)) ||
                (s.series && s.series.toLowerCase().includes(query))
            );

            if (matchingSession) {
                sessionMatch = true;

                // Helper for truncation
                const trunc = (s, l=17) => s.length > l ? s.substring(0, l) + '...' : s;

                 if (matchingSession.user.toLowerCase().includes(query)) {
                    matchPreview = `<i class="fa-solid fa-user"></i> ${trunc(matchingSession.user)}`;
                } else if (matchingSession.title && matchingSession.title.toLowerCase().includes(query)) {
                    matchPreview = `<i class="fa-solid fa-film"></i> ${trunc(matchingSession.title)}`;
                } else if (matchingSession.series && matchingSession.series.toLowerCase().includes(query)) {
                    matchPreview = `<i class="fa-solid fa-tv"></i> ${trunc(matchingSession.series)}`;
                }
            }

            if (!serverNameMatch && !sessionMatch) {
                return;
            }
        }

        hasActiveServers = hasActiveServers || isActive;

        // Add section divider when type changes
        if (server.type !== currentType) {
            // Calculate total watchers for this type
            const typeServers = SERVERS.filter(s => s.type === server.type);
            const totalWatchers = typeServers.reduce((acc, s) => {
                const sSessions = ALL_SESSIONS[s.name] || [];
                return acc + sSessions.length;
            }, 0);

            const divider = document.createElement('div');
            divider.className = `section-divider ${server.type}`;
            divider.textContent = `${server.type.toUpperCase()} SERVERS [${totalWatchers}]`;
            container.appendChild(divider);
            currentType = server.type;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'server-card-wrapper';

        const card = document.createElement('div');
        card.className = `server-card server-${server.type} ${isActive ? 'active' : 'idle'}`;
        card.draggable = IS_ADMIN;
        card.dataset.serverId = server.id;

        if (reorderMode && IS_ADMIN) {
            card.classList.add('reorder-mode');
        }

        const dragHandle = IS_ADMIN ? '<div class="drag-handle"><i class="fa-solid fa-bars"></i></div>' : '';

        // OS Badge Logic
        let osIcon = 'fa-server';
        if (!server.os_type || server.os_type === 'linux') osIcon = 'fa-linux';
        else if (server.os_type === 'docker') osIcon = 'fa-docker';
        else if (server.os_type === 'macos') osIcon = 'fa-apple';
        else if (server.os_type === 'other') osIcon = 'fa-server';

        // Drag Handle (Always included if admin, CSS controls visibility)
        const dragHandleHtml = IS_ADMIN ? '<div class="drag-handle"><i class="fa-solid fa-bars"></i></div>' : '';

        card.innerHTML = `
            ${dragHandleHtml}
            <div class="server-name">${esc(server.name)}</div>
            ${server.version ? `
                <div class="server-version">
                    v${esc(server.version)}
                    ${server.hasUpdate ? '<i class="fa-solid fa-circle-up update-available" title="Update Available: New version ready!"></i>' : ''}
                </div>
            ` : ''}
            <div class="server-status">
                <div class="status-dot ${isActive ? 'active server-' + esc(server.type) : ''}"></div>
                ${isActive ? `${sessions.length} playing` : 'Idle'}
            </div>
            <div class="card-os-badge"><i class="fa-brands ${osIcon}"></i></div>
        `;

        // Click to view sessions (only if not clicking drag handle)
        card.addEventListener('click', (e) => {
            if (!e.target.classList.contains('drag-handle') && !e.target.closest('a') && !reorderMode) {
                showSessionsView(server.id, server.name);
            }
        });

        // Drag events
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);

        wrapper.appendChild(card);

        if (matchPreview) {
            const preview = document.createElement('div');
            preview.className = 'server-match-preview';
            preview.innerHTML = matchPreview;
            wrapper.appendChild(preview);
        }

        container.appendChild(wrapper);
    });

}

// Render sessions for a specific server or all servers
function renderSessions(serverName = null) {
    const container = document.getElementById("sessions");
    let sessions = [];

    if (serverName) {
        // Single server
        sessions = (ALL_SESSIONS[serverName] || []).slice();
        sessions.sort((a, b) => a.user.localeCompare(b.user));
    } else {
        // All servers
        sessions = Object.values(ALL_SESSIONS).flat();
        sessions.sort((a,b) => {
            const serverDiff = a.server.localeCompare(b.server);
            if (serverDiff !== 0) return serverDiff;
            return a.user.localeCompare(b.user);
        });
    }

    // Apply search filter if input exists
    const searchInput = document.getElementById('session-search');
    if (searchInput && searchInput.value.trim() !== '') {
        const query = searchInput.value.toLowerCase();
        sessions = sessions.filter(s =>
            (s.user && s.user.toLowerCase().includes(query)) ||
            (s.title && s.title.toLowerCase().includes(query)) ||
            (s.series && s.series.toLowerCase().includes(query)) ||
            (s.device && s.device.toLowerCase().includes(query))
        );
    }

    container.innerHTML = "";

    if(!sessions.length) {
        // If searching, show "No results" instead of "Nothing playing"
        if (searchInput && searchInput.value.trim() !== '') {
            container.innerHTML = '<div class="empty">No matching sessions found</div>';
        } else {
            container.innerHTML = '<div class="empty">Nothing playing</div>';
        }
        return;
    }

    let lastServer = null;
    sessions.forEach(s => {
        // Add server separator for "Show All" view with themed header
        if (!serverName && s.server !== lastServer) {
            // Find the server to get its type
            const server = SERVERS.find(srv => srv.name === s.server);
            const serverType = server ? server.type : 'emby';

            const sep = document.createElement('div');
            sep.className = `section-divider ${serverType}`;
            sep.textContent = s.server;
            container.appendChild(sep);
            lastServer = s.server;
        }

        // Get server type for theming
        const server = SERVERS.find(srv => srv.name === s.server);
        const serverType = server ? server.type : 'emby';
        let bgColor = 'var(--emby-color)'; // Default
        if (serverType === 'plex') bgColor = 'var(--plex-color)';
        if (serverType === 'jellyfin') bgColor = 'var(--jellyfin-color)';

        const isLive = !s.duration || s.duration <= 0 || !isFinite(s.duration);
        const percent = isLive ? null : Math.min(100, Math.floor((s.position / s.duration) * 100));
        const card = document.createElement('div');
        card.className = "session" + (s.paused ? " paused" : "");
        card.style.background = bgColor;

        // Build season/episode string if available
        let episodeInfo = '';
        if (s.season && s.episode) {
            episodeInfo = ` • S${String(s.season).padStart(2, '0')}E${String(s.episode).padStart(2, '0')}`;
        }

        // Build quality and playback badges
        const qualityBadge = getQualityBadge(s.width, s.height);
        const playIcon = getPlayMethodIcon(s.playMethod);
        let badges = '';
        if (qualityBadge || playIcon) {
            badges = `<div class="stream-badges">`;
            if (playIcon) badges += `<span class="badge play-badge" title="${esc(s.playMethod)}">${playIcon}</span>`;
            if (qualityBadge) badges += `<span class="badge quality-badge">${esc(qualityBadge)}</span>`;
            badges += `</div>`;
        }

        card.innerHTML = `
            <div class="user-label">${esc(s.user)}${badges}</div>
            <div class="title">${esc(s.title)}</div>
            ${s.series ? `<div class="subtitle">${esc(s.series)}${esc(episodeInfo)}</div>` : ``}
            <div class="muted">
                ${isLive ? "<i class='fa-solid fa-circle' style='color:#ff5252;'></i> Live" :
                `${msToTime(s.position)} / ${msToTime(s.duration)} • ${s.paused ? "Paused" : "Playing"}`}
            </div>
            ${isLive ? "" :
            `<div class="progress-bar">
                <div class="progress" style="width:${percent}%"></div>
            </div>`}
        `;

        // Click to show item details
        card.style.cursor = 'pointer';
        card.addEventListener('click', () => {
            showItemDetails(s.server, s.itemId, serverType);
        });

        container.appendChild(card);
    });
}

// Show server grid view
function showServerView() {
    currentView = 'servers';
    document.getElementById('server-view').classList.add('visible');
    document.getElementById('sessions-view').classList.remove('visible');

    // Show all buttons again
    if (IS_ADMIN) {
        document.getElementById('reorder-btn').style.display = '';
        document.getElementById('users-btn').style.display = '';
    }

    selectedServerId = null;
    window.scrollTo(0, 0);
}

// Show sessions view for a specific server
function showSessionsView(serverId, serverName, highlightUser = null) {
    currentView = 'sessions';
    selectedServerId = serverId;
    document.getElementById('server-view').classList.remove('visible');
    document.getElementById('sessions-view').classList.add('visible');

    // Find the server by ID to get its type for theming
    const server = SERVERS.find(s => s.id === serverId);
    const serverType = server ? server.type : 'emby';

    // Update the server title with themed header
    const titleElement = document.getElementById('server-title');

    // Header Left
    let headerHtml = '<div class="header-left">';

    // Header OS Icon (Left)
    if (server) {
        let osIcon = 'fa-server';
        if (!server.os_type || server.os_type === 'linux') osIcon = 'fa-linux';
        else if (server.os_type === 'docker') osIcon = 'fa-docker';
        else if (server.os_type === 'macos') osIcon = 'fa-apple';
        else if (server.os_type === 'other') osIcon = 'fa-server';

        headerHtml += `<div class="header-os-badge" title="OS: ${esc(server.os_type || 'linux')}"><i class="fa-brands ${osIcon}"></i></div>`;
    }

    headerHtml += `
            <span class="server-name-text">${esc(serverName)}</span>
            ${server && server.version ? `<span class="server-title-version">[v${esc(server.version)}]</span>` : ''}
    `;

    // Update Badge
    if (server && server.hasUpdate) {
        headerHtml += `<span class="badge" style="background:#4caf50; color:white; margin-left:10px; font-size:0.7rem;"><i class="fa-solid fa-circle-arrow-up"></i> Update Available</span>`;
    }

    headerHtml += `
            ${server ? `<a href="${esc(server.url)}" target="_blank" class="server-link-btn" title="Go to Server"><i class="fa-solid fa-external-link-alt"></i></a>` : ''}
        </div>
    `;

    // Header Center (SSH Indicator)
    headerHtml += `<div class="header-center" style="display:flex;">`;
    if (server && (!server.os_type || server.os_type === 'linux')) {
        const sshId = `ssh-badge-${esc(server.id)}`;
        if (server.ssh_initialized) {
            headerHtml += `<span id="${sshId}" class="badge" style="background:rgba(255,255,255,0.1); color:#81c784; font-size:0.75rem; border:1px solid rgba(76,175,80,0.3); cursor:pointer;" onclick="openSSHConnectedModal('${esc(server.id)}', '${esc(serverName)}')"><i class="fa-solid fa-check"></i> SSH</span>`;
        } else {
            headerHtml += `<span id="${sshId}" class="badge" style="background:rgba(255,255,255,0.1); color:#e57373; font-size:0.75rem; border:1px solid rgba(229,115,115,0.3); cursor:pointer;" onclick="openServerSetupModal('${esc(server.id)}', '${esc(serverName)}')"><i class="fa-solid fa-xmark"></i> SSH</span>`;
        }
    }
    headerHtml += `</div>`;

    // Header Right (Controls)
    headerHtml += `<div class="header-right">`;

    if (IS_ADMIN && server) {
        // 1. Restart Server (API) - Non-Plex
        if (server.type !== 'plex') {
             headerHtml += `
                <button class="admin-action-btn danger" title="Restart Server (API)" onclick="restartServer('${esc(server.id)}', '${esc(server.name)}')">
                    <i class="fa-solid fa-power-off"></i>
                </button>
            `;
        }

        // 2. SSH Controls Container (Start/Stop/Restart)
        headerHtml += `<span id="js-header-controls-${esc(serverId)}"></span>`;

        // 3. Reinstall / Update (Linux + SSH)
        if ((!server.os_type || server.os_type === 'linux') && server.ssh_initialized) {
            const btnColor = server.hasUpdate ? '#4caf50' : '#888';
            const btnTitle = server.hasUpdate ? 'Update Available - Click to Install' : 'Reinstall Server';
            const btnIcon = server.hasUpdate ? 'fa-cloud-arrow-down' : 'fa-wrench';

            headerHtml += `
                <button class="admin-action-btn" style="color:${btnColor}; border-color:${btnColor};" title="${btnTitle}" onclick="openUpdateModal('${esc(server.id)}')">
                    <i class="fa-solid ${btnIcon}"></i>
                </button>
            `;
        }

        // 4. Check Updates
        headerHtml += `
            <button class="admin-action-btn" title="Check for Updates" onclick="checkServerUpdate('${esc(server.id)}', this)">
                <i class="fa-solid fa-rotate"></i>
            </button>
        `;

        // 5. Edit
        headerHtml += `
            <button class="admin-action-btn" title="Edit Server" onclick="openEditServerModal('${esc(server.id)}')">
                <i class="fa-solid fa-pen-to-square"></i>
            </button>
        `;

        // 6. Delete
        headerHtml += `
            <button class="admin-action-btn danger" title="Delete Server" onclick="deleteServer('${esc(server.id)}', '${esc(server.name)}')">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;
    } else {
        // Placeholder if not admin or server not found, though header should probably be empty then
        headerHtml += `<span id="js-header-controls-${esc(serverId)}"></span>`;
    }

    headerHtml += `</div>`;

    titleElement.innerHTML = headerHtml;
    titleElement.className = `server-header-enhanced ${serverType}`;

    // Clear stats
    const statsEl = document.getElementById('server-stats');
    if (statsEl) {
        statsEl.style.display = 'none';
        statsEl.innerHTML = '';
    }

    // Clear Libraries Container
    const libsEl = document.getElementById('server-libraries-container');
    if (libsEl) {
        libsEl.style.display = 'none';
        libsEl.innerHTML = '';
    }

    // Trigger async load of controls if admin and supported OS
    if (IS_ADMIN && server && (!server.os_type || server.os_type === 'linux')) {
        // Initial render
        const container = document.getElementById(`js-header-controls-${esc(serverId)}`);
        if (container) {
             if (server.ssh_initialized) {
                 // Only set spinner if empty, to avoid flickering on re-render
                 if (!container.innerHTML) {
                     container.innerHTML = '<i class="fa-solid fa-spinner fa-spin" title="Verifying SSH..."></i>';
                 }
                 fetchServerStatus(serverId);
                 if (typeof fetchServerStats === 'function') fetchServerStats(serverId);
             }
        }
    }

    // Fetch and render libraries if admin
    if (IS_ADMIN && server) {
        fetchAndRenderInlineLibraries(server.name);
    }

    // Hide Reorder and Users buttons when viewing single server
    if (IS_ADMIN) {
        document.getElementById('reorder-btn').style.display = 'none';
        document.getElementById('users-btn').style.display = 'none';
    }

    window.scrollTo(0, 0);

    // Render sessions
    if (server) {
        renderSessions(server.name);

        // Highlight specific user session if requested
        if (highlightUser) {
            const sessionsContainer = document.getElementById("sessions");
            const sessionCards = sessionsContainer.querySelectorAll('.session');

            for (const card of sessionCards) {
                const userLabel = card.querySelector('.user-label');
                // The user label might contain badges, so we need to be careful with text content
                // Getting the first text node usually works if structure is "User Name <div...>"
                // Or just check if innerText starts with the name
                if (userLabel && userLabel.textContent.trim().startsWith(highlightUser)) {
                    card.classList.add('highlight-session');
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    break;
                }
            }
        }
    }
}

// Show all sessions function removed

// Session Filter
const sessionSearch = document.getElementById('session-search');
if (sessionSearch) {
    sessionSearch.addEventListener('input', () => {
        if (currentView === 'sessions' && selectedServerId) {
            // Find the server name
            const server = SERVERS.find(s => s.id === selectedServerId);
            if (server) renderSessions(server.name);
        } else if (currentView === 'all') {
            renderSessions(null);
        }
    });
}

// Server Filter
const serverSearch = document.getElementById('server-search');
if (serverSearch) {
    serverSearch.addEventListener('input', () => {
        renderServerGrid();
    });
}


// Drag and Drop handlers
let draggedCard = null;

function handleDragStart(e) {
    if (!reorderMode) {
        e.preventDefault();
        return;
    }
    draggedCard = this;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.serverId);
    setTimeout(() => this.classList.add('dragging'), 0);
}

function handleDragEnd(e) {
    this.classList.remove('dragging');
    document.querySelectorAll('.server-card').forEach(card => {
        card.classList.remove('drag-over');
    });
}

// Prevent default drag behavior on drag over (to allow drop)
document.addEventListener('dragover', (e) => {
    if (reorderMode) {
        e.preventDefault();
    }
});

// Modal functions
let modalRefreshInterval = null;
let currentSSHModalServerId = null;
let currentModalServer = null;
let currentModalItemId = null;
let currentModalServerType = null;

function showModal() {
    document.getElementById('item-modal').classList.add('visible');
}

function hideModal() {
    document.getElementById('item-modal').classList.remove('visible');
    // Clear refresh interval when closing modal
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
        modalRefreshInterval = null;
    }
    currentModalServer = null;
    currentModalItemId = null;
    currentModalServerType = null;
}

// Close modal on click outside or close button
document.getElementById('item-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideModal();
    }
});

const itemModalClose = document.querySelector('#item-modal .modal-close');
if (itemModalClose) {
    itemModalClose.addEventListener('click', hideModal);
}

// Show item details in modal
async function showItemDetails(serverName, itemId, serverType) {
    // Store current modal info for refresh
    currentModalServer = serverName;
    currentModalItemId = itemId;
    currentModalServerType = serverType;

    const modalBody = document.getElementById('modal-body');
    modalBody.innerHTML = '<div class="empty">Loading...</div>';
    showModal();

    // Clear any existing refresh interval
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
    }

    // Function to update modal content
    async function updateModalContent() {
        // Find the session data for this item to get user and progress
        let sessionData = null;
        const sessions = ALL_SESSIONS[serverName] || [];
        for (const session of sessions) {
            if (session.itemId === itemId) {
                sessionData = session;
                break;
            }
        }

        try {
            const response = await fetch(`get_item_details.php?server=${encodeURIComponent(serverName)}&itemId=${encodeURIComponent(itemId)}`);
            const data = await response.json();

            if (!data.success) {
                modalBody.innerHTML = '<div class="empty">Failed to load item details</div>';
                return;
            }

            const item = data.item;

        // Build modal content with poster and overview side-by-side
        let html = '<div class="modal-header-new">';

        // Poster image
        if (item.poster) {
            html += `
                <div class="modal-poster">
                    <img src="${esc(item.poster)}" alt="${esc(item.title)}" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImdyYWQxIiB4MT0iMCUiIHkxPSIwJSIgeDI9IjEwMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdHlsZT0ic3RvcC1jb2xvcjojZTVhMDBkO3N0b3Atb3BhY2l0eToxIiAvPjxzdG9wIG9mZnNldD0iNTAlIiBzdHlsZT0ic3RvcC1jb2xvcjojNGNhZjUwO3N0b3Atb3BhY2l0eToxIiAvPjxzdG9wIG9mZnNldD0iMTAwJSIgc3R5bGU9InN0b3AtY29sb3I6I2FhMDBhYTtzdG9wLW9wYWNpdHk6MSIgLz48L2xpbmVhckdyYWRpZW50PjwvZGVmcz48cGF0aCBkPSJNMTIwIDY0IEw0MjAgMjU2IEwxMjAgNDQ4IFoiIGZpbGw9InVybCgjZ3JhZDEpIiBzdHJva2U9IiMzMzMiIHN0cm9rZS13aWR0aD0iMTAiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz48cmVjdCB4PSI4MCIgeT0iNDAiIHdpZHRoPSIzODAiIGhlaWdodD0iNDMyIiByeD0iNDAiIHJ5PSI0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJ1cmwoI2dyYWQxKSIgc3Ryb2tlLXdpZHRoPSIyMCIgLz48L3N2Zz4='">
                </div>
            `;
        } else {
            html += `
                <div class="modal-poster">
                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImdyYWQxIiB4MT0iMCUiIHkxPSIwJSIgeDI9IjEwMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdHlsZT0ic3RvcC1jb2xvcjojZTVhMDBkO3N0b3Atb3BhY2l0eToxIiAvPjxzdG9wIG9mZnNldD0iNTAlIiBzdHlsZT0ic3RvcC1jb2xvcjojNGNhZjUwO3N0b3Atb3BhY2l0eToxIiAvPjxzdG9wIG9mZnNldD0iMTAwJSIgc3R5bGU9InN0b3AtY29sb3I6I2FhMDBhYTtzdG9wLW9wYWNpdHk6MSIgLz48L2xpbmVhckdyYWRpZW50PjwvZGVmcz48cGF0aCBkPSJNMTIwIDY0IEw0MjAgMjU2IEwxMjAgNDQ4IFoiIGZpbGw9InVybCgjZ3JhZDEpIiBzdHJva2U9IiMzMzMiIHN0cm9rZS13aWR0aD0iMTAiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz48cmVjdCB4PSI4MCIgeT0iNDAiIHdpZHRoPSIzODAiIGhlaWdodD0iNDMyIiByeD0iNDAiIHJ5PSI0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJ1cmwoI2dyYWQxKSIgc3Ryb2tlLXdpZHRoPSIyMCIgLz48L3N2Zz4=" alt="No Image">
                </div>
            `;
        }

        // Right side: Title, meta, and overview
        html += '<div class="modal-content-right">';
        html += `<div class="modal-title">${esc(item.title)}</div>`;

        if (item.subtitle) {
            html += `<div class="modal-subtitle">${esc(item.subtitle)}</div>`;
        }

        // Show season and episode numbers for TV shows
        if (item.season && item.episode) {
            html += `<div class="modal-episode">Season ${esc(item.season)}, Episode ${esc(item.episode)}</div>`;
        }

        // Meta information
        html += '<div class="modal-meta">';
        if (item.year) {
            html += `<div class="modal-meta-item"><span class="modal-meta-label">Year:</span> ${esc(item.year)}</div>`;
        }
        if (item.rating) {
            html += `<div class="modal-meta-item"><span class="modal-meta-label">Rating:</span> ${esc(item.rating)}</div>`;
        }
        if (item.runtime) {
            html += `<div class="modal-meta-item"><span class="modal-meta-label">Runtime:</span> ${esc(item.runtime)}</div>`;
        }
        html += '</div>';

        // Tech Badges Logic
        let qualityBadge = '';
        if (item.resolution) {
            if (item.resolution.toLowerCase() === 'sd') {
                qualityBadge = 'SD';
            } else {
                const resolutionParts = item.resolution.split('x');
                // If "WxH", use both. If single number "H", assume height.
                if (resolutionParts.length > 1) {
                    const w = parseInt(resolutionParts[0]);
                    const h = parseInt(resolutionParts[1]);
                    qualityBadge = getQualityBadge(w, h);
                } else if (resolutionParts.length === 1) {
                    const val = parseInt(resolutionParts[0]);
                    if (!isNaN(val)) {
                         // Assume height if single number (e.g. "1080", "480")
                         qualityBadge = getQualityBadge(0, val);
                    }
                }
            }
        }

        const hasTechInfo = qualityBadge || item.container || item.audioCodec;

        if (hasTechInfo) {
            html += '<div class="modal-tech-badges">';
            if (qualityBadge) {
                html += `<div class="tech-badge">${esc(qualityBadge)}</div>`;
            }
            if (item.container) {
                html += `<div class="tech-badge">${esc(item.container)}</div>`;
            }
            if (item.audioCodec) {
                const audioCh = formatAudioChannels(item.audioChannels);
                html += `<div class="tech-badge">${esc(item.audioCodec)} ${audioCh ? esc(audioCh) + 'ch' : ''}</div>`;
            }
            html += '</div>';
        }

        // Overview inline with poster
        if (item.overview) {
            html += `<div class="modal-overview-inline">${esc(item.overview)}</div>`;
        }

        html += '</div></div>'; // Close modal-content-right and modal-header-new

        // Additional details - only show if there's content
        const hasDetails = item.genres || item.director || item.studio || item.contentRating;
        if (hasDetails) {
            html += '<div class="modal-details">';
            if (item.genres) {
                html += `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Genres</div>
                        <div class="modal-detail-value">${esc(item.genres)}</div>
                    </div>
                `;
            }
            if (item.director) {
                html += `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Director</div>
                        <div class="modal-detail-value">${esc(item.director)}</div>
                    </div>
                `;
            }
            if (item.studio) {
                html += `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Studio</div>
                        <div class="modal-detail-value">${esc(item.studio)}</div>
                    </div>
                `;
            }
            if (item.contentRating) {
                html += `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Content Rating</div>
                        <div class="modal-detail-value">${esc(item.contentRating)}</div>
                    </div>
                `;
            }
            html += '</div>';
        }

        // File Info (Path only, tech details moved to badges)
        if (item.path) {
            const path = item.path;
            const lastSlash = Math.max(path.lastIndexOf('/'), path.lastIndexOf('\\'));
            let dir = path;
            let file = '';

            if (lastSlash > -1) {
                dir = path.substring(0, lastSlash);
                file = path.substring(lastSlash + 1);
            }

            html += `
                <div class="modal-file-info">
                    <div style="margin-bottom: 8px;">
                        <div class="modal-file-label">Root Path</div>
                        <span class="modal-file-value">${esc(dir)}</span>
                    </div>
                    ${file ? `
                    <div>
                        <div class="modal-file-label">Filename</div>
                        <span class="modal-file-value">${esc(file)}</span>
                    </div>` : ''}
                </div>
            `;
        }

        // Current playback info at the bottom
        if (sessionData) {
            const isLive = !sessionData.duration || sessionData.duration <= 0 || !isFinite(sessionData.duration);
            const percent = isLive ? 0 : Math.min(100, Math.floor((sessionData.position / sessionData.duration) * 100));

            html += '<div class="modal-playback">';

            // Status and play method badges in top right
            let topBadges = '';

            // Status badge (playing/paused)
            const statusClass = sessionData.paused ? 'status-paused' : 'status-playing';
            const statusText = sessionData.paused ? 'Paused' : 'Playing';
            topBadges += `<span class="status-badge-fixed ${statusClass}">${statusText}</span>`;

            // Play method badge
            if (sessionData.playMethod) {
                const isDirectPlay = sessionData.playMethod.toLowerCase().includes('direct');
                const methodIcon = isDirectPlay ? '<i class="fa-solid fa-bolt"></i>' : '<i class="fa-solid fa-arrows-rotate"></i>';
                const methodClass = isDirectPlay ? 'direct-play' : 'transcoding';
                const methodText = isDirectPlay ? 'Direct Play' : 'Transcoding';
                topBadges += `<span class="playmethod-badge-fixed ${methodClass}">${methodIcon} ${methodText}</span>`;
            }

            // Build single line with all info
            let infoLine = `<i class="fa-solid fa-user"></i> <strong>${esc(sessionData.user)}</strong>`;

            if (sessionData.device) {
                infoLine += ` • ${esc(sessionData.device)}`;
            }

            if (sessionData.quality) {
                infoLine += ` • <span class="quality-badge">${esc(sessionData.quality)}</span>`;
            }

            html += `<div class="modal-playback-info-wrapper">${topBadges}<div class="modal-playback-info">${infoLine}</div></div>`;

            // Progress bar and time
            if (!isLive) {
                html += '<div class="modal-playback-progress" style="margin-top: 12px;">';
                html += `<div class="modal-playback-time">${msToTime(sessionData.position)} / ${msToTime(sessionData.duration)} • ${msToTime(sessionData.duration - sessionData.position)} remaining</div>`;
                html += `<div class="progress-bar"><div class="progress" style="width:${percent}%"></div></div>`;
                html += '</div>';
            } else {
                html += '<div class="modal-playback-live"><i class="fa-solid fa-circle" style="color:#ff5252;"></i> Live</div>';
            }

            html += '</div>';
        }

        modalBody.innerHTML = html;

        } catch (error) {
            console.error('Error fetching item details:', error);
            modalBody.innerHTML = '<div class="empty">Error loading item details</div>';
        }
    }

    // Initial load
    await updateModalContent();

    // Set up auto-refresh every 5 seconds
    modalRefreshInterval = setInterval(updateModalContent, 5000);
}

function handleDragOver(e) {
    if (!reorderMode) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    this.classList.add('drag-over');
    return false;
}

function handleDrop(e) {
    if (!reorderMode) return;
    e.preventDefault();
    e.stopPropagation();

    this.classList.remove('drag-over');

    const droppedCard = this;
    const droppedId = droppedCard.dataset.serverId;
    const draggedId = e.dataTransfer.getData('text/plain');

    if (draggedId === droppedId) return;

    // Find the indices
    const draggedIndex = SERVERS.findIndex(s => s.id === draggedId);
    const droppedIndex = SERVERS.findIndex(s => s.id === droppedId);

    if (draggedIndex === -1 || droppedIndex === -1) return;

    // Reorder the array
    const [draggedServer] = SERVERS.splice(draggedIndex, 1);
    SERVERS.splice(droppedIndex, 0, draggedServer);

    // Update order values
    SERVERS.forEach((server, index) => {
        server.order = index + 1;
    });

    // Save the new order to servers.json
    saveServerOrder();

    // Re-render
    renderServerGrid();

    return false;
}

async function saveServerOrder() {
    try {
        const response = await fetch('update_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ servers: SERVERS })
        });
        const result = await response.json();
        if (!result.success) {
            console.error('Failed to save order:', result.error);
        }
    } catch (error) {
        console.error('Error saving order:', error);
    }
}

// Load all servers
async function loadAll(){
    const progressEl = document.getElementById('loading-progress');
    let loaded = 0;
    const total = SERVERS.length;

    if (progressEl) {
        progressEl.textContent = `Loading servers: 0/${total}`;
    }

    const requests = SERVERS.map(async server => {
        const sessions = await fetchServer(server);
        ALL_SESSIONS[server.name] = sessions;
        loaded++;
        if (progressEl) {
            progressEl.textContent = `Loading servers: ${loaded}/${total}`;
        }
        return sessions;
    });
    await Promise.all(requests);

    // Update current view
    if (currentView === 'servers') {
        renderServerGrid();
    } else if (currentView === 'sessions' && selectedServerId) {
        // Find the server by ID and get its name
        const server = SERVERS.find(s => s.id === selectedServerId);
        if (server) {
            renderSessions(server.name);
        }
    } else if (currentView === 'all') {
        renderSessions(null);
    }
}

// Session Activity / Heartbeat
function resetSessionTimer() {
    // Throttled Heartbeat: Send "I am alive" to server if > 60s since last heartbeat
    const now = Date.now();
    if (now - lastHeartbeatTime > 60000) {
        lastHeartbeatTime = now;
        // Use a lightweight call that triggers activity update (default requireLogin(true))
        fetch('get_user.php').then(res => {
            checkSessionExpiry(res);
        }).catch(e => console.error('Heartbeat failed', e));
    }
}

// Auto-refresh
async function start(){
    const refreshSeconds = await loadConfig();

    // Fetch server versions once
    SERVERS.forEach(async server => {
        const info = await fetchServerInfo(server);
        if (info) {
            server.version = info.version;
            server.hasUpdate = info.hasUpdate;
            // Update UI if we are in server view
            if (currentView === 'servers') renderServerGrid();
        }
    });

    if(refreshTimer) clearInterval(refreshTimer);
    await loadAll();
    // Do NOT reset session timer on poll anymore. Only on user interaction.
    refreshTimer = setInterval(async () => {
        try {
            await loadAll();
        } catch (e) {
            // If polling fails (e.g. 401 Unauthorized because session expired), reload to show login
            // But loadAll catches errors internally mostly.
            // fetchServer catches error.
            // We rely on client timer for redirect.
        }
    }, refreshSeconds * 1000);

    // Hide loading indicator
    const loadingIndicator = document.getElementById('loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.style.display = 'none';
    }
}



// Edit Server Logic
function openEditServerModal(serverId) {
    const server = SERVERS.find(s => s.id === serverId);
    if (!server) return;

    // Open modal in edit mode
    openServerModal(true);

    // Populate form with existing data
    const form = document.getElementById('add-server-form');
    form.querySelector('[name="name"]').value = server.name;
    form.querySelector('[name="type"]').value = server.type;

    // Parse URL into protocol and path
    let fullUrl = server.url;
    let protocol = 'http://';
    let urlPath = fullUrl;

    if (fullUrl.startsWith('https://')) {
        protocol = 'https://';
        urlPath = fullUrl.substring(8);
    } else if (fullUrl.startsWith('http://')) {
        protocol = 'http://';
        urlPath = fullUrl.substring(7);
    }

    form.querySelector('[name="protocol"]').value = protocol;
    form.querySelector('[name="url_path"]').value = urlPath;

    form.querySelector('[name="apiKey"]').value = server.apiKey || '';
    form.querySelector('[name="token"]').value = server.token || '';

    form.querySelector('[name="os_type"]').value = server.os_type || 'linux';
    form.querySelector('[name="ssh_port"]').value = server.ssh_port || '22';

    // Store server ID for update
    form.dataset.originalName = server.id;

    // Update field visibility based on loaded type
    updateServerFormFields();
}

// Delete Server Logic
async function deleteServer(serverId, serverName) {
    if (!await showModalConfirm(`Are you sure you want to delete "${esc(serverName)}"?`)) return;

    try {
        const res = await fetch('delete_server.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: serverId})
        });
        const result = await res.json();

        if (result.success) {
            showModalAlert(`Deleted server: ${esc(serverName)}`);
            showServerView();
            start();
        } else {
            showModalAlert(`Error: ${esc(result.error)}`);
        }
    } catch(err) {
        showModalAlert('Failed to delete server');
        console.error(err);
    }
}

// Modify add server form to handle edits
document.getElementById('add-server-form').addEventListener('submit', async e=>{
    e.preventDefault();

    // Check if user is admin
    if (!IS_ADMIN) {
        showModalAlert('Only administrators can add or edit servers');
        return;
    }

    const f = e.target;
    const urlInput = f.url_path;
    const urlVal = urlInput.value.trim();

    // Validation: host:port
    // Simple regex: non-colon characters + colon + digits
    const urlRegex = /^[^:\/\s]+:\d+$/;

    if (!urlRegex.test(urlVal)) {
        urlInput.setCustomValidity("Please enter a valid Host:Port (e.g., 192.168.1.10:8096)");
        urlInput.reportValidity();
        return;
    }

    const serverId = f.dataset.originalName; // This now stores the ID, not name
    const isEdit = !!serverId;

    const fullUrl = f.protocol.value + urlVal;

    console.log('Form submitted:', {
        isEdit: isEdit,
        serverId: serverId,
        formData: {
            name: f.name.value,
            type: f.type.value,
            url: fullUrl,
            apiKey: f.apiKey.value,
            token: f.token.value
        }
    });

    const data={
        name:f.name.value,
        type:f.type.value,
        url:fullUrl,
        apiKey:f.apiKey.value,
        token:f.token.value,
        os_type: f.os_type.value,
        ssh_port: f.ssh_port.value
    };

    // If editing, include server ID
    if (isEdit) {
        data.id = serverId;
        console.log('Sending update data:', data);
    }

    try{
        const endpoint = isEdit ? 'update_server.php' : 'add_server.php';
        const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
        const result = await res.json();
        console.log('Server response:', result);

        if(result.success){
            const action = isEdit ? 'Updated' : 'Added';
            showModalAlert(`${action} server: ${esc(result.server.name)}`);
            closeServerModal();
            // Reload server data to refresh the SERVERS array
            await start();
            if (isEdit) {
                // Find the server with the ID to get the current name
                const server = SERVERS.find(s => s.id === result.server.id);
                if (server) {
                    showSessionsView(server.id, server.name);
                }
            }
        } else showModalAlert(`Error: ${esc(result.error)}`);
    } catch(err){ showModalAlert('Failed to save server'); console.error(err); }
});

start();

// User Management
if (IS_ADMIN) {
    document.getElementById('users-btn').addEventListener('click', function() {
        openUsersModal();
    });
}

function openUsersModal() {
    document.getElementById('users-modal').classList.add('visible');
    loadUsersList();
}

function closeUsersModal() {
    document.getElementById('users-modal').classList.remove('visible');
    document.getElementById('add-user-form').reset();
}

async function loadUsersList() {
    try {
        const response = await fetch('manage_users.php');
        const data = await response.json();

        if (data.success) {
            const container = document.getElementById('users-list');
            if (data.users.length === 0) {
                container.innerHTML = '<div class="empty">No users found</div>';
                return;
            }

            container.innerHTML = data.users.map(user => `
                <div class="user-item">
                    <div class="user-item-info">
                        <div class="user-item-username">${esc(user.username)}</div>
                        <div class="user-item-meta">
                            <span class="user-item-role ${esc(user.role)}">${esc(user.role).toUpperCase()}</span>
                            Created: ${esc(user.created)}
                        </div>
                    </div>
                    <div class="user-item-actions">
                        <button class="btn" onclick="changeUserPassword('${esc(user.username)}')"><i class="fa-solid fa-key"></i> Change Password</button>
                        <button class="btn" onclick="toggleUserRole('${esc(user.username)}', '${esc(user.role)}')">${user.role === 'admin' ? '<i class="fa-solid fa-user"></i> Make Viewer' : '<i class="fa-solid fa-crown"></i> Make Admin'}</button>
                        <button class="btn danger" onclick="deleteUser('${esc(user.username)}')"><i class="fa-solid fa-trash"></i> Delete</button>
                    </div>
                </div>
            `).join('');
        }
    } catch (error) {
        console.error('Error loading users:', error);
        showModalAlert('Failed to load users');
    }
}

// Add user form submission
document.getElementById('add-user-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    const data = {
        action: 'add',
        username: formData.get('username'),
        password: formData.get('password'),
        role: formData.get('role')
    };

    try {
        const response = await fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const result = await response.json();

        if (result.success) {
            showModalAlert('User added successfully');
            e.target.reset();
            loadUsersList();
        } else {
            showModalAlert('Error: ' + esc(result.error));
        }
    } catch (error) {
        console.error('Error adding user:', error);
        showModalAlert('Failed to add user');
    }
});

async function changeUserPassword(username) {
    const newPassword = prompt(`Enter new password for ${username}:\n(minimum 6 characters)`);

    if (!newPassword) return;

    if (newPassword.length < 6) {
        showModalAlert('Password must be at least 6 characters');
        return;
    }

    try {
        const response = await fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'update',
                username: username,
                password: newPassword
            })
        });
        const result = await response.json();

        if (result.success) {
            showModalAlert('Password changed successfully');
        } else {
            showModalAlert('Error: ' + esc(result.error));
        }
    } catch (error) {
        console.error('Error changing password:', error);
        showModalAlert('Failed to change password');
    }
}

async function toggleUserRole(username, currentRole) {
    const newRole = currentRole === 'admin' ? 'viewer' : 'admin';
    const action = newRole === 'admin' ? 'promote to Admin' : 'demote to Viewer';

    if (!await showModalConfirm(`Are you sure you want to ${action} user "${esc(username)}"?`)) return;

    try {
        const response = await fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'update',
                username: username,
                role: newRole
            })
        });
        const result = await response.json();

        if (result.success) {
            showModalAlert('User role updated successfully');
            loadUsersList();
        } else {
            showModalAlert('Error: ' + esc(result.error));
        }
    } catch (error) {
        console.error('Error updating role:', error);
        showModalAlert('Failed to update role');
    }
}

async function deleteUser(username) {
    if (!await showModalConfirm(`Are you sure you want to delete user "${esc(username)}"?<br><br>This action cannot be undone.`)) return;

    try {
        const response = await fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'delete',
                username: username
            })
        });
        const result = await response.json();

        if (result.success) {
            showModalAlert('User deleted successfully');
            loadUsersList();
        } else {
            showModalAlert('Error: ' + esc(result.error));
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        showModalAlert('Failed to delete user');
    }
}

// Close modals when clicking outside
document.getElementById('users-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUsersModal();
    }
});

// (Libraries Management Removed)

async function scanLibrary(serverName, libraryId, libraryName, btn) {
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting...';
    }

    try {
        const response = await fetch(`library_actions.php?action=scan&server=${encodeURIComponent(serverName)}&library_id=${encodeURIComponent(libraryId)}&library_name=${encodeURIComponent(libraryName)}`);
        const data = await response.json();

        if (data.success) {
            if (btn) btn.innerHTML = '<i class="fa-solid fa-check"></i> Started';
            setTimeout(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Scan';
                }
            }, 3000);
        } else {
            showModalAlert('Error: ' + esc(data.error));
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-times"></i> Failed';
            }
        }
    } catch (error) {
        console.error('Error scanning library:', error);
        showModalAlert('Failed to start scan');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-times"></i> Error';
        }
    }
}
const toGB = (b) => (b / 1073741824).toFixed(1);

const parseNet = (str) => {
    let rx = 0, tx = 0;
    const lines = str.split('\n');
    for (const line of lines) {
        if (line.includes(':')) {
            const parts = line.split(':')[1].trim().split(/\s+/);
            rx += parseInt(parts[0]);
            tx += parseInt(parts[8]);
        }
    }
    return { rx, tx };
};

const formatSpeed = (bytes) => {
    const bits = bytes * 8;
    if (bits >= 1000000) return (bits / 1000000).toFixed(1) + ' Mbps';
    if (bits >= 1000) return (bits / 1000).toFixed(1) + ' Kbps';
    return bits + ' bps';
};

const parseCpu = (str) => {
    const line = str.split('\n')[0];
    if (!line) return { total: 0, idle: 0 };
    const vals = line.match(/\d+/g).map(Number);
    // user+nice+system+idle+iowait...
    const idle = vals[3] + vals[4]; // idle + iowait
    const total = vals.reduce((a, b) => a + b, 0);
    return { total, idle };
};

async function fetchAndRenderInlineLibraries(serverName) {
    const container = document.getElementById('server-libraries-container');
    if (!container) return;

    try {
        const response = await fetch(`library_actions.php?action=list&server=${encodeURIComponent(serverName)}`);
        const data = await response.json();

        if (data.success && data.libraries.length > 0) {
            let html = '<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:center;">';

            // Header
            html += '<div style="font-size:0.8rem; font-weight:700; color:var(--muted); margin-right:8px; letter-spacing:0.05em;">LIBRARIES</div>';

            // Scan All Button
            html += `
                <button class="btn" style="padding:4px 10px; font-size:0.8rem; background:#37474f; border:1px solid var(--border);" onclick="scanAllInlineLibraries(this)" title="Scan All Libraries">
                    <i class="fa-solid fa-layer-group"></i> Scan All
                </button>
                <div style="width:1px; height:20px; background:var(--border); margin:0 4px;"></div>
            `;

            data.libraries.forEach(lib => {
                const countBadge = lib.count !== undefined ? `<span style="color:var(--muted); font-size:0.75rem;">(${lib.count})</span>` : '';
                html += `
                    <div class="inline-library-item">
                        <span>${esc(lib.name)} ${countBadge}</span>
                        <button class="btn primary scan-lib-btn" style="padding:2px 6px; font-size:0.7rem; min-height:auto;" onclick="scanLibrary('${esc(serverName)}', '${esc(lib.id)}', '${esc(lib.name)}', this)" title="Scan Library">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
            container.style.display = 'block';
        }
    } catch (e) {
        console.error('Failed to fetch inline libraries', e);
    }
}

async function scanAllInlineLibraries(btn) {
    if (!await showModalConfirm('Are you sure you want to scan ALL libraries? This may put high load on the server.')) return;

    const container = document.getElementById('server-libraries-container');
    const scanButtons = container.querySelectorAll('.scan-lib-btn');

    // Disable main button
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Queuing...';

    let count = 0;
    for (const scanBtn of scanButtons) {
        if (!scanBtn.disabled) {
            scanBtn.click();
            count++;
            // Small delay to prevent overwhelming the browser/network
            await new Promise(r => setTimeout(r, 200));
        }
    }

    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = originalContent;
    }, 2000);

    showModalAlert(`Triggered scan for ${count} libraries.`);
}

async function fetchServerStats(serverId) {
    const statsEl = document.getElementById('server-stats');
    if (!statsEl) return;

    // Show loading state if it's empty or hidden
    if (statsEl.innerHTML.trim() === '' || statsEl.style.display === 'none') {
        statsEl.innerHTML = '<div style="color:var(--muted); font-size:0.9rem; padding:10px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading system stats...</div>';
        statsEl.style.display = 'block';
    }

    try {
        const res = await fetch(`proxy.php?id=${encodeURIComponent(serverId)}&action=ssh_system_stats`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const data = await res.json();

        if (data.success && data.output) {
            const parts = data.output.split('---').map(p => p.trim());
            let uptimeStr, loadString, memString, memAvailStr, rxStr, txStr, cpuPercent, procStr;

            // Detect OS by header (OS: Linux in proxy)
            // If first part is OS: Linux or just straight stats (legacy), use linux parsing

            // Handle optional SSH banner noise by finding the OS line
            let startIdx = 0;

            if (data.output.includes('OS: Linux')) {
                 const idx = parts.findIndex(p => p.includes('OS: Linux'));
                 if (idx !== -1) startIdx = idx + 1;
            }

            {
                // Linux Parsing (Legacy + New Header)
                if (parts.length < startIdx + 9) {
                     if (startIdx === 0 && parts.length < 9) {
                         console.warn('Incomplete stats data received', parts);
                         statsEl.innerHTML = '<div style="color:orange; font-size:0.8rem;">Stats incomplete</div>';
                         return;
                     }
                }

                // 1. Uptime
                const uptimeLines = parts[startIdx].trim().split('\n');
                const uptimeLine = uptimeLines[uptimeLines.length - 1].trim();
                const uptimeSec = parseFloat(uptimeLine.split(' ')[0]);
                const d = Math.floor(uptimeSec / 86400);
                const h = Math.floor((uptimeSec % 86400) / 3600);
                uptimeStr = (isNaN(d) || isNaN(h)) ? 'Unknown' : `${d}d ${h}h`;

                // 2. Load
                loadString = parts[startIdx + 1].split(' ').slice(0, 3).join(' ');

                // 3. Memory
                const memLines = parts[startIdx + 2].split('\n');
                const memLine = memLines.find(l => l.startsWith('Mem:'));
                let memTotal = 0, memUsed = 0, memAvail = 0;
                if (memLine) {
                    const vals = memLine.match(/\d+/g);
                    if (vals && vals.length >= 3) {
                         memTotal = parseInt(vals[0]);
                         memUsed = parseInt(vals[1]);
                         memAvail = parseInt(vals[5]) || parseInt(vals[2]);
                    }
                }
                memString = `${toGB(memUsed)}/${toGB(memTotal)} GB`;
                memAvailStr = `${toGB(memAvail)} GB avail`;

                // 4. Net
                const netStart = parseNet(parts[startIdx + 3]);
                const netEnd = parseNet(parts[startIdx + 7]);
                rxStr = formatSpeed(netEnd.rx - netStart.rx);
                txStr = formatSpeed(netEnd.tx - netStart.tx);

                // 5. CPU
                const cpuStart = parseCpu(parts[startIdx + 4]);
                const cpuEnd = parseCpu(parts[startIdx + 8]);
                cpuPercent = 0;
                const diffTotal = cpuEnd.total - cpuStart.total;
                const diffIdle = cpuEnd.idle - cpuStart.idle;
                if (diffTotal > 0) {
                    cpuPercent = ((1 - diffIdle / diffTotal) * 100).toFixed(0);
                }

                // 6. Process Stats
                const procParts = parts[startIdx + 5].trim().split(/\s+/);
                if (procParts.length >= 3 && procParts[0] !== '0') {
                    const rssKB = parseInt(procParts[0]);
                    const timeStr = procParts[1];
                    const threads = procParts[2];
                    const rssGB = (rssKB / 1048576).toFixed(2);
                    let hours = 0;
                    if (timeStr.includes('-')) {
                        const [day, t] = timeStr.split('-');
                        hours += parseInt(day) * 24;
                        const tParts = t.split(':');
                        hours += parseInt(tParts[0]);
                    } else {
                        const tParts = timeStr.split(':');
                        if (tParts.length === 3) hours += parseInt(tParts[0]);
                    }
                    let svcName = 'Process';
                    const server = SERVERS.find(s => s.id === serverId);
                    if (server) svcName = server.type === 'plex' ? 'Plex' : (server.type === 'emby' ? 'Emby' : 'Jellyfin');
                    procStr = `${svcName} ${rssGB} GB • ${hours}h CPU • ${threads} threads`;
                } else {
                    procStr = 'Service Stopped';
                }
            }

            // Render
            const html = `
                <div class="server-stats-container">
                    <button class="stats-refresh-btn" onclick="fetchServerStats('${esc(serverId)}')" title="Refresh Stats">
                        <i class="fa-solid fa-rotate-right"></i> Refresh Stats
                    </button>
                    <div class="stats-row">
                        <div class="stats-item">
                            <span class="stats-label">Uptime</span>
                            <span class="stats-value">${uptimeStr}</span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">CPU</span>
                            <span class="stats-value">${cpuPercent}%</span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">Load</span>
                            <span class="stats-value">${loadString}</span>
                        </div>
                    </div>
                    <div class="stats-row">
                        <div class="stats-item">
                            <span class="stats-label">RAM</span>
                            <span class="stats-value">${memString} <span style="color:#888;font-size:0.85rem;">(${memAvailStr})</span></span>
                        </div>
                        <div class="stats-item">
                            <span class="stats-label">Net</span>
                            <span class="stats-value"><i class="fa-solid fa-arrow-down"></i> ${rxStr} <i class="fa-solid fa-arrow-up"></i> ${txStr}</span>
                        </div>
                        <div class="stats-item" style="border-left: 1px solid rgba(255,255,255,0.1); padding-left: 10px; margin-left: 6px;">
                            <span class="stats-value" style="color:var(--accent); font-weight:600;">${procStr}</span>
                        </div>
                    </div>
                </div>
            `;

            statsEl.innerHTML = html;
            statsEl.style.display = 'block';

        } else {
            const errorMsg = data.error || 'Unknown';
            if (errorMsg.includes('Permission denied') || errorMsg.includes('Exit Code 255')) {
                 statsEl.innerHTML = '<div style="color:var(--muted); font-size:0.9rem; padding:15px; background:rgba(255,255,255,0.05); border-radius:6px; margin-top:10px;"><i class="fa-solid fa-triangle-exclamation" style="color:#e57373;"></i> Connection lost. Please verify SSH setup.</div>';
            } else {
                 statsEl.innerHTML = `<div style="color:#d32f2f; font-size:0.8rem; padding:10px;">Stats Error: ${esc(errorMsg)}</div>`;
            }
        }
    } catch (e) {
        console.error('Failed to fetch stats', e);
        statsEl.innerHTML = `<div style="color:#d32f2f; font-size:0.8rem; padding:10px;">Connection Failed: ${esc(e.message)}</div>`;
    }
}

// Theme Toggle Logic
function initTheme() {
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (!themeToggleBtn) return;

    function updateThemeIcon(theme) {
        const icon = themeToggleBtn.querySelector('i');
        const text = themeToggleBtn.querySelector('span');
        if (theme === 'light') {
            // Current is Light, show option to switch to Dark
            icon.className = 'fa-solid fa-moon'; // Icon for target (Dark)
            if (text) text.textContent = 'Dark Mode';
            icon.style.color = '';
        } else {
            // Current is Dark, show option to switch to Light
            icon.className = 'fa-solid fa-sun'; // Icon for target (Light)
            if (text) text.textContent = 'Light Mode';
            icon.style.color = '#ffa726'; // Orange-ish sun
        }
    }

    // Check saved theme or default
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    themeToggleBtn.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
    });
}

// Initialize UI Elements (Theme, Menu, Listeners)
document.addEventListener('DOMContentLoaded', () => {
    // Session Activity Tracking
    const events = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
    events.forEach(evt => {
        document.addEventListener(evt, resetSessionTimer, { passive: true });
    });

    // Theme Toggle Logic
    initTheme();

    // Menu Dropdown Logic
    const menuBtn = document.getElementById('menu-toggle-btn');
    const menuDropdown = document.getElementById('menu-dropdown');

    if (menuBtn && menuDropdown) {
        // Toggle on click
        menuBtn.onclick = function(e) {
            e.stopPropagation();
            e.preventDefault();
            menuDropdown.classList.toggle('visible');
        };

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (menuDropdown.classList.contains('visible') && !menuDropdown.contains(e.target) && !menuBtn.contains(e.target)) {
                menuDropdown.classList.remove('visible');
            }
        });
    } else {
        console.error('Menu elements not found:', { btn: menuBtn, dropdown: menuDropdown });
    }

    // Bind Menu Items to Functions
    // Re-bind IDs that were moved from buttons to divs
    if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN) {
        const toggleFormBtn = document.getElementById('toggle-form');
        if (toggleFormBtn) {
            toggleFormBtn.addEventListener('click', () => {
                openServerModal(false);
                if (menuDropdown) menuDropdown.classList.remove('visible');
            });
        }

        const reorderBtn = document.getElementById('reorder-btn');
        if (reorderBtn) {
            reorderBtn.addEventListener('click', function() {
                reorderMode = !reorderMode;

                // Update text/style
                // 'this' refers to the clicked element (div.menu-item), so we search inside it
                const span = this.querySelector('span');
                const icon = this.querySelector('i');

                if (span) span.textContent = reorderMode ? 'Done Reordering' : 'Reorder Servers';
                if (icon) icon.className = reorderMode ? 'fa-solid fa-check' : 'fa-solid fa-sort';

                renderServerGrid();

                // Close menu to allow interaction with grid
                if (menuDropdown) menuDropdown.classList.remove('visible');
            });
        }

        const usersBtn = document.getElementById('users-btn');
        if (usersBtn) {
            usersBtn.addEventListener('click', () => {
                openUsersModal();
                if (menuDropdown) menuDropdown.classList.remove('visible');
            });
        }

        const sshBtn = document.getElementById('ssh-keys-nav-btn');
        if (sshBtn) {
            sshBtn.addEventListener('click', () => {
                openSSHModal();
                if (menuDropdown) menuDropdown.classList.remove('visible');
            });
        }

        const backupBtn = document.getElementById('backup-nav-btn');
        if (backupBtn) {
            backupBtn.addEventListener('click', () => {
                openBackupModal();
                if (menuDropdown) menuDropdown.classList.remove('visible');
            });
        }

        const panicBtn = document.getElementById('panic-btn');
        if (panicBtn) {
            panicBtn.addEventListener('click', async () => {
                if (menuDropdown) menuDropdown.classList.remove('visible');

                // First Confirmation
                if (!await showModalConfirm(
                    '<span style="color:#ef5350; font-weight:bold;">WARNING: FACTORY RESET</span><br><br>' +
                    'This will <strong>permanently wipe ALL data</strong> including:<br>' +
                    '• User Accounts<br>' +
                    '• Server Configurations<br>' +
                    '• SSH Keys<br>' +
                    '• System Logs<br><br>' +
                    'This action is <strong>IRREVOCABLE</strong>.<br>' +
                    'It is highly recommended to download a backup first.<br><br>' +
                    'Do you want to proceed?',
                    'System Panic'
                )) return;

                // Second Confirmation
                if (!await showModalConfirm(
                    '<span style="color:#ef5350; font-weight:bold;">FINAL CONFIRMATION</span><br><br>' +
                    'Are you absolutely sure?<br>' +
                    'Typing "yes" is not required, but you must click Confirm to trigger the wipe.<br>' +
                    'The system will be reset to factory defaults immediately.',
                    'Irrevocable Action'
                )) return;

                // Execute Reset
                try {
                    // Show a "Wiping..." alert or just loading state
                    // We can reuse showModalAlert but we want it to persist until redirect
                    // So let's manually show a loading state on the body or just alert

                    const res = await fetch('reset.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ confirmed: true })
                    });
                    const data = await res.json();

                    if (data.success) {
                        showModalAlert('System Reset Complete. Redirecting to setup...');
                        setTimeout(() => window.location.reload(), 3000);
                    } else {
                        showModalAlert('Reset Failed: ' + esc(data.error));
                    }
                } catch (e) {
                    showModalAlert('Reset Request Failed: ' + e.message);
                }
            });
        }
    }

    const feedbackBtn = document.getElementById('feedback-btn');
    if (feedbackBtn) {
        feedbackBtn.addEventListener('click', () => {
             openFeedbackModal();
             if (menuDropdown) menuDropdown.classList.remove('visible');
        });
    }

    // User Search Modal Listeners
    const userSearchBtn = document.getElementById('user-search-nav-btn');
    if (userSearchBtn) {
        userSearchBtn.addEventListener('click', () => {
            openUserSearchModal();
        });
    }

    const userSearchInput = document.getElementById('user-search-input');
    if (userSearchInput) {
        userSearchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();

            if (query.length < 2) {
                renderUserSearchResults([]); // Show "Type 2 chars" message
                return;
            }

            const filtered = ALL_MEDIA_USERS.filter(u =>
                u.name.toLowerCase().includes(query) ||
                u.serverName.toLowerCase().includes(query)
            );
            renderUserSearchResults(filtered);
        });
    }

    document.getElementById('user-search-modal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUserSearchModal();
    });

    document.getElementById('media-user-modal')?.addEventListener('click', function(e) {
        if (e.target === this) closeMediaUserModal();
    });

    // Initial load of media users to support User Modals from dashboard badges
    fetchMediaUsers();
});

// Donate Modal Logic
function openDonateModal() {
    const modal = document.getElementById('donate-modal');
    if (modal) modal.classList.add('visible');
}

function closeDonateModal() {
    const modal = document.getElementById('donate-modal');
    if (modal) modal.classList.remove('visible');
}

const donateModal = document.getElementById('donate-modal');
if (donateModal) {
    donateModal.addEventListener('click', function(e) {
        if (e.target === this) closeDonateModal();
    });
}

const donateBtn = document.getElementById('donate-btn');
if (donateBtn) {
    donateBtn.addEventListener('click', openDonateModal);
}

function processDonation() {
    const amount = document.getElementById('donate-amount').value || 5;
    window.open(`https://paypal.me/tophicles/${amount}`, '_blank');
    closeDonateModal();
}

// Feedback Logic
function openFeedbackModal() {
    const modal = document.getElementById('feedback-modal');
    if (modal) {
        modal.classList.add('visible');
        document.getElementById('feedback-form').reset();
    }
}

function closeFeedbackModal() {
    const modal = document.getElementById('feedback-modal');
    if (modal) modal.classList.remove('visible');
}

const feedbackModal = document.getElementById('feedback-modal');
if (feedbackModal) {
    feedbackModal.addEventListener('click', function(e) {
        if (e.target === this) closeFeedbackModal();
    });
}

const feedbackForm = document.getElementById('feedback-form');
if (feedbackForm) {
    feedbackForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const type = formData.get('type');
        const message = formData.get('message');

        // Construct GitHub Issue URL
        // Repository: Tophicles/dash
        const repoUrl = 'https://github.com/Tophicles/dash/issues/new';

        // Format Title: [Type] Feedback
        // Capitalize type first letter
        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);
        const title = `[${typeLabel}] Feedback`;

        // Construct body with metadata hint
        const body = `${message}\n\n---\nSubmitted via MultiDash Feedback Form`;

        const url = `${repoUrl}?title=${encodeURIComponent(title)}&body=${encodeURIComponent(body)}`;

        // Open in new tab
        window.open(url, '_blank');

        // Close modal
        closeFeedbackModal();
    });
}
