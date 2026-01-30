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

    if (!typeSelect || !apiKeyGroup || !tokenGroup) return;

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
}

// Add event listener for server type change
const serverTypeSelect = document.getElementById('server-type-select');
if (serverTypeSelect) {
    serverTypeSelect.addEventListener('change', updateServerFormFields);
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
            onlineUsers.push({
                name: session.user,
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
        badge.innerHTML = `<i class="fa-solid fa-user"></i> ${esc(u.name)}`;

        if (u.serverId) {
            badge.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent toggling the list if clicking a badge
                showSessionsView(u.serverId, u.serverName, u.name);
            });
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
toggleSection('dashboard-users-label', '#dashboard-users .user-list-content');

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

document.getElementById('menu-header').addEventListener('click', function() {
    const content = document.getElementById('menu-content');
    const label = document.getElementById('menu-toggle-label');
    content.classList.toggle('hidden');
    const isHidden = content.classList.contains('hidden');
    label.textContent = 'MENU ' + (isHidden ? '+' : '-');
});

// Fetch and render dashboard users
async function fetchDashboardUsers() {
    try {
        const res = await fetch('get_active_users.php?_=' + Date.now());
        if (!res.ok) throw new Error('API Error');
        const data = await res.json();
        renderDashboardUsers(data.users || []);
    } catch (e) {
        console.error('Error fetching dashboard users:', e);
    }
}

function renderDashboardUsers(users) {
    const container = document.querySelector("#dashboard-users .user-list-content");
    if (!container) return;

    if (!users || users.length === 0) {
        container.innerHTML = '<span style="color:var(--muted);font-size:0.9rem;">No users active</span>';
        return;
    }

    container.innerHTML = '';
    users.forEach(user => {
        const badge = document.createElement('div');
        badge.className = 'online-user-badge';
        badge.style.background = '#2196f3'; // Different color for dashboard users
        badge.innerHTML = `<i class="fa-solid fa-user"></i> ${esc(user)}`;
        container.appendChild(badge);
    });
}

// Render server cards
function renderServerGrid() {
    // Get search filter
    const searchInput = document.getElementById('server-search');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

    try {
        renderOnlineUsers(query);
        fetchDashboardUsers();
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

        // Filter: if showActiveOnly is true, only show active servers
        if (showActiveOnly && !isActive) {
            return;
        }

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

        card.innerHTML = `
            ${dragHandle}
            <div class="server-name">${esc(server.name)}</div>
            <div class="server-status">
                <div class="status-dot ${isActive ? 'active server-' + esc(server.type) : ''}"></div>
                ${isActive ? `${sessions.length} playing` : 'Idle'}
            </div>
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

    // Show message if no active servers in active-only mode
    if (showActiveOnly && !hasActiveServers) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'No active servers';
        container.appendChild(empty);
    }
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
    document.getElementById('activeonly-btn').style.display = '';
    document.getElementById('showall-btn').textContent = 'Show All';
    document.getElementById('showall-btn').classList.remove('hideall');
    document.getElementById('showall-btn').style.display = showActiveOnly ? 'none' : '';

    document.getElementById('server-actions').classList.remove('visible');
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
    titleElement.innerHTML = `
        ${esc(serverName)}
        ${server ? `<a href="${esc(server.url)}" target="_blank" class="server-link-btn" title="Go to Server"><i class="fa-solid fa-external-link-alt"></i></a>` : ''}
    `;
    titleElement.className = `section-divider ${serverType}`;

    // Hide Reorder, Active Only, Show All, and Users buttons when viewing single server
    if (IS_ADMIN) {
        document.getElementById('reorder-btn').style.display = 'none';
        document.getElementById('users-btn').style.display = 'none';
    }
    document.getElementById('activeonly-btn').style.display = 'none';
    document.getElementById('showall-btn').style.display = 'none';

    document.getElementById('server-actions').classList.add('visible');
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

// Show all sessions from all servers
function showAllSessions() {
    currentView = 'all';
    selectedServerId = null;
    document.getElementById('server-view').classList.remove('visible');
    document.getElementById('sessions-view').classList.add('visible');

    // Update title with neutral styling for "All Servers"
    const titleElement = document.getElementById('server-title');
    titleElement.textContent = 'All Servers';
    titleElement.className = 'section-divider';

    // Hide Reorder, Active Only, and Users buttons when viewing all sessions
    if (IS_ADMIN) {
        document.getElementById('reorder-btn').style.display = 'none';
        document.getElementById('users-btn').style.display = 'none';
    }
    document.getElementById('activeonly-btn').style.display = 'none';

    document.getElementById('server-actions').classList.remove('visible');
    window.scrollTo(0, 0);
    renderSessions(null); // null = show all
}

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

// Toggle reorder mode (admin only)
if (IS_ADMIN) {
    document.getElementById('reorder-btn').addEventListener('click', function() {
        reorderMode = !reorderMode;
        this.classList.toggle('active');
        this.textContent = reorderMode ? 'Done' : 'Reorder';

        // Re-render to update drag handles
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
                html += `<div class="tech-badge">${esc(item.audioCodec)} ${audioCh ? esc(audioCh) : ''}</div>`;
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
                <div style="margin-top: 12px; padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; font-family: monospace; font-size: 0.85rem; word-break: break-all; color: #aaa;">
                    <div style="margin-bottom: 8px;">
                        <div style="font-size: 0.7rem; text-transform: uppercase; margin-bottom: 2px; color: #666;">Root Path</div>
                        ${esc(dir)}
                    </div>
                    ${file ? `
                    <div>
                        <div style="font-size: 0.7rem; text-transform: uppercase; margin-bottom: 2px; color: #666;">Filename</div>
                        <span style="color: #aaa; font-weight: 600;">${esc(file)}</span>
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
                const methodIcon = isDirectPlay ? '⚡' : '🔄';
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
                html += '<div class="modal-playback-live">🔴 Live</div>';
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

// Auto-refresh
async function start(){
    const refreshSeconds = await loadConfig();
    if(refreshTimer) clearInterval(refreshTimer);
    await loadAll();
    refreshTimer = setInterval(loadAll, refreshSeconds * 1000);

    // Hide loading indicator
    const loadingIndicator = document.getElementById('loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.style.display = 'none';
    }
}



// Edit server button (admin only)
if (IS_ADMIN) {
    document.getElementById('edit-server-btn').addEventListener('click', function() {
        const server = SERVERS.find(s => s.id === selectedServerId);
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

        // Store server ID for update
        form.dataset.originalName = server.id;

        // Update field visibility based on loaded type
        updateServerFormFields();
    });

    // Delete server button (admin only)
    document.getElementById('delete-server-btn').addEventListener('click', async function() {
        const server = SERVERS.find(s => s.id === selectedServerId);
        if (!server) return;

        if (!confirm(`Are you sure you want to delete "${server.name}"?`)) return;

        try {
            const res = await fetch('delete_server.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: selectedServerId})
            });
            const result = await res.json();

            if (result.success) {
                alert(`Deleted server: ${server.name}`);
                showServerView();
                start();
            } else {
                alert(`Error: ${result.error}`);
            }
        } catch(err) {
            alert('Failed to delete server');
            console.error(err);
        }
    });
}

// Modify add server form to handle edits
document.getElementById('add-server-form').addEventListener('submit', async e=>{
    e.preventDefault();

    // Check if user is admin
    if (!IS_ADMIN) {
        alert('Only administrators can add or edit servers');
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
        token:f.token.value
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
            alert(`${action} server: ${result.server.name}`);
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
        } else alert(`Error: ${result.error}`);
    } catch(err){ alert('Failed to save server'); console.error(err); }
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
        alert('Failed to load users');
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
            alert('User added successfully');
            e.target.reset();
            loadUsersList();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error adding user:', error);
        alert('Failed to add user');
    }
});

async function changeUserPassword(username) {
    const newPassword = prompt(`Enter new password for ${username}:\n(minimum 6 characters)`);

    if (!newPassword) return;

    if (newPassword.length < 6) {
        alert('Password must be at least 6 characters');
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
            alert('Password changed successfully');
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error changing password:', error);
        alert('Failed to change password');
    }
}

async function toggleUserRole(username, currentRole) {
    const newRole = currentRole === 'admin' ? 'viewer' : 'admin';
    const action = newRole === 'admin' ? 'promote to Admin' : 'demote to Viewer';

    if (!confirm(`Are you sure you want to ${action} user "${username}"?`)) return;

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
            alert('User role updated successfully');
            loadUsersList();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error updating role:', error);
        alert('Failed to update role');
    }
}

