# Use official PHP FPM image
FROM php:8.4-fpm


ARG http_proxy
ARG https_proxy

ENV http_proxy=${http_proxy}
ENV https_proxy=${https_proxy}


# Download and install the install-php-extensions script
# https://github.com/mlocati/docker-php-extension-installer
RUN curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions

# Configure PEAR
RUN if [ -n "${http_proxy}" ]; then pear config-set http_proxy ${http_proxy}; fi && \
    pear config-set php_ini $PHP_INI_DIR/php.ini


# Install system dependencies for PostgreSQL, Xdebug, Apache, and FastCGI
RUN apt-get update \
    && apt-get install -y libpq-dev cron curl apache2 libapache2-mod-fcgid \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pdo pdo_pgsql

# Xdebug configuration
COPY ./build_config/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# PHP-FPM configuration for better performance
RUN echo '[www]' > /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'listen = 127.0.0.1:9000' >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'pm.max_children = 50' >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'pm.start_servers = 5' >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'pm.min_spare_servers = 5' >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'pm.max_spare_servers = 10' >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo 'request_terminate_timeout = 300' >> /usr/local/etc/php-fpm.d/zzz-custom.conf

# Enable Apache modules for FastCGI and .htaccess functionality
RUN a2enmod rewrite headers expires deflate fcgid actions alias

# Configure Apache for FastCGI with PHP-FPM
RUN echo "FcgidConnectTimeout 20" >> /etc/apache2/conf-available/fcgid.conf && \
    echo "FcgidIOTimeout 300" >> /etc/apache2/conf-available/fcgid.conf && \
    echo "FcgidMaxRequestLen 134217728" >> /etc/apache2/conf-available/fcgid.conf && \
    a2enconf fcgid

# Configure Apache virtual host for FastCGI
RUN echo '<VirtualHost *:80>' > /etc/apache2/sites-available/000-default.conf && \
    echo '    DocumentRoot /var/www/html' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    <Directory /var/www/html>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '        AllowOverride All' >> /etc/apache2/sites-available/000-default.conf && \
    echo '        Require all granted' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    </Directory>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    # FastCGI configuration for PHP' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    <FilesMatch "\.php$">' >> /etc/apache2/sites-available/000-default.conf && \
    echo '        SetHandler "proxy:fcgi://127.0.0.1:9000"' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    </FilesMatch>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</VirtualHost>' >> /etc/apache2/sites-available/000-default.conf

# Enable mod_proxy_fcgi for PHP-FPM communication
RUN a2enmod proxy proxy_fcgi

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Copy and make the entrypoint script executable
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set permissions (optional, for dev)
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (Apache default)
EXPOSE 80

# Use our custom entrypoint script
CMD ["/usr/local/bin/docker-entrypoint.sh"]
