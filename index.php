<?php
require_once 'auth.php';
requireLogin();
$user = getCurrentUser();
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Now Playing – Media Servers</title>
<style>
:root {
  --bg:#0f0f0f; --card:#1b1b1b; --border:#333;
  --text:#eee; --muted:#aaa; --accent:#4caf50;
  --emby-color: #1f2f1f;
  --plex-color: #2f2f1f;
}
body { 
  margin:0; 
  padding:16px; 
  background:var(--bg); 
  color:var(--text); 
  font-family:system-ui,sans-serif;
}
.back-btn {
  background: var(--card);
  border: 1px solid var(--border);
  color: white;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 0.9rem;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 16px;
  transition: all 0.2s;
}
.back-btn:hover {
  background: #2a2a2a;
  border-color: #555;
}

/* Top Menu Bar */
.top-bar {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 20px;
  overflow: hidden;
}
.menu-content {
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  border-top: 1px solid var(--border);
}
.menu-content.hidden {
  display: none !important;
}
.top-bar-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: var(--card);
  cursor: default;
}
.app-title {
  font-weight: 700;
  color: var(--accent);
  font-size: 1.1rem;
}
.current-time {
  color: var(--muted);
  font-family: monospace;
  font-size: 0.9rem;
}
.top-bar-left,
.top-bar-center,
.top-bar-right {
  display: flex;
  align-items: center;
  gap: 12px;
}
.user-info {
  font-size: 0.85rem;
  color: #aaa;
  padding: 8px 12px;
  background: rgba(0,0,0,0.3);
  border-radius: 4px;
}
.btn.logout {
  background: #f44336;
}
.btn.logout:hover {
  background: #d32f2f;
}
.btn.users {
  background: #2196f3;
}
.btn.users:hover {
  background: #1976d2;
}
.top-bar-center {
  flex: 1;
  justify-content: center;
}
.btn {
  background: var(--accent);
  border: none;
  color: white;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 0.9rem;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s;
  white-space: nowrap;
}
.btn:hover {
  background: #45a049;
  transform: translateY(-1px);
}
.btn:active {
  transform: translateY(0);
}
.btn.reload {
  background: #607d8b;
}
.btn.reload:hover {
  background: #546e7a;
}
.btn.showall {
  background: var(--accent);
}
.btn.showall.hideall {
  background: #607d8b;
}
.btn.showall.hideall:hover {
  background: #546e7a;
}
.btn.edit {
  background: #ff9800;
}
.btn.edit:hover {
  background: #f57c00;
}
.btn.delete {
  background: #f44336;
}
.btn.delete:hover {
  background: #d32f2f;
}
.server-actions {
  display: none;
  gap: 8px;
}
.server-actions.visible {
  display: flex;
}

