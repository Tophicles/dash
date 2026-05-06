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
      <span class="user-info" id="user-info-btn" style="cursor: pointer;" title="Show Active Users">
        <span class="user-desktop"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars(ucwords($user['username'])); ?> (<?php echo htmlspecialchars($user['role']); ?>)</span>
        <span class="user-mobile">MENU</span>
      </span>
    </div>
    <div class="header-section center">
      <span class="header-badge" id="header-clock">--:--</span>
    </div>
    <div class="header-section right" id="header-clock-btn">
      <button class="btn header-btn" id="user-search-nav-btn" title="Media Users" style="margin-right: 8px;">
          <i class="fa-solid fa-users"></i> <span class="btn-text">Users</span>
      </button>
      <button class="btn header-btn" id="feedback-btn" title="Feedback" style="margin-right: 8px;">
          <i class="fa-regular fa-comment-dots"></i> <span class="btn-text">Feedback</span>
      </button>
      <button class="btn header-btn" id="donate-btn" title="Donate">
          <i class="fa-solid fa-heart"></i> <span class="btn-text">Donate</span>
      </button>
      <div class="menu-container">
        <button class="btn header-btn" id="menu-toggle-btn" title="Menu">
          <i class="fa-solid fa-bars"></i> <span class="btn-text">MENU</span>
        </button>
        <div class="menu-dropdown" id="menu-dropdown">
          <div class="menu-item" id="theme-toggle-btn">
            <i class="fa-solid fa-moon"></i> <span>Toggle Theme</span>
          </div>
          <?php if ($isAdmin): ?>
          <div class="menu-item" id="toggle-form">
            <i class="fa-solid fa-plus"></i> <span>Add Server</span>
          </div>
          <div class="menu-item" id="reorder-btn">
            <i class="fa-solid fa-sort"></i> <span>Reorder Servers</span>
          </div>
          <div class="menu-item" id="users-btn">
            <i class="fa-solid fa-users"></i> <span>MultiDash Users</span>
          </div>
          <div class="menu-item" id="ssh-keys-nav-btn">
            <i class="fa-solid fa-key"></i> <span>SSH Keys</span>
          </div>
          <div class="menu-item" id="global-scan-menu-btn">
            <i class="fa-solid fa-arrows-rotate"></i> <span>Global Library Scan</span>
          </div>
          <div class="menu-item" id="bulk-update-menu-btn">
            <i class="fa-solid fa-circle-up"></i> <span>Bulk Server Update</span>
          </div>
          <div class="menu-item" id="logs-btn" onclick="window.open('view_logs.php', 'SystemLogs')">
            <i class="fa-solid fa-file-lines"></i> <span>System Logs</span>
          </div>
          <div class="menu-item" id="backup-nav-btn">
            <i class="fa-solid fa-floppy-disk"></i> <span>Backup & Restore</span>
          </div>
          <div class="menu-item danger" id="panic-btn">
            <i class="fa-solid fa-radiation"></i> <span>Panic! (Reset)</span>
          </div>
          <?php endif; ?>
          <div class="menu-divider"></div>
          <div class="menu-item danger" onclick="window.location.href='logout.php'">
            <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Feedback Modal (GitHub Issues) -->
<div id="feedback-modal" class="modal">
  <div class="modal-content" style="max-width: 500px;">
    <span class="modal-close" onclick="closeFeedbackModal()">&times;</span>
    <h2 style="margin-bottom: 20px;"><i class="fa-brands fa-github"></i> Project Feedback</h2>
    <p style="color:var(--muted); margin-bottom:20px; line-height: 1.5;">
        Submit bugs or feature requests directly to the project's GitHub repository.
        This ensures the developer sees it and can track progress.
    </p>
    <form id="feedback-form">
        <div class="server-form-group">
            <label>Feedback Type</label>
            <select name="type" id="feedback-type">
                <option value="suggestion">Feature Request / Suggestion</option>
                <option value="bug">Bug Report</option>
                <option value="other">General Feedback</option>
            </select>
        </div>
        <div class="server-form-group">
            <label>Message / Details</label>
            <textarea name="message" id="feedback-message" rows="6" required style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--border); color:var(--text); padding:10px; border-radius:6px; resize:vertical; box-sizing: border-box;" placeholder="Describe your suggestion or bug..."></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:15px; gap: 10px;">
            <button type="button" class="btn" onclick="closeFeedbackModal()">Cancel</button>
            <button type="submit" class="btn primary"><i class="fa-solid fa-up-right-from-square"></i> Open GitHub Issue</button>
        </div>
    </form>
  </div>