async function deleteUser(username) {
    if (!confirm(`Are you sure you want to delete user "${username}"?\n\nThis action cannot be undone.`)) return;

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
            alert('User deleted successfully');
            loadUsersList();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        alert('Failed to delete user');
    }
}

// Close modals when clicking outside
document.getElementById('users-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUsersModal();
    }
});

// Libraries Management
if (IS_ADMIN) {
    const libBtn = document.getElementById('libraries-btn');
    if (libBtn) {
        libBtn.addEventListener('click', function() {
            openLibrariesModal();
        });
    }
}

function openLibrariesModal() {
    const modal = document.getElementById('libraries-modal');
    modal.classList.add('visible');

    // Populate Server Select
    const select = document.getElementById('library-server-select');
    select.innerHTML = '<option value="">-- Select a Server --</option>';

    SERVERS.forEach(server => {
        const option = document.createElement('option');
        option.value = server.name;
        option.textContent = `${server.name} (${server.type})`;
        select.appendChild(option);
    });

    // Reset View
    document.getElementById('libraries-list').innerHTML = '';
    document.getElementById('libraries-empty').style.display = 'block';

    // Event Listener for Select
    select.onchange = function() {
        if (this.value) {
            fetchLibraries(this.value);
        } else {
            document.getElementById('libraries-list').innerHTML = '';
            document.getElementById('libraries-empty').style.display = 'block';
        }
    };
}

