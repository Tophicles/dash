FROM php:8.2-apache

# ----------------------------
# Install system dependencies
# ----------------------------
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# ----------------------------
# Install PHP extensions
# ----------------------------
RUN docker-php-ext-install curl zip

# ----------------------------
# Apache setup
# ----------------------------
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# ----------------------------
# Persistent config directory
# ----------------------------
RUN mkdir -p /config \
    && chmod -R 777 /config   # writable by nobody

ENV CONFIG_DIR=/config

# ----------------------------
# Copy application files
# ----------------------------
COPY . /var/www/html/

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

# ----------------------------
# Expose HTTP port
# ----------------------------
EXPOSE 80

# ----------------------------
# Run as nobody:users (Unraid-friendly)
# ----------------------------
USER nobody:users

# Start Apache
CMD ["apache2-foreground"]
