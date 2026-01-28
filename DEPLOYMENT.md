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
11. **get_active_users.php** (200B) - Active dashboard users API

### Server Management (Admin Only)
9. **add_server.php** (3.2KB) - Add new servers
10. **update_server.php** (2.7KB) - Edit existing servers
11. **delete_server.php** (910B) - Delete servers
12. **update_order.php** (1.1KB) - Reorder servers via drag-drop

### Media Content APIs
13. **get_item_details.php** (11KB) - Fetch detailed item metadata
14. **get_image.php** (2.7KB) - Proxy images via HTTPS

### Configuration
15. **.htaccess** (390B) - Apache configuration
16. **AUTH_README.md** (3.5KB) - Documentation

### Generated Files (Created Automatically)
- **users.json** - User database (created during setup)

## Total File Count: 16 files + 1 auto-generated

## Deployment Checklist

### Initial Setup
```bash
cd /var/www/html/dash

# Upload all 16 files listed above

# Set directory permissions
sudo chmod 775 /var/www/html/dash
sudo chown www-data:www-data /var/www/html/dash
sudo chown 666 activity.json
sudo chown 666 users.json
sudo chown 666 servers.json

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
- `servers.json` - Your media servers
- `users.json` - User accounts (auto-created)
- `.htaccess` - Web server rules

## Notes
- All files are optimized and production-ready
- No external dependencies required
- Authentication system is fully integrated
- All admin features are protected
