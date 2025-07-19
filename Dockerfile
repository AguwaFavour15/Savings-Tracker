FROM php:8.1-apache

# Install mysqli extension
RUN docker-php-ext-install mysqli

# Enable mod_rewrite (optional for clean URLs)
RUN a2enmod rewrite

# Copy your app files
COPY public/ /var/www/html/
COPY db.php /var/www/html/db.php
COPY config.php /var/www/html/config.php

# Set permissions (optional)
RUN chown -R www-data:www-data /var/www/html
