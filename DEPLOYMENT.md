# Deployment Guide - MultiDash

This guide outlines the files required and the steps needed to deploy the MultiDash application to a production environment.

## File Manifest (Upload to `/var/www/html/dash/`)

### Core Application
*   `index.php` - Main dashboard interface.
*   `auth.php` - Authentication logic.
*   `logging.php` - Centralized logging system.
*   `log_event.php` - API for logging client-side events.
*   `proxy.php` - API proxy for communicating with Plex/Emby servers.
*   `path_helper.php` - Directory and path definitions.
*   `encryption_helper.php` - Encryption logic for API keys/tokens.
*   `get_config.php` - Fetches public configuration.

### Authentication & Setup
*   `setup.php` - First-time setup wizard (creates initial admin).
*   `login.php` - Login page.
*   `logout.php` - Session destruction.
*   `manage_users.php` - User management API (CRUD).
*   `get_user.php` - Current user context API.
*   `get_active_users.php` - Dashboard presence API.

### Server Management
*   `add_server.php` - API to add new servers.
*   `update_server.php` - API to update existing servers.
*   `delete_server.php` - API to remove servers.
*   `update_order.php` - API to save server sort order.
*   `library_actions.php` - API for listing and scanning libraries.

### System & Maintenance
*   `backup.php` - Backup creation and restoration logic.
*   `restore_helper.php` - Shared restoration logic.
*   `reset.php` - Factory reset logic (Panic button).
*   `ssh_manager.php` - SSH key generation and management.
*   `ssh_helper.php` - SSH execution helper.

### Media & Logs
*   `get_item_details.php` - Fetches metadata for media items.
*   `get_image.php` - Proxies images securely.
*   `view_logs.php` - Admin interface for viewing system logs.

### Configuration & Assets
*   `.htaccess` - Apache configuration for security.
*   `assets/` - Directory containing CSS, JS, and Images.
    *   `assets/css/` - Stylesheets.
    *   `assets/js/` - JavaScript logic.
    *   `assets/img/` - Logos and favicons.
*   `os_helpers/` - Directory containing Linux setup scripts.
*   `screenshots/` - Directory containing UI images (optional, for README).
*   `README.md` - Documentation.

## Auto-Generated Files (Do Not Upload)
These files are created automatically by the application but require write permissions.
*   `key.php` - Unique encryption key (auto-generated in root).
*   `dashboard.log` - Application event logs.
*   `db/` - Database directory:
    *   `users.json` - User database.
    *   `servers.json` - Server configuration.
    *   `activity.json` - Active dashboard user tracking.
    *   `watcher_state.json` - State tracking for media watcher logging.
*   `keys/` - Directory storing generated SSH key pairs (protected).

---

## Deployment Steps

### 1. Upload Files
Upload all files listed in the **File Manifest** to your web server directory (e.g., `/var/www/html/dash`). Ensure the `assets/` directory and its subdirectories are preserved.

### 2. Configure Permissions
The web server user (usually `www-data` or `apache`) needs **write access** to the installation directory to create and update the JSON databases and log files.

```bash
cd /var/www/html/dash

# Set directory ownership
sudo chown -R www-data:www-data .

# Set directory permissions (allow write)
sudo chmod 755 .

# Note: The application will attempt to create the `db/` and `keys/` directories.
# Ensure the parent directory is writable by the web server user.
```

### 3. System Requirements
*   **PHP Extensions:** `php-curl`, `php-openssl`, `php-zip` (required for Backup/Restore).
*   **System Tools:** `openssh-client` (required for SSH server management).

### 4. Web Server Configuration

#### Apache
Ensure `.htaccess` overrides are enabled for the directory. The included `.htaccess` file protects sensitive files (`db/*.json`, `keys/*`, `*.log`, `key.php`) from direct web access.

#### Nginx
If using Nginx, add the following rules to your server block to replicate the `.htaccess` protection. **Adjust the path `/dash` to match your installation URL.**

```nginx
location /dash {
    # Deny access to sensitive files/directories
    location ~ ^/dash/(db/|keys/|key\.php|dashboard\.log) {
        deny all;
        return 403;
    }

    # Deny direct access to all PHP files by default to enforce whitelist (Optional but Recommended)
    # OR just allow execution of all PHP files if you trust the source.
    # The regex below allows only valid entry points:
    location ~ \.php$ {
         location ~ ^/dash/(index|login|setup|logout|proxy|backup|reset|ssh_manager|log_event|library_actions|view_logs|get_[a-z_]+|add_[a-z_]+|update_[a-z_]+|delete_[a-z_]+|manage_[a-z_]+)\.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php-fpm.sock;
        }
        # Deny execution of internal helpers (e.g. auth.php, path_helper.php) directly via URL if not imported
        # (Though they usually handle direct access gracefully, it's safer to block)
    }
}
```

### 5. Initial Setup
1.  Navigate to your dashboard URL (e.g., `https://your-domain.com/dash/`).
2.  You will be automatically redirected to the **Setup Wizard**.
3.  Create your **Administrator Account**.
    *   *Alternatively, if you have a backup ZIP from a previous installation, use the "Restore from Backup" option.*
4.  The system will automatically initialize `db/users.json`, `db/activity.json`, and generate an encryption key in `key.php`.

### 6. Troubleshooting
*   **Permission Errors:** Check `dashboard.log` or your web server's error log. Ensure the directory is writable.
*   **Encryption Key:** If migrating `servers.json` to a new host, you **MUST** copy `key.php` manually (or use the Backup & Restore feature), or all encrypted API keys/tokens will become unreadable.
*   **SSH Errors:** Ensure the `www-data` user has permissions to run `ssh`. If using SELinux, you may need to allow HTTPD scripts to connect to the network.
