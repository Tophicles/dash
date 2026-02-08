#!/bin/bash
set -e

# Handle PUID/PGID if provided (e.g. for Unraid)
if [ -n "$PUID" ] && [ -n "$PGID" ]; then
    echo "Updating www-data to UID: $PUID, GID: $PGID"

    # Update group ID
    groupmod -o -g "$PGID" www-data || echo "Warning: Failed to update GID"

    # Update user ID
    usermod -o -u "$PUID" -g www-data www-data || echo "Warning: Failed to update UID"

    # Ensure proper ownership of web root
    if [ -d "/var/www/html" ]; then
        chown -R www-data:www-data /var/www/html
    fi
fi

# Fix permissions for config directory if it exists
if [ -d "/config" ]; then
    chown -R www-data:www-data /config
fi

# Execute the original entrypoint logic
exec docker-php-entrypoint "$@"
