<?php
require_once 'auth.php';
require_once 'logging.php';
requireLogin();
$user = getCurrentUser();
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Now Playing – MultiDash</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Top Menu Bar -->
<div class="top-bar">
  <div class="top-bar-header" id="menu-header">
    <div class="header-section left" id="header-reload-btn" title="Reload Dashboard">
      <span class="header-badge">MultiDash</span>
    </div>
    <div class="header-section center" id="menu-toggle-label">
      MENU +
    </div>
    <div class="header-section right" id="header-clock-btn">
      <span class="header-badge" id="header-clock">--:--</span>
    </div>
  </div>
  <div id="menu-content" class="menu-content hidden">
    <div class="top-bar-left">
      <span class="user-info">👤 <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</span>
      <?php if ($isAdmin): ?>
      <button class="btn primary" id="toggle-form">Add Server</button>
      <?php endif; ?>
    </div>
    <div class="top-bar-right">
      <?php if ($isAdmin): ?>
      <div class="server-actions" id="server-actions">
        <button class="btn" id="edit-server-btn">✏️ Edit</button>
        <button class="btn danger" id="delete-server-btn">🗑️ Delete</button>
      </div>
      <button class="btn" id="reorder-btn" title="Toggle Reorder Mode">Reorder</button>
      <button class="btn" id="users-btn" title="Manage Users">👥 Users</button>
      <button class="btn" id="libraries-btn" title="Manage Libraries">📚 Libraries</button>
      <button class="btn" id="logs-btn" title="View System Logs" onclick="window.open('view_logs.php', 'SystemLogs')">📜 Logs</button>
      <?php endif; ?>
      <button class="btn" id="activeonly-btn" title="Show Only Active Servers">Active Only</button>
      <button class="btn" id="showall-btn" title="Toggle All Sessions">Show All</button>
      <button class="btn danger" onclick="window.location.href='logout.php'">Logout</button>
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
    <option value="jellyfin">Jellyfin</option>
    <option value="plex">Plex</option>
  </select>
  <input type="text" name="url" placeholder="Proxy URL" required>
  <input type="text" name="apiKey" placeholder="API Key (Emby/Jellyfin)">
  <input type="text" name="token" placeholder="Token (Plex)">
  <button type="submit" class="btn primary">Add Server</button>
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
              <label>&nbsp;</label>
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

<!-- Libraries Management Modal -->
<div id="libraries-modal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeLibrariesModal()">&times;</span>
    <div id="libraries-modal-body">
      <h2 style="margin-bottom: 20px;">Manage Libraries</h2>

      <div class="info-box" style="margin-bottom:20px; padding:15px; background:rgba(255,255,255,0.05); border-radius:6px;">
        <label style="display:block; margin-bottom:10px;">Select Server:</label>
        <select id="library-server-select" style="width:100%; max-width:300px; padding:10px; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:4px;">
           <option value="">-- Select a Server --</option>
        </select>
      </div>

      <div id="libraries-list-container" style="min-height:200px;">
         <div class="empty" id="libraries-loading" style="display:none;">Loading libraries...</div>
         <div class="empty" id="libraries-empty">Select a server to view libraries.</div>
         <div id="libraries-list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:12px;"></div>
      </div>
    </div>
  </div>
</div>


<script>
    const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
</script>
<script src="assets/js/main.js"></script>
</body>
</html>