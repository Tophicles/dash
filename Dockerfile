FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install curl zip

# Apache setup
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf  # suppress warning

# Create a persistent config directory
RUN mkdir -p /config \
    && chown -R www-data:www-data /config

# Set environment variable
ENV CONFIG_DIR=/config

# Copy app files
COPY . /var/www/html/

# Set permissions for www-data
RUN chown -R www-data:www-data /var/www/html

# Drop privileges
USER www-data

# Expose HTTP port
EXPOSE 80
