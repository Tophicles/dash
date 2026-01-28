# Media Server Dashboard - Deployment Files

## Essential Files (Upload to `/var/www/html/dash/`)

### Core Application Files
1. **index.php** (57KB) - Main dashboard interface
2. **auth.php** (1.9KB) - Authentication system
3. **servers.json** (3.4KB) - Server configuration (your existing file)

### Authentication & User Management
4. **setup.php** (5.7KB) - First-time setup wizard
5. **login.php** (2.9KB) - Login page
6. **logout.php** (77B) - Logout handler
7. **manage_users.php** (5.1KB) - User management API
8. **get_user.php** (167B) - Current user info API
9. **get_active_users.php** (200B) - Active dashboard users API

### Server Management (Admin Only)
10. **add_server.php** (3.2KB) - Add new servers
11. **update_server.php** (2.7KB) - Edit existing servers
12. **delete_server.php** (910B) - Delete servers
13. **update_order.php** (1.1KB) - Reorder servers via drag-drop

### Media Content APIs
14. **get_item_details.php** (11KB) - Fetch detailed item metadata
15. **get_image.php** (2.7KB) - Proxy images via HTTPS

### Configuration
16. **.htaccess** (390B) - Apache configuration
17. **AUTH_README.md** (3.5KB) - Documentation

### Generated Files (Created Automatically)
- **users.json** - User database (created during setup)
- **activity.json** - User activity log

## Total File Count: 17 files + 2 auto-generated

## Deployment Checklist

### Initial Setup
```bash
cd /var/www/html/dash

# Upload all files listed above

# Set directory permissions
sudo chmod 775 /var/www/html/dash
sudo chown www-data:www-data /var/www/html/dash

# Set file permissions (if files exist manually, otherwise web server creates them)
# Note: In a production environment, use more restrictive permissions (e.g. 640 or 660) if possible.
sudo touch activity.json users.json servers.json
sudo chmod 666 activity.json users.json servers.json

### First Access
1. Navigate to `https://bntv.ca/dash/`
2. Setup wizard appears automatically
3. Create admin account
4. Start using the dashboard!

## File Purposes Summary

### User-Facing Pages
- `index.php` - Main dashboard
- `login.php` - Login screen
- `setup.php` - First-time setup

### Backend APIs
- `auth.php` - Core authentication
- `manage_users.php` - User CRUD operations
- `add_server.php`, `update_server.php`, `delete_server.php` - Server management
- `get_item_details.php` - Media metadata
- `get_image.php` - Image proxy

### Configuration
- `servers.json` - Your 10 media servers
- `users.json` - User accounts (auto-created)
- `.htaccess` - Web server rules

## Notes
- All files are optimized and production-ready
- No external dependencies required
- Authentication system is fully integrated
- All admin features are protected
