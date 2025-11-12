FROM php:8.1-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y curl && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install curl && docker-php-ext-enable curl

# Apache configuration
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN echo "<Directory /var/www/html>" >> /etc/apache2/apache2.conf
RUN echo "    Options -Indexes +FollowSymLinks" >> /etc/apache2/apache2.conf
RUN echo "    AllowOverride All" >> /etc/apache2/apache2.conf
RUN echo "    DirectoryIndex app.php index.php index.html" >> /etc/apache2/apache2.conf
RUN echo "    Require all granted" >> /etc/apache2/apache2.conf
RUN echo "</Directory>" >> /etc/apache2/apache2.conf

# Create logs directory
RUN mkdir -p /var/www/html/logs && chmod 755 /var/www/html/logs

# Copy app files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/ && chmod -R 755 /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/ \
    && chmod 644 /var/www/html/*.php

# Create custom php.ini for error logging
RUN echo "log_errors = On" >> /usr/local/etc/php/php.ini \
    && echo "error_log = /var/www/html/logs/error.log" >> /usr/local/etc/php/php.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini \
    && echo "max_execution_time = 60" >> /usr/local/etc/php/php.ini

# Create health check file
RUN echo "<?php header('Content-Type: application/json'); echo json_encode(['status' => 'OK', 'message' => 'Stripe Processor is running']); ?>" > /var/www/html/health.php

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health.php || exit 1

# Start Apache
CMD ["apache2-foreground"]