</div>

<!-- Donate Modal -->
<div id="donate-modal" class="modal">
  <div class="modal-content" style="max-width: 400px;">
    <span class="modal-close" onclick="closeDonateModal()">&times;</span>
    <h2 style="margin-bottom: 20px;"><i class="fa-solid fa-heart" style="color: #e91e63;"></i> Support Project</h2>
    <p style="color: var(--muted); margin-bottom: 20px; line-height: 1.6;">
        This is a private project maintained with love. Donations help cover server costs and fuel further development. Thank you!
    </p>
    <div class="server-form-group" style="text-align: center; margin-bottom: 30px;">
      <label style="display: block; margin-bottom: 15px; font-size: 1.1rem; color: var(--text);">Donation Amount (USD)</label>
      <div class="donate-input-wrapper">
          <span class="currency-symbol">$</span>
          <input type="number" id="donate-amount" value="5" min="1" step="1" class="donate-input">
      </div>
    </div>
    <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
      <button class="btn primary" onclick="processDonation()" style="width: 100%;">
        <i class="fa-brands fa-paypal"></i> Donate via PayPal
      </button>
    </div>
  </div>
</div>

<!-- Server Setup Modal -->
<div id="server-setup-modal" class="modal">
  <div class="modal-content" style="max-width: 650px;">
    <span class="modal-close" onclick="closeServerSetupModal()">&times;</span>
    <h2 style="margin-bottom: 20px;">Connect Server via SSH</h2>

    <div class="info-box">
      <label class="info-label">Setup Command</label>
      <div class="info-text">Run this unified command on your <strong>Linux</strong> media server to install the agent and authorized key:</div>
      <div class="code-block" id="setup-command-display">
        Loading command...
      </div>
      <button class="btn" onclick="copyToClipboard('setup-command-display', this)">Copy Command</button>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
      <button class="btn" onclick="closeServerSetupModal()">Close</button>
      <button class="btn primary" id="setup-verify-btn">Verify Connection</button>
    </div>
  </div>
</div>

<!-- SSH Connected Modal -->
<div id="ssh-connected-modal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <span class="modal-close" onclick="closeSSHConnectedModal()">&times;</span>
    <h2 style="margin-bottom: 20px; color: #81c784;"><i class="fa-solid fa-shield-halved"></i> Secure Connection Active</h2>

    <div class="modal-description">
      <p>This server is connected using a dedicated SSH key pair (<code>mediasvc</code> user). This method is secure because:</p>
      <ul>
        <li>No passwords are stored or transmitted.</li>
        <li>The connection is restricted to specific commands (updates, restarts, stats) via <code>sudoers</code>.</li>
        <li>Interactive login for this user is disabled.</li>
      </ul>
    </div>

    <div class="danger-box">
      <label class="danger-label">Uninstall / Disconnect</label>
      <div class="info-text">To remove the dashboard agent and revoke access, run this command on your server:</div>
      <div class="code-block" id="uninstall-command-display">
        Loading command...
      </div>
      <button class="btn" onclick="copyToClipboard('uninstall-command-display', this)">Copy Command</button>
    </div>

    <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
      <button class="btn" onclick="closeSSHConnectedModal()">Close</button>
    </div>
  </div>
</div>

<!-- SSH Manager Modal -->
<div id="ssh-modal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <span class="modal-close" onclick="closeSSHModal()">&times;</span>
    <h2 style="margin-bottom: 20px;">SSH Key Management</h2>

    <div class="ssh-status-box">
      <label>SSH Username (Global for all Linux servers)</label>
      <div style="display:flex; gap:8px; margin-bottom: 15px;">
        <input type="text" id="ssh-global-user" class="btn" style="flex:1; text-align:left; cursor:text; background:rgba(255,255,255,0.05); border:1px solid var(--border);" placeholder="mediasvc">
        <button class="btn primary" id="ssh-user-save-btn">Save User</button>
      </div>

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
      <div class="list-label" id="online-users-label">Now Watching</div>
      <div class="user-list-content">
        <span style="color:var(--muted);font-size:0.9rem;">No users online</span>
      </div>
    </div>
  </div>
</div>


