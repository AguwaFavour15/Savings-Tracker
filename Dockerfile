FROM php:8.1-apache

# Clean default Apache directory
RUN rm -rf /var/www/html/*

# Copy all your files from repo root to Apache
COPY . /var/www/html/

# Enable mod_rewrite (optional for future Laravel/rewrites)
RUN a2enmod rewrite
