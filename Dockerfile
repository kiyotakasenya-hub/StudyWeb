# Use the official PHP Apache image
FROM php:8.2-apache

# Enable necessary PHP extensions for SQLite
RUN apt-get update && apt-get install -y libsqlite3-dev
RUN docker-php-ext-install pdo pdo_sqlite

# Copy your files to the web server's root
COPY . /var/www/html/

# Expose port 80
EXPOSE 80
