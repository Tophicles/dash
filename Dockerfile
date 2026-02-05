FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install curl zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Create config directory
RUN mkdir -p /config \
    && chown -R www-data:www-data /config

# Environment variable for config path
ENV CONFIG_DIR=/config

# Copy application files
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

# Drop privileges explicitly
USER www-data

EXPOSE 80
