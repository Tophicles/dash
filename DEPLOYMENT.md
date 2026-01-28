# Media Server Dashboard - Deployment Files

## Essential Files (Upload to `/var/www/html/dash/`)

### Core Application Files
1. **index.php** - Main dashboard interface
2. **auth.php** - Authentication system
3. **servers.json** - Server configuration (your existing file)
4. **proxy.php** - Server proxy handling
5. **encryption_helper.php** - Encryption utilities

### Authentication & User Management
6. **setup.php** - First-time setup wizard
7. **login.php** - Login page
8. **logout.php** - Logout handler
9. **manage_users.php** - User management API
10. **get_user.php** - Current user info API

### Server Management (Admin Only)
11. **add_server.php** - Add new servers
12. **update_server.php** - Edit existing servers
13. **delete_server.php** - Delete servers
14. **update_order.php** - Reorder servers via drag-drop
15. **git_update.php** - Update application via git

### Media Content APIs
16. **get_item_details.php** - Fetch detailed item metadata
17. **get_image.php** - Proxy images via HTTPS

### Configuration
18. **get_config.php** - Load and decrypt configuration
19. **.htaccess** - Apache configuration
20. **AUTH_README.md** - Documentation

### Generated Files (Created Automatically)
- **users.json** - User database (created during setup)

## Total File Count: 20 files + 1 auto-generated

## Deployment Checklist

### Initial Setup
```bash
cd /var/www/html/dash

# Upload all files listed above

# Set directory permissions
sudo chmod 775 /var/www/html/dash
sudo chown www-data:www-data /var/www/html/dash
```

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
- `git_update.php` - Application update
- `get_item_details.php` - Media metadata
- `get_image.php` - Image proxy
- `proxy.php` - Server communication

### Configuration
- `servers.json` - Your 10 media servers
- `users.json` - User accounts (auto-created)
- `.htaccess` - Web server rules
- `get_config.php` - Configuration loader

## Notes
- All files are optimized and production-ready
- No external dependencies required (requires `git` for update feature)
- Authentication system is fully integrated
- All admin features are protected
