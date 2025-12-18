# Use official PHP Apache image
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    curl \
    dom \
    xml \
    mbstring \
    zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copy application files
COPY st7.php .
COPY .htaccess .
COPY approved.txt .

# Create necessary directories and set permissions
RUN mkdir -p /tmp/cookies && \
    chown -R www-data:www-data /var/www/html /tmp/cookies && \
    chmod 755 /var/www/html && \
    chmod 644 /var/www/html/st7.php && \
    chmod 644 /var/www/html/.htaccess && \
    chmod 644 /var/www/html/approved.txt && \
    touch /var/www/html/approved.txt && \
    chmod 666 /var/www/html/approved.txt

# Create PHP configuration
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "allow_url_fopen = On" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/conf.d/custom.ini

# Create volume for persistent data
VOLUME ["/var/www/html"]

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache in foreground
CMD ["apache2-foreground"]
