FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    openssh-client \
    git \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install curl

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Create config directory
RUN mkdir -p /config && chown -R www-data:www-data /config

# Set environment variable
ENV CONFIG_DIR=/config

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80
