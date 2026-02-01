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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>

<!-- Top Menu Bar -->
<div class="top-bar">
  <div class="top-bar-header" id="menu-header">
    <div class="header-section left">
      <div id="header-reload-btn" title="Reload Dashboard" style="cursor: pointer;">
        <span class="header-badge">Home</span>
      </div>
      <span class="user-info" style="margin-left: 10px;">
        <span class="user-desktop"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars(ucwords($user['username'])); ?> (<?php echo htmlspecialchars($user['role']); ?>)</span>
        <span class="user-mobile">MENU</span>
      </span>
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
      <?php if ($isAdmin): ?>
      <button class="btn primary" id="toggle-form">Add Server</button>
      <?php endif; ?>
    </div>
    <div class="top-bar-right">
      <?php if ($isAdmin): ?>
      <div class="server-actions" id="server-actions">
        <button class="btn" id="edit-server-btn">Edit</button>
        <button class="btn danger" id="delete-server-btn">Delete</button>
      </div>
      <button class="btn" id="reorder-btn" title="Toggle Reorder Mode">Reorder</button>
      <button class="btn" id="users-btn" title="Manage Users">Users</button>
      <button class="btn" id="ssh-keys-nav-btn" title="SSH Key Manager">SSH Keys</button>
      <button class="btn" id="logs-btn" title="View System Logs" onclick="window.open('view_logs.php', 'SystemLogs')">Logs</button>
      <?php endif; ?>
      <button class="btn danger" onclick="window.location.href='logout.php'">Logout</button>
    </div>
  </div>
</div>

<!-- SSH Manager Modal -->
<div id="ssh-modal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <span class="modal-close" onclick="closeSSHModal()">&times;</span>
    <h2 style="margin-bottom: 20px;">SSH Key Management</h2>

    <div class="ssh-status-box">
      <label>Public Key (for authorized_keys)</label>
      <textarea id="ssh-public-key" readonly class="ssh-key-display" placeholder="No key generated yet."></textarea>
      <button class="btn" id="ssh-copy-btn" style="margin-top: 8px;">Copy to Clipboard</button>
    </div>

    <div class="ssh-warning-box">
      <div class="warning-title"><i class="fa-solid fa-triangle-exclamation"></i> WARNING</div>
      <p>Generating a new key pair will overwrite any existing keys. You will need to update the <code>authorized_keys</code> file on all managed servers with the new public key.</p>
      <p>This allows this dashboard server to execute remote commands (like restarting services) on your media servers.</p>

      <div class="ssh-agreement">
        <input type="checkbox" id="ssh-agree-chk">
        <label for="ssh-agree-chk">I acknowledge the security risks involved in generating and storing private keys.</label>
      </div>

      <button class="btn danger" id="ssh-generate-btn" disabled>Generate New Key Pair</button>
    </div>
  </div>
</div>

<!-- Loading Indicator -->
<div id="loading-indicator" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(26, 26, 26, 0.95); display: flex; align-items: center; justify-content: center; z-index: 9999;">
  <div style="text-align: center;">
    <div style="font-size: 3rem; margin-bottom: 20px;"><i class="fa-solid fa-spinner fa-spin"></i></div>
    <div style="font-size: 1.2rem; color: #e0e0e0; margin-bottom: 10px;">Loading dashboard...</div>
    <div id="loading-progress" style="font-size: 0.9rem; color: #888;"></div>
  </div>
</div>

<!-- Server Grid View -->
<div id="server-view" class="view-container visible">
  <div class="search-container">
    <input type="text" id="server-search" placeholder="Filter servers...">
  </div>
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
  <div id="server-stats" style="text-align:center; color:var(--muted); font-size:0.9rem; margin-bottom:10px; display:none; font-family: monospace;"></div>
  <div id="server-libraries-container" style="margin-bottom:16px; display:none;"></div>
  <div class="search-container">
    <input type="text" id="session-search" placeholder="Filter sessions...">
  </div>
  <div id="sessions" class="session-grid"></div>
</div>