/* Server Grid View */
.user-lists-container {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 20px;
  margin-bottom: 20px;
}
.online-users {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 8px;
  min-height: 24px;
}
.list-label {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
  font-weight: 600;
  margin-bottom: 4px;
}
.user-list-content {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  transition: max-height 0.3s ease-out;
  overflow: hidden;
}
.user-list-content.hidden {
  display: none;
}
.list-label {
  cursor: pointer;
  user-select: none;
}
.online-user-badge {
  background: var(--accent);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 0.85rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
}
.online-user-badge.server-plex {
  background: #e5a00d !important;
  color: black !important;
}
.online-user-badge.server-emby {
  background: #4caf50 !important;
  color: white !important;
}
.server-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.server-card {
  background: var(--card);
  border: 2px solid var(--border);
  border-radius: 8px;
  padding: 8px 12px;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-height: 60px;
  position: relative;
}
.server-card.server-emby {
  background: var(--emby-color);
  border-color: rgba(76, 175, 80, 0.3);
}
.server-card.server-plex {
  background: var(--plex-color);
  border-color: #ffc107;
}
.server-card.dragging {
  opacity: 0.5;
  cursor: grabbing;
}
.server-card.drag-over {
  transform: scale(1.05);
  box-shadow: 0 8px 16px rgba(0,0,0,0.4);
}
.drag-handle {
  position: absolute;
  top: 4px;
  right: 4px;
  font-size: 14px;
  color: rgba(255,255,255,0.5);
  cursor: grab;
  padding: 2px 4px;
  border-radius: 3px;
  transition: all 0.2s;
  user-select: none;
  display: none;
}
.server-card.reorder-mode .drag-handle {
  display: block;
}
.drag-handle:hover {
  color: rgba(255,255,255,0.9);
  background: rgba(0,0,0,0.3);
}
.drag-handle:active {
  cursor: grabbing;
}
.server-card.reorder-mode {
  cursor: grab;
}
.server-card.reorder-mode:hover {
  transform: translateY(-2px);
}
.btn.reorder {
  background: #607d8b;
}
.btn.reorder:hover {
  background: #546e7a;
}
.btn.reorder.active {
  background: #4caf50;
}
.btn.activeonly {
  background: #607d8b;
}
.btn.activeonly:hover {
  background: #546e7a;
}
.btn.activeonly.active {
  background: #4caf50;
}
.server-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.server-card.server-emby:hover {
  border-color: rgba(76, 175, 80, 0.6);
}
.server-card.server-plex:hover {
  border-color: rgba(255, 193, 7, 0.6);
}
.server-card.active {
  border-color: var(--accent);
}
.server-card.server-plex.active {
  border-color: #ffc107;
}
.server-card.idle {
  opacity: 0.6;
}
.server-name {
  font-size: 0.9rem;
  font-weight: 600;
  margin-bottom: 1px;
  line-height: 1.1;
}
.server-type {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
}

.server-status {
  margin-top: auto;
  font-size: 0.75rem;
  display: flex;
  align-items: center;
  gap: 4px;
}
.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #666;
}
.status-dot.active.server-emby {
  background: rgba(76, 175, 80, 0.8);
  box-shadow: 0 0 6px rgba(76, 175, 80, 0.6);
}
.status-dot.active.server-plex {
  background: rgba(255, 193, 7, 0.8);
  box-shadow: 0 0 6px rgba(255, 193, 7, 0.6);
}

.section-divider {
  grid-column: 1 / -1;
  padding: 8px 0;
  margin: 12px 0 6px 0;
  border-top: 2px solid var(--border);
  border-bottom: 2px solid var(--border);
  text-align: center;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
}
.section-divider.emby {
  background: var(--emby-color);
  color: #4caf50;
  border-color: #4caf50;
}
.section-divider.plex {
  background: var(--plex-color);
  color: #ffc107;
  border-color: #ffc107;
}

/* Sessions View */
.sessions-view { display: none; }
.sessions-view.visible { display: block; }

/* Modal Styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.8);
  animation: fadeIn 0.3s;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
.modal.visible {
  display: flex !important;
  align-items: center;
  justify-content: center;
}
.modal-content {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  max-width: 800px;
  max-height: 90vh;
  width: 90%;
  overflow-y: auto;
  position: relative;
  animation: slideUp 0.3s;
}
@keyframes slideUp {
  from { transform: translateY(50px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
.modal-close {
  position: absolute;
  top: 12px;
  right: 20px;
  font-size: 32px;
  font-weight: bold;
  color: var(--muted);
  cursor: pointer;
  z-index: 1;
  transition: color 0.2s;
}
.modal-close:hover {
  color: var(--text);
}
#modal-body {
  padding: 20px;
}
.modal-header {
  display: flex;
  gap: 20px;
  margin-bottom: 20px;
}
.modal-header-new {
  display: flex;
  gap: 20px;
  margin-bottom: 20px;
}
.modal-poster {
  flex-shrink: 0;
}
.modal-poster img {
  width: 200px;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.5);
}
.modal-content-right {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.modal-info {
  flex: 1;
}
.modal-title {
  font-size: 1.8rem;
  font-weight: 700;
  margin-bottom: 8px;
  line-height: 1.2;
}
.modal-subtitle {
  font-size: 1.2rem;
  color: rgba(255,255,255,0.7);
  margin-bottom: 8px;
}
.modal-episode {
  font-size: 0.95rem;
  color: rgba(255,255,255,0.6);
  margin-bottom: 12px;
  font-style: italic;
}
.modal-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 16px;
  font-size: 0.9rem;
}
.modal-meta-item {
  display: flex;
  align-items: center;
  gap: 6px;
}
.modal-meta-label {
  color: var(--muted);
  font-weight: 600;
}
.modal-overview {
  line-height: 1.6;
  color: rgba(255,255,255,0.85);
  margin-bottom: 20px;
}
.modal-overview-inline {
  line-height: 1.6;
  color: rgba(255,255,255,0.85);
  margin-top: 16px;
  flex: 1;
}
.modal-playback {
  background: rgba(76, 175, 80, 0.1);
  border: 1px solid rgba(76, 175, 80, 0.3);
  border-radius: 8px;
  padding: 16px;
  margin-top: 20px;
  position: relative;
}
.modal-playback .progress-bar {
  margin-top: 8px;
}
.modal-playback-info-wrapper {
  position: relative;
}
.modal-playback-info {
  font-size: 0.95rem;
  color: var(--text);
  line-height: 1.6;
  padding-right: 240px;
}
.status-badge-fixed {
  position: absolute;
  top: 0;
  right: 140px;
  display: inline-block;
  padding: 4px 10px;
  color: white;
  border-radius: 4px;
  font-size: 0.85rem;
  font-weight: 600;
}
.status-badge-fixed.status-playing {
  background: #4caf50;
}
.status-badge-fixed.status-paused {
  background: #ff9800;
}
.playmethod-badge-fixed {
  position: absolute;
  top: 0;
  right: 0;
  display: inline-block;
  padding: 4px 10px;
  color: white;
  border-radius: 4px;
  font-size: 0.85rem;
  font-weight: 600;
}
.playmethod-badge-fixed.direct-play {
  background: #4caf50;
}
.playmethod-badge-fixed.transcoding {
  background: #ff9800;
}
.modal-playback-progress {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.modal-playback-time {
  font-size: 0.9rem;
  color: rgba(255,255,255,0.8);
  text-align: center;
  margin-bottom: 5px;
}

.modal-playback-live {
  font-size: 1rem;
  color: #ff5252;
  font-weight: 600;
}
.quality-badge {
  display: inline-block;
  padding: 3px 8px;
  background: #2196f3;
  color: white;
  border-radius: 4px;
  font-size: 0.85rem;
  font-weight: 600;
}
.playmethod-badge {
  display: inline-block;
  padding: 3px 8px;
  color: white;
  border-radius: 4px;
  font-size: 0.85rem;
  font-weight: 600;
}
.playmethod-badge.direct-play {
  background: #4caf50;
}
.playmethod-badge.transcoding {
  background: #ff9800;
}
.modal-details {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  padding: 16px;
  background: rgba(0,0,0,0.3);
  border-radius: 8px;
  margin-top: 20px;
}
.modal-detail-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.modal-detail-label {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
}
.modal-detail-value {
  font-size: 0.95rem;
  color: var(--text);
}
.back-btn {
  display: none !important;
}

/* User Management Modal */
.user-form-container {
  background: rgba(0,0,0,0.3);
  padding: 20px;
  border-radius: 8px;
  margin-bottom: 30px;
}
.user-form-container h3 {
  margin-bottom: 15px;
  color: #fff;
}
.form-row {
  display: grid;
  grid-template-columns: 2fr 2fr 1fr 1fr;
  gap: 12px;
  align-items: end;
}
.users-list-container h3 {
  margin-bottom: 15px;
  color: #fff;
}
.user-item {
  background: rgba(0,0,0,0.3);
  padding: 15px;
  border-radius: 6px;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border: 1px solid var(--border);
}
.user-item-info {
  flex: 1;
}
.user-item-username {
  font-weight: 600;
  font-size: 1rem;
  color: #fff;
  margin-bottom: 4px;
}
.user-item-meta {
  font-size: 0.85rem;
  color: #888;
}
.user-item-role {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 3px;
  font-size: 0.75rem;
  font-weight: 600;
  margin-right: 10px;
}
.user-item-role.admin {
  background: #4caf50;
  color: white;
}
.user-item-role.viewer {
  background: #607d8b;
  color: white;
}
.user-item-actions {
  display: flex;
  gap: 8px;
}
.user-item-actions .btn {
  padding: 6px 12px;
  font-size: 0.85rem;
}
.btn.reload.tolist {
  background: var(--accent);
}
.btn.reload.tolist:hover {
  background: #45a049;
}
.session-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; }
.session {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.title {
  font-size: 0.95rem;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.subtitle {
  font-size: 0.85rem;
  color: rgba(255,255,255,0.7);
  line-height: 1.1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-label { 
  font-size:0.7rem; 
  text-transform:uppercase; 
  letter-spacing:0.05em; 
  opacity:0.9; 
  padding-bottom:4px; 
  border-bottom:1px solid rgba(255,255,255,0.15); 
  margin-bottom:6px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.stream-badges {
  display: flex;
  gap: 4px;
  align-items: center;
}
.badge {
  font-size: 0.65rem;
  padding: 2px 6px;
  border-radius: 3px;
  font-weight: 600;
  letter-spacing: 0.03em;
}
.quality-badge {
  background: rgba(33, 150, 243, 0.3);
  color: #64b5f6;
  border: 1px solid rgba(33, 150, 243, 0.5);
}
.play-badge {
  background: rgba(76, 175, 80, 0.3);
  color: #81c784;
  border: 1px solid rgba(76, 175, 80, 0.5);
  font-size: 0.75rem;
  padding: 1px 4px;
}
.muted { font-size:0.7rem; color:rgba(255,255,255,0.7); margin-bottom:4px; }
.progress-bar { height:8px; background:#333; border-radius:4px; margin-top:2px; overflow:hidden; opacity:0.9; }
.progress { height:100%; background:var(--accent); width:0%; transition:width 0.4s linear; }
.paused .progress { background:#ffc107; }

.empty { color:var(--muted); text-align:center; margin-top:40px; }

/* Form styling */
#add-server-form {
  margin-bottom: 20px;
  padding: 16px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 8px;
  display: none;
  flex-wrap: wrap;
  gap: 8px;
  animation: slideDown 0.3s ease-out;
}
#add-server-form.visible {
  display: flex;
}
@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
form input, form select, form button { padding:8px 12px; font-size:0.9rem; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:4px; }
form button { background:var(--accent); border:none; cursor:pointer; font-weight:600; }
form button:hover { background:#45a049; }

.view-container { display: none; }
.view-container.visible { display: block; }
.session.highlight-session {
  border: 2px solid var(--accent);
  box-shadow: 0 0 15px rgba(76, 175, 80, 0.4);
  animation: pulse-highlight 2s infinite;
}
@keyframes pulse-highlight {
  0% { box-shadow: 0 0 5px rgba(76, 175, 80, 0.4); }
  50% { box-shadow: 0 0 20px rgba(76, 175, 80, 0.6); }
  100% { box-shadow: 0 0 5px rgba(76, 175, 80, 0.4); }
}
</style>
</head>
<body>

<!-- Top Menu Bar -->
<div class="top-bar">
  <div class="top-bar-header">
    <div class="app-title">Media MultiDash</div>
    <div class="list-label" id="menu-label" style="cursor: pointer;">MENU +</div>
    <div class="current-time" id="clock">--:--</div>
  </div>
  <div id="menu-content" class="menu-content hidden">
    <div class="top-bar-left">
      <span class="user-info">👤 <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</span>
      <?php if ($isAdmin): ?>
      <button class="btn" id="toggle-form">Add Server</button>
      <?php endif; ?>
    </div>
    <div class="top-bar-center">
      <button class="btn reload" id="reload-btn">🔄 Reload</button>
    </div>
    <div class="top-bar-right">
      <?php if ($isAdmin): ?>
      <div class="server-actions" id="server-actions">
        <button class="btn edit" id="edit-server-btn">✏️ Edit</button>
        <button class="btn delete" id="delete-server-btn">🗑️ Delete</button>
      </div>
      <button class="btn reorder" id="reorder-btn" title="Toggle Reorder Mode">Reorder</button>
      <button class="btn users" id="users-btn" title="Manage Users">👥 Users</button>
      <?php endif; ?>
      <button class="btn activeonly" id="activeonly-btn" title="Show Only Active Servers">Active Only</button>
      <button class="btn showall" id="showall-btn" title="Toggle All Sessions">Show All</button>
      <button class="btn logout" onclick="window.location.href='logout.php'">Logout</button>
    </div>
  </div>
</div>

<!-- Loading Indicator -->
<div id="loading-indicator" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(26, 26, 26, 0.95); display: flex; align-items: center; justify-content: center; z-index: 9999;">
  <div style="text-align: center;">
    <div style="font-size: 3rem; margin-bottom: 20px;">⏳</div>
    <div style="font-size: 1.2rem; color: #e0e0e0; margin-bottom: 10px;">Loading dashboard...</div>
    <div id="loading-progress" style="font-size: 0.9rem; color: #888;"></div>
  </div>
</div>

<!-- Server Grid View -->
<div id="server-view" class="view-container visible">
  <div id="server-grid" class="server-grid"></div>
  <div class="user-lists-container">
    <div id="online-users" class="online-users">
      <div class="list-label" id="online-users-label">Now Watching +</div>
      <div class="user-list-content hidden">
        <span style="color:var(--muted);font-size:0.9rem;">No users online</span>
      </div>
    </div>
    <div id="dashboard-users" class="online-users">
      <div class="list-label" id="dashboard-users-label">Dashboard Users +</div>
      <div class="user-list-content hidden">
        <span style="color:var(--muted);font-size:0.9rem;">Loading...</span>
      </div>
    </div>
  </div>
</div>


<!-- Sessions View -->
<div id="sessions-view" class="view-container">
  <button class="back-btn" id="back-btn">← Back to Servers</button>
  <div id="server-title"></div>
  <div id="sessions" class="session-grid"></div>
</div>

<!-- Add Server Form (Hidden by default) -->
<form id="add-server-form">
  <input type="text" name="name" placeholder="Server Name" required>
  <select name="type">
    <option value="emby">Emby</option>
    <option value="plex">Plex</option>
  </select>
  <input type="text" name="url" placeholder="Proxy URL" required>
  <input type="text" name="apiKey" placeholder="API Key (Emby)">
  <input type="text" name="token" placeholder="Token (Plex)">
  <button type="submit">Add Server</button>
</form>

<!-- Modal for Item Details -->
<div id="item-modal" class="modal">
  <div class="modal-content">
    <span class="modal-close">&times;</span>
    <div id="modal-body"></div>
  </div>
</div>

<!-- User Management Modal -->
<div id="users-modal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeUsersModal()">&times;</span>
    <div id="users-modal-body">
      <h2 style="margin-bottom: 20px;">User Management</h2>
      
      <!-- Add User Form -->
      <div class="user-form-container">
        <h3>Add New User</h3>
        <form id="add-user-form">
          <div class="form-row">
            <div class="form-group">
              <label>Username</label>
              <input type="text" name="username" required minlength="3">
            </div>
            <div class="form-group">
              <label>Password</label>
              <input type="password" name="password" required minlength="6">
            </div>
            <div class="form-group">
              <label>Role</label>
              <select name="role" required>
                <option value="viewer">Viewer</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="form-group">
              <button type="submit" class="btn primary">Add User</button>
            </div>
          </div>
        </form>
      </div>
      
      <!-- Users List -->
      <div class="users-list-container">
        <h3>Existing Users</h3>
        <div id="users-list"></div>
      </div>
    </div>
  </div>
</div>


<script>
// Helper to escape HTML and prevent XSS
function esc(str) {
    if (str === null || str === undefined) return '';
    const temp = document.createElement('div');
    temp.textContent = str;
    return temp.innerHTML.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

let SERVERS = [];
let ALL_SESSIONS = {};
const SERVER_COLORS = {};
let refreshTimer = null;
let currentView = 'servers'; // 'servers', 'sessions', or 'all'
let selectedServerId = null;
let reorderMode = false;
let showActiveOnly = false;
const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

// Toggle form visibility (admin only)
if (IS_ADMIN) {
    document.getElementById('toggle-form').addEventListener('click', function() {
        const form = document.getElementById('add-server-form');
        form.classList.toggle('visible');
        if (form.classList.contains('visible')) {
            this.textContent = 'Cancel';
        } else {
            this.textContent = 'Add Server';
            // Reset submit button text and clear original name
            form.querySelector('button[type="submit"]').textContent = 'Add Server';
            delete form.dataset.originalName;
        }
    });
}

// Reload button
document.getElementById('reload-btn').addEventListener('click', function() {
    if (currentView === 'servers') {
        // In server view, reload the page
        location.reload();
    } else {
        // In sessions view, go back to server list
        showServerView();
        window.scrollTo(0, 0);
    }
});

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
    SERVERS.forEach(s=>{ if(s.color) SERVER_COLORS[s.name] = s.color; });
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
    return '';
}

// Get play method icon
function getPlayMethodIcon(playMethod) {
    if (!playMethod) return '';
    const method = playMethod.toLowerCase();
    if (method.includes('direct')) return '⚡'; // Direct play
    if (method.includes('transcode')) return '🔄'; // Transcoding
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
        if(server.type==="emby"){
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
function renderOnlineUsers() {
    const container = document.querySelector("#online-users .user-list-content");
    const label = document.getElementById("online-users-label");
    if (!container) return;

    const onlineUsers = [];
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

    if (label) {
        const isHidden = container.classList.contains('hidden');
        label.textContent = `${onlineUsers.length} NOW WATCHING ${isHidden ? '+' : '-'}`;
    }

    if (onlineUsers.length === 0) {
        container.innerHTML = '<span style="color:var(--muted);font-size:0.9rem;">No users online</span>';
        return;
    }

    container.innerHTML = '';
    onlineUsers.forEach(u => {
        const badge = document.createElement('div');
        badge.className = `online-user-badge server-${esc(u.type)}`;
        badge.style.cursor = 'pointer';
        badge.innerHTML = `👤 ${esc(u.name)}`;

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
        label.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            container.classList.toggle('hidden');
            const isHidden = container.classList.contains('hidden');
            const text = label.innerText;
            // Replace the last character (+ or -)
            if (text.endsWith('+') || text.endsWith('-')) {
                label.innerText = text.slice(0, -1) + (isHidden ? '+' : '-');
            } else {
                // Fallback if suffix missing
                label.innerText = text + (isHidden ? ' +' : ' -');
            }
        });
    }
}

// Initialize toggles
// Wait for DOM to be ready just in case
document.addEventListener('DOMContentLoaded', () => {
    toggleSection('online-users-label', '#online-users .user-list-content');
    toggleSection('dashboard-users-label', '#dashboard-users .user-list-content');
    toggleSection('menu-label', '#menu-content');
});

// Clock
function updateClock() {
    const now = new Date();
    document.getElementById('clock').textContent = now.toLocaleString();
}
setInterval(updateClock, 1000);
updateClock();

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
        badge.innerHTML = `👤 ${esc(user)}`;
        container.appendChild(badge);
    });
}

// Render server cards
function renderServerGrid() {
    try {
        renderOnlineUsers();
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
        
        const card = document.createElement('div');
        card.className = `server-card server-${server.type} ${isActive ? 'active' : 'idle'}`;
        card.draggable = IS_ADMIN;
        card.dataset.serverId = server.id;
        
        if (reorderMode && IS_ADMIN) {
            card.classList.add('reorder-mode');
        }
        
        const dragHandle = IS_ADMIN ? '<div class="drag-handle">☰</div>' : '';
        
        card.innerHTML = `
            ${dragHandle}
            <div class="server-name">${esc(server.name)}</div>
            <div class="server-type">${esc(server.type)}</div>
            <div class="server-status">
                <div class="status-dot ${isActive ? 'active server-' + esc(server.type) : ''}"></div>
                ${isActive ? `${sessions.length} playing` : 'Idle'}
            </div>
        `;
        
        // Click to view sessions (only if not clicking drag handle)
        card.addEventListener('click', (e) => {
            if (!e.target.classList.contains('drag-handle') && !reorderMode) {
                showSessionsView(server.id, server.name);
            }
        });
        
        // Drag events
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
        
        container.appendChild(card);
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
        sessions = ALL_SESSIONS[serverName] || [];
    } else {
        // All servers
        sessions = Object.values(ALL_SESSIONS).flat();
        sessions.sort((a,b)=>a.server.localeCompare(b.server));
    }
    
    container.innerHTML = "";
    
    if(!sessions.length) {
        container.innerHTML = '<div class="empty">Nothing playing</div>';
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
        const bgColor = serverType === 'emby' ? 'var(--emby-color)' : 'var(--plex-color)';
        
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
                ${isLive ? "🔴 Live" :
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
    
    document.getElementById('reload-btn').textContent = '🔄 Reload';
    document.getElementById('reload-btn').classList.remove('tolist');
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
    titleElement.textContent = serverName;
    titleElement.className = `section-divider ${serverType}`;
    
    // Hide Reorder, Active Only, Show All, and Users buttons when viewing single server
    if (IS_ADMIN) {
        document.getElementById('reorder-btn').style.display = 'none';
        document.getElementById('users-btn').style.display = 'none';
    }
    document.getElementById('activeonly-btn').style.display = 'none';
    document.getElementById('showall-btn').style.display = 'none';
    
    document.getElementById('reload-btn').textContent = 'Server List';
    document.getElementById('reload-btn').classList.add('tolist');
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
    
    document.getElementById('reload-btn').textContent = 'Server List';
    document.getElementById('reload-btn').classList.add('tolist');
    document.getElementById('server-actions').classList.remove('visible');
    window.scrollTo(0, 0);
    renderSessions(null); // null = show all
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

document.querySelector('.modal-close').addEventListener('click', hideModal);

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
                    <img src="${esc(item.poster)}" alt="${esc(item.title)}" onerror="this.style.display='none'">
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
            let infoLine = `👤 <strong>${esc(sessionData.user)}</strong>`;
            
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
        
        // Populate form with existing data
        const form = document.getElementById('add-server-form');
        form.querySelector('[name="name"]').value = server.name;
        form.querySelector('[name="type"]').value = server.type;
        form.querySelector('[name="url"]').value = server.url;
        form.querySelector('[name="apiKey"]').value = server.apiKey || '';
        form.querySelector('[name="token"]').value = server.token || '';
        
        // Store server ID for update (using originalName as the storage key for compatibility)
        form.dataset.originalName = server.id;
        
        // Update submit button text
        form.querySelector('button[type="submit"]').textContent = 'Save Changes';
        
        // Show form
        form.classList.add('visible');
        document.getElementById('toggle-form').textContent = 'Cancel';
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
    
    const f=e.target;
    const serverId = f.dataset.originalName; // This now stores the ID, not name
    const isEdit = !!serverId;
    
    console.log('Form submitted:', {
        isEdit: isEdit,
        serverId: serverId,
        formData: {
            name: f.name.value,
            type: f.type.value,
            url: f.url.value,
            apiKey: f.apiKey.value,
            token: f.token.value
        }
    });
    
    const data={
        name:f.name.value,
        type:f.type.value,
        url:f.url.value,
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
            f.reset();
            delete f.dataset.originalName;
            // Reset submit button text
            f.querySelector('button[type="submit"]').textContent = 'Add Server';
            document.getElementById('add-server-form').classList.remove('visible');
            document.getElementById('toggle-form').textContent = 'Add Server';
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
                        <button class="btn" onclick="changeUserPassword('${esc(user.username)}')">🔑 Change Password</button>
                        <button class="btn" onclick="toggleUserRole('${esc(user.username)}', '${esc(user.role)}')">${user.role === 'admin' ? '👤 Make Viewer' : '👑 Make Admin'}</button>
                        <button class="btn delete" onclick="deleteUser('${esc(user.username)}')">🗑️ Delete</button>
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
</script>
</body>
</html>