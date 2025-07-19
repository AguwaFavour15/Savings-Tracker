FROM php:8.1-apache

# Clear existing Apache directory
RUN rm -rf /var/www/html/*

# Copy everything from the repo root
COPY . /var/www/html/

# Enable Apache rewrite module (optional, good for future Laravel or .htaccess use)
RUN a2enmod rewrite
