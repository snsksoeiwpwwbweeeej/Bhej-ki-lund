# Use official PHP Apache image
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install required system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Copy application file
COPY st7.php /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && chmod 644 /var/www/html/st7.php

# Configure Apache to allow access and use .htaccess
RUN echo '<Directory /var/www/html>' > /etc/apache2/conf-available/app.conf \
    && echo '    Options Indexes FollowSymLinks' >> /etc/apache2/conf-available/app.conf \
    && echo '    AllowOverride All' >> /etc/apache2/conf-available/app.conf \
    && echo '    Require all granted' >> /etc/apache2/conf-available/app.conf \
    && echo '</Directory>' >> /etc/apache2/conf-available/app.conf \
    && a2enconf app

# Also update the default Apache configuration
RUN sed -i 's|/var/www/html|/var/www/html|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<VirtualHost \*:80>/,/<\/VirtualHost>/ {/DocumentRoot/ s|/var/www/html|/var/www/html|}' /etc/apache2/sites-available/000-default.conf

# Create a simple .htaccess file to route all requests to st7.php
RUN echo 'RewriteEngine On' > /var/www/html/.htaccess \
    && echo 'RewriteCond %{REQUEST_FILENAME} !-f' >> /var/www/html/.htaccess \
    && echo 'RewriteCond %{REQUEST_FILENAME} !-d' >> /var/www/html/.htaccess \
    && echo 'RewriteRule ^ index.php [L]' >> /var/www/html/.htaccess \
    && echo 'DirectoryIndex st7.php' >> /var/www/html/.htaccess

# Create a simple index.php that includes st7.php
RUN echo '<?php include "st7.php"; ?>' > /var/www/html/index.php

# Set PHP configuration
RUN echo 'memory_limit = 256M' > /usr/local/etc/php/conf.d/custom.ini \
    && echo 'max_execution_time = 300' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'display_errors = Off' >> /usr/local/etc/php/conf.d/custom.ini \
    && echo 'log_errors = On' >> /usr/local/etc/php/conf.d/custom.ini

EXPOSE 80

CMD ["apache2-foreground"]
