# Deployment Guide - MultiDash

This guide outlines the files required and the steps needed to deploy the MultiDash application to a production environment.

## File Manifest (Upload to `/var/www/html/dash/`)

### Core Application
*   `index.php` - Main dashboard interface.
*   `auth.php` - Authentication logic.
*   `logging.php` - Centralized logging system.
*   `proxy.php` - API proxy for communicating with Plex/Emby servers.
*   `encryption_helper.php` - Encryption logic for API keys/tokens.

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
*   `screenshots/` - Directory containing UI images (optional, for README).
*   `README.md` - Documentation.

## Auto-Generated Files (Do Not Upload)
These files are created automatically by the application but require write permissions:
*   `users.json` - User database.
*   `servers.json` - Server configuration.
*   `activity.json` - Active dashboard user tracking.
*   `watcher_state.json` - State tracking for media watcher logging.
*   `dashboard.log` - Application event logs.
*   `key.php` - Unique encryption key (auto-generated).

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

# Note: The application will attempt to create files with 0666 permissions.
# Ensure the parent directory is writable by the web server user.
```

### 3. Web Server Configuration

#### Apache
Ensure `.htaccess` overrides are enabled for the directory. The included `.htaccess` file protects sensitive files (`*.json`, `*.log`, `key.php`) from direct web access.

#### Nginx
If using Nginx, add the following rules to your server block to replicate the `.htaccess` protection:

```nginx
location /dash {
    # Deny access to sensitive files
    location ~ \.(json|log|php)$ {
        # Allow specific PHP entry points
        location ~ ^/dash/(index|login|setup|logout|proxy|get_|add_|update_|delete_|manage_|view_logs|library_actions)\.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php-fpm.sock;
        }
        # Deny everything else (including key.php and json/log files)
        deny all;
        return 403;
    }
}
```

### 4. Initial Setup
1.  Navigate to your dashboard URL (e.g., `https://your-domain.com/dash/`).
2.  You will be automatically redirected to the **Setup Wizard**.
3.  Create your **Administrator Account**.
4.  The system will automatically initialize `users.json`, `activity.json`, and generate an encryption key.

### 5. Troubleshooting
*   **Permission Errors:** Check `dashboard.log` or your web server's error log. Ensure the directory is writable.
*   **Encryption Key:** If migrating `servers.json` to a new host, you **MUST** copy `key.php` manually, or all encrypted API keys/tokens will become unreadable.
