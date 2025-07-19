FROM php:8.1-apache

# Copy app files to Apache web root
COPY public/ /var/www/html/

# Enable Apache mod_rewrite if needed
RUN a2enmod rewrite
