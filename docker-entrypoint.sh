#!/bin/bash
set -e

# Configuration
CONFIG_DIR="/config"
WWW_DIR="/var/www/html"

# Default PUID/PGID to 33 (www-data) if not set
PUID=${PUID:-33}
PGID=${PGID:-33}

# Function to log messages
log() {
    echo "[Entrypoint] $1"
}

# Adjust www-data user/group if PUID/PGID changed
CURRENT_UID=$(id -u www-data)
CURRENT_GID=$(id -g www-data)

if [ "$PUID" != "$CURRENT_UID" ] || [ "$PGID" != "$CURRENT_GID" ]; then
    log "Adjusting www-data to UID:$PUID GID:$PGID"
    groupmod -o -g "$PGID" www-data
    usermod -o -u "$PUID" -g www-data www-data

    # Fix ownership of web root since we changed the user ID
    log "Updating ownership of $WWW_DIR"
    chown -R www-data:www-data "$WWW_DIR"
fi

# Ensure config directories exist
mkdir -p "$CONFIG_DIR"
mkdir -p "$CONFIG_DIR/keys"

# Persistent files
PERSIST_FILES=(
    "users.json"
    "servers.json"
    "activity.json"
    "watcher_state.json"
    "dashboard.log"
    "key.php"
)

log "Setting up persistent files..."

for FILE in "${PERSIST_FILES[@]}"; do
    SRC="$WWW_DIR/$FILE"
    DST="$CONFIG_DIR/$FILE"

    # If file exists in image (and isn't a symlink), move it to config to preserve default data
    if [ -f "$SRC" ] && [ ! -L "$SRC" ]; then
        if [ ! -f "$DST" ]; then
            log "Moving initial $FILE to storage"
            mv "$SRC" "$DST"
        else
            log "Removing static $FILE (using stored version)"
            rm "$SRC"
        fi
    fi

    # Create symlink if missing
    if [ ! -L "$SRC" ]; then
        ln -s "$DST" "$SRC"
    fi
done

# Handle keys directory
KEYS_SRC="$WWW_DIR/keys"
KEYS_DST="$CONFIG_DIR/keys"

if [ -d "$KEYS_SRC" ] && [ ! -L "$KEYS_SRC" ]; then
    # If keys dir exists and has content, move it
    if [ "$(ls -A $KEYS_SRC 2>/dev/null)" ]; then
        log "Migrating existing keys..."
        cp -r "$KEYS_SRC"/* "$KEYS_DST"/ 2>/dev/null || true
    fi
    rm -rf "$KEYS_SRC"
fi

if [ ! -L "$KEYS_SRC" ]; then
    ln -s "$KEYS_DST" "$KEYS_SRC"
fi

# Ensure permissions on config directory
log "Ensuring permissions on /config"
chown -R www-data:www-data "$CONFIG_DIR"

# Configure .htaccess for Apache in case it wasn't done in Dockerfile or overridden
# (Already handled in Dockerfile via sed on apache2.conf)

log "Starting Apache..."
exec "$@"
