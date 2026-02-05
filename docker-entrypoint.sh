#!/bin/bash
set -e

# Fix permissions for config directory if it exists
if [ -d "/config" ]; then
    chown -R www-data:www-data /config
fi

# Execute the original entrypoint logic
exec docker-php-entrypoint "$@"
