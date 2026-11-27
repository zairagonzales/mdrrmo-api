FROM php:8.3-apache

# Install PostgreSQL development dependencies and PHP PDO driver
RUN apt-get update \
   && apt-get install -y --no-install-recommends libpq-dev \
   && docker-php-ext-install pdo_pgsql \
   && a2enmod rewrite \
   && rm -rf /var/lib/apt/lists/*

# Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html

# Copy public API files into Apache's document root
COPY public/ /var/www/html/

# Keep application/database code outside the public web directory
COPY src/ /var/www/src/

# Set ownership for the application files
RUN chown -R www-data:www-data /var/www/html /var/www/src

# Configure Apache to listen on Render's assigned port
# Defaults to 10000 if PORT is not set
RUN printf '%s\n' \
   '#!/bin/sh' \
   'set -eu' \
   'PORT="${PORT:-10000}"' \
   'sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf' \
   'sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
   'exec apache2-foreground' \
   > /usr/local/bin/render-entrypoint \
   && chmod +x /usr/local/bin/render-entrypoint

# Document the default container port
EXPOSE 10000

# Start Apache using Render's port
CMD ["/usr/local/bin/render-entrypoint"]

