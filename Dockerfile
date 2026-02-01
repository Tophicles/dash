FROM php:8.2-apache

# Install system dependencies
# openssh-client: for remote server management
# git: useful for updates if needed (though we copy source)
# libzip-dev: for zip extension if needed (not strictly required by current code but good practice)
RUN apt-get update && apt-get install -y \
    openssh-client \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Configure Apache to allow .htaccess overrides
# This is crucial for the application's security rules in .htaccess to work
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application source
COPY . /var/www/html/

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose port 80
EXPOSE 80

# Define volume for configuration persistence
VOLUME ["/config"]

# Set custom entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]