function closeLibrariesModal() {
    document.getElementById('libraries-modal').classList.remove('visible');
}

// Close libraries modal when clicking outside
document.getElementById('libraries-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeLibrariesModal();
    }
});

async function fetchLibraries(serverName) {
    const list = document.getElementById('libraries-list');
    const empty = document.getElementById('libraries-empty');
    const loading = document.getElementById('libraries-loading');

    list.innerHTML = '';
    empty.style.display = 'none';
    loading.style.display = 'block';

    try {
        const response = await fetch(`library_actions.php?action=list&server=${encodeURIComponent(serverName)}`);
        const data = await response.json();

        loading.style.display = 'none';

        if (data.success) {
            if (data.libraries.length === 0) {
                empty.textContent = 'No libraries found.';
                empty.style.display = 'block';
                return;
            }

            // Render libraries
            data.libraries.forEach(lib => {
                const item = document.createElement('div');
                item.className = 'user-item'; // Reuse existing style
                item.style.background = 'rgba(0,0,0,0.3)';

                item.innerHTML = `
                    <div class="user-item-info">
                        <div class="user-item-username">${esc(lib.name)}</div>
                        <div class="user-item-meta">
                            Type: ${esc(lib.type)}
                        </div>
                    </div>
                    <div class="user-item-actions">
                        <button class="btn primary" onclick="scanLibrary('${esc(serverName)}', '${esc(lib.id)}', '${esc(lib.name)}', this)"><i class="fa-solid fa-arrows-rotate"></i> Scan</button>
                    </div>
                `;
                list.appendChild(item);
            });

        } else {
            empty.textContent = 'Error: ' + (data.error || 'Unknown error');
            empty.style.display = 'block';
        }
    } catch (error) {
        console.error('Error fetching libraries:', error);
        loading.style.display = 'none';
        empty.textContent = 'Failed to fetch libraries';
        empty.style.display = 'block';
    }
}

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
            alert('Error: ' + data.error);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-times"></i> Failed';
            }
        }
    } catch (error) {
        console.error('Error scanning library:', error);
        alert('Failed to start scan');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-times"></i> Error';
        }
    }
}