<!-- Sessions View -->
<div id="sessions-view" class="view-container">
  <button class="back-btn" id="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Servers</button>
  <div id="server-title"></div>
  <div id="server-stats" style="text-align:center; color:var(--muted); font-size:0.9rem; margin-bottom:10px; display:none; font-family: monospace;"></div>
  <div id="server-libraries-container" style="margin-bottom:16px; display:none;"></div>
  <div id="server-scans-container" style="margin-bottom:16px; display:none;"></div>
  <div class="search-container">
    <input type="text" id="session-search" placeholder="Filter sessions...">
  </div>
  <div id="sessions" class="session-grid"></div>
</div>

<!-- Media User Modal -->
<div id="media-user-modal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <span class="modal-close" onclick="closeMediaUserModal()">&times;</span>
    <div id="media-user-modal-body">
        <div style="text-align:center; padding: 40px;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem; color: var(--accent);"></i>
            <p style="margin-top: 15px; color: var(--muted);">Loading user details...</p>
        </div>
    </div>
  </div>
</div>

<!-- User Search Modal -->
<div id="user-search-modal" class="modal">
  <div class="modal-content" style="max-width: 650px;">
    <span class="modal-close" onclick="closeUserSearchModal()">&times;</span>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h2><i class="fa-solid fa-users"></i> Media Users</h2>
      <button class="btn primary" onclick="openMigrateUserModal()">
        <i class="fa-solid fa-exchange-alt"></i> Migrate User
      </button>
    </div>

    <div class="search-container" style="margin-bottom: 15px;">
        <input type="text" id="user-search-input" placeholder="Start typing a name..." autocomplete="off">
    </div>

    <div id="user-search-results" style="display: flex; flex-direction: column; gap: 8px; max-height: 400px; overflow-y: auto; padding-right: 5px;">
        <div style="text-align:center; color: #888; padding: 20px;">Open search to load users...</div>
    </div>


    <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
        <button class="btn" onclick="closeUserSearchModal()">Close</button>
    </div>
  </div>
</div>

<!-- Active Sessions Modal -->
<div id="active-sessions-modal" class="modal">
  <div class="modal-content" style="max-width: 400px;">
    <span class="modal-close" onclick="closeActiveSessionsModal()">&times;</span>
    <h2 style="margin-bottom: 20px;">Active Dashboard Users</h2>
    <div id="active-sessions-list" style="display: flex; flex-direction: column; gap: 8px;">
        <div style="text-align:center; color: #888;">Loading...</div>
    </div>
    <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
        <button class="btn" onclick="closeActiveSessionsModal()">Close</button>
    </div>
  </div>
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
          <input type="password" name="apiKey">
        </div>
        <div class="server-form-group" id="group-token">
          <label>Token (Plex)</label>
          <input type="password" name="token">
        </div>

        <!-- Update Branch Configuration -->
        <div class="server-form-group">
            <label>Update Branch</label>
            <div class="branch-selector" id="server-branch-selector">
                <input type="hidden" name="branch" id="server-branch-select" value="stable">
                <button type="button" class="branch-btn active" data-branch="stable">
                    <i class="fa-solid fa-box"></i> Stable
                </button>
                <button type="button" class="branch-btn" data-branch="beta">
                    <i class="fa-solid fa-flask"></i> Beta
                </button>
            </div>
        </div>

        <!-- OS Configuration -->
        <div style="margin-top: 20px; border-top: 1px solid var(--border); padding-top: 15px;">
            <h3 style="margin-bottom: 10px; font-size: 1rem; color: var(--accent);">Operating System</h3>

            <div class="server-form-group">
                <label>OS Type</label>
                <select name="os_type" id="server-os-select">
                    <option value="linux">Linux</option>
                    <option value="windows">Windows</option>
                    <option value="docker">Docker</option>
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

<!-- Backup Modal -->
<div id="backup-modal" class="modal">
  <div class="modal-content" style="max-width: 500px;">
    <span class="modal-close" onclick="closeBackupModal()">&times;</span>
    <h2 style="margin-bottom: 20px;">Backup & Restore</h2>

    <div class="info-box">
        <h3><i class="fa-solid fa-download"></i> Create Backup</h3>
        <p style="margin-bottom: 10px; font-size: 0.9rem; color: #ccc;">Download a ZIP archive containing all servers, users, and configuration keys.</p>
        <button class="btn primary" id="download-backup-btn" style="width: 100%;">Download Backup</button>
    </div>

    <div class="danger-box">
        <h3><i class="fa-solid fa-upload"></i> Restore Backup</h3>
        <p style="margin-bottom: 10px; font-size: 0.9rem; color: #ccc;">Upload a backup file to restore settings. <strong>This will overwrite current configuration.</strong></p>

        <input type="file" id="restore-file-input" accept=".zip" class="file-input">
        <label for="restore-file-input" class="file-label">
            <i class="fa-solid fa-file-zipper"></i> Choose Backup File
        </label>
        <div id="restore-file-name" class="file-name"></div>

        <button class="btn danger" id="restore-backup-btn" style="width: 100%; margin-top: 10px;" disabled>Upload & Restore</button>
    </div>
  </div>