<!-- Modal for Add/Edit Server -->
<div id="server-modal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeServerModal()">&times;</span>
    <div id="server-modal-body">
      <h2 style="margin-bottom: 20px;" id="server-modal-title">Add Server</h2>
      <form id="add-server-form">
        <div class="server-form-group">
          <label>Server Name</label>
          <input type="text" name="name" required>
        </div>
        <div class="server-form-group">
          <label>Server Type</label>
          <select name="type" id="server-type-select">
            <option value="emby">Emby</option>
            <option value="jellyfin">Jellyfin</option>
            <option value="plex">Plex</option>
          </select>
        </div>
        <div class="server-form-group">
          <label>Proxy URL</label>
          <div class="url-input-group">
            <select name="protocol" id="server-protocol-select">
              <option value="http://">http://</option>
              <option value="https://">https://</option>
            </select>
            <input type="text" name="url_path" id="server-url-input" required>
          </div>
        </div>
        <div class="server-form-group" id="group-apikey">
          <label>API Key (Emby/Jellyfin)</label>
          <input type="text" name="apiKey">
        </div>
        <div class="server-form-group" id="group-token">
          <label>Token (Plex)</label>
          <input type="text" name="token">
        </div>

        <!-- OS Configuration -->
        <div style="margin-top: 20px; border-top: 1px solid var(--border); padding-top: 15px;">
            <h3 style="margin-bottom: 10px; font-size: 1rem; color: var(--accent);">Operating System</h3>

            <div class="server-form-group">
                <label>OS Type</label>
                <select name="os_type" id="server-os-select">
                    <option value="linux">Linux</option>
                    <option value="docker">Docker</option>
                    <option value="windows">Windows</option>
                    <option value="macos">macOS</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="server-form-group" id="ssh-port-group">
                <label>SSH Port (Linux Only)</label>
                <input type="number" name="ssh_port" value="22" placeholder="22">
            </div>
        </div>

        <div class="server-form-group" style="margin-top: 10px;">
          <button type="submit" class="btn primary" id="server-submit-btn">Add Server</button>
        </div>
      </form>
    </div>
  </div>
</div>

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

<!-- Update Server Modal -->
<div id="update-modal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <span class="modal-close" onclick="closeUpdateModal()">&times;</span>

    <div id="update-modal-body" style="padding: 20px;">
        <h2 style="margin-bottom: 20px;">Update Server</h2>

        <div class="server-form-group">
            <label>Update Branch</label>
            <div class="branch-selector">
                <input type="hidden" id="update-branch-select" value="stable">
                <button type="button" class="branch-btn active" data-branch="stable">
                    <i class="fa-solid fa-box"></i> Stable
                </button>
                <button type="button" class="branch-btn" data-branch="beta">
                    <i class="fa-solid fa-flask"></i> Beta
                </button>
            </div>
            <div style="font-size: 0.85rem; color: #888; margin-top: 10px;">
                Downloading package directly and installing via <code>dpkg</code>.
            </div>
        </div>

        <div class="log-container" style="background: #111; color: #0f0; padding: 10px; padding-bottom: 20px; border-radius: 4px; font-family: monospace; height: 300px; overflow-y: auto; margin: 20px 0; border: 1px solid #333; scroll-behavior: smooth;">
            <pre id="update-log-output" style="margin: 0; white-space: pre-wrap; font-size: 0.85rem;">Ready to update...</pre>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button class="btn" onclick="closeUpdateModal()">Close</button>
            <button class="btn primary" id="start-update-btn">Start Update</button>
        </div>
    </div>
  </div>
</div>

<!-- Custom Alert/Confirm Modal -->
<div id="custom-modal" class="modal" style="z-index: 10000;">
  <div class="modal-content" style="max-width: 400px; text-align: center;">
    <div id="custom-modal-title" style="font-size: 1.2rem; font-weight: bold; margin-bottom: 10px;">Title</div>
    <div id="custom-modal-message" style="margin-bottom: 20px; color: #ccc; line-height: 1.5;">Message</div>
    <div id="custom-modal-actions" style="display: flex; justify-content: center; gap: 10px;">
      <button id="custom-modal-cancel" class="btn">Cancel</button>
      <button id="custom-modal-confirm" class="btn primary">Confirm</button>
    </div>
  </div>
</div>

<script>
    const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
</script>
<script src="assets/js/main.js?v=<?= time() ?>"></script>
</body>
</html>