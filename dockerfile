# Use official PHP Apache image
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install required system dependencies
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    git \
    unzip \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/

# Install PHP extensions
RUN docker-php-ext-install \
    dom \
    xml \
    curl \
    mbstring

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Copy application files
COPY st7.php /var/www/html/st7.php

# Create necessary directories and set permissions
RUN mkdir -p /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && chmod 644 /var/www/html/st7.php

# Set PHP configuration
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/docker-php-memlimit.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/docker-php-maxexectime.ini \
    && echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/docker-php-upload.ini \
    && echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/docker-php-upload.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/docker-php-errors.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/docker-php-errors.ini \
    && echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/conf.d/docker-php-errors.ini

# Create error log file
RUN touch /var/log/php_errors.log \
    && chown www-data:www-data /var/log/php_errors.log

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo "<Directory /var/www/html>" > /etc/apache2/conf-available/app.conf \
    && echo "    Options Indexes FollowSymLinks" >> /etc/apache2/conf-available/app.conf \
    && echo "    AllowOverride All" >> /etc/apache2/conf-available/app.conf \
    && echo "    Require all granted" >> /etc/apache2/conf-available/app.conf \
    && echo "</Directory>" >> /etc/apache2/conf-available/app.conf \
    && a2enconf app

# Expose port 80
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