</div>

<!-- User Management Modal -->
<div id="users-modal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeUsersModal()">&times;</span>
    <div id="users-modal-body">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>MultiDash Users</h2>
        <button class="btn primary" onclick="openMigrateUserModal()">
          <i class="fa-solid fa-exchange-alt"></i> Migrate Media User
        </button>
      </div>
      
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

<!-- Migrate User Modal -->
<div id="migrate-user-modal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <span class="modal-close" onclick="closeMigrateUserModal()">&times;</span>
    <h2 style="margin-bottom: 20px;"><i class="fa-solid fa-exchange-alt"></i> Migrate Media User</h2>

    <div style="background: var(--surface-light); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
      <div class="server-form-group">
        <label>Source Server (Plex, Emby, Jellyfin)</label>
        <select id="migrate-source-server" onchange="loadMigrateSourceUsers()"></select>
      </div>
      <div class="server-form-group" style="margin-top: 10px;">
        <label>Source User</label>
        <select id="migrate-source-user">
            <option value="">Select a user...</option>
        </select>
      </div>
    </div>

    <div style="background: var(--surface-light); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
      <div class="server-form-group">
        <label>Destination Server (Emby/Jellyfin Only)</label>
        <select id="migrate-target-server" onchange="loadMigrateTargetUsers()"></select>
      </div>

      <div style="margin-top: 15px; margin-bottom: 10px;">
          <label style="display:inline-flex; align-items:center; cursor:pointer; margin-right: 15px;">
              <input type="radio" name="migrate-user-type" value="existing" checked onchange="toggleMigrateUserFields()">
              <span style="margin-left:5px;">Map to Existing User</span>
          </label>
          <label style="display:inline-flex; align-items:center; cursor:pointer;">
              <input type="radio" name="migrate-user-type" value="new" onchange="toggleMigrateUserFields()">
              <span style="margin-left:5px;">Create New User</span>
          </label>
      </div>

      <div class="server-form-group" id="migrate-existing-user-group">
        <label>Target User</label>
        <select id="migrate-target-user">
            <option value="">Select a user...</option>
        </select>
      </div>

      <div id="migrate-new-user-group" style="display:none; gap: 10px; flex-direction: column;">
          <div class="server-form-group">
            <label>New Username</label>
            <input type="text" id="migrate-new-username" placeholder="e.g. JohnDoe">
          </div>
          <div class="server-form-group">
            <label>Password (Optional)</label>
            <input type="password" id="migrate-new-password">
          </div>
      </div>
    </div>

    <div class="log-container" id="migrate-log-container" style="display:none; background: #111; color: #0f0; padding: 10px; border-radius: 4px; font-family: monospace; height: 150px; overflow-y: auto; margin-bottom: 20px; border: 1px solid #333; font-size: 0.85rem;">
        <div id="migrate-log-output"></div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button class="btn" onclick="closeMigrateUserModal()">Cancel</button>
        <button class="btn primary" id="start-migrate-btn" onclick="startMigration()">Start Migration</button>
    </div>
  </div>
</div>

<!-- Update Server Modal -->
<div id="update-modal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <span class="modal-close" onclick="closeUpdateModal()">&times;</span>

    <div id="update-modal-body" style="padding: 20px;">
        <h2 style="margin-bottom: 20px;">Update Server</h2>

        <div id="update-modal-tabs" style="display:none; margin-bottom:15px; flex-wrap:wrap; gap:5px; border-bottom:1px solid var(--border); padding-bottom:10px;"></div>

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
  <div class="modal-content confirm-modal-content">
    <div id="custom-modal-title" class="confirm-modal-title">Title</div>
    <div id="custom-modal-message" class="confirm-modal-message">Message</div>
    <div id="custom-modal-actions" class="confirm-modal-actions">
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