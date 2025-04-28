# Use official PHP image with Apache
FROM php:8.4-apache


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


# Install system dependencies for PostgreSQL and Xdebug
RUN apt-get update \
    && apt-get install -y libpq-dev \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pdo pdo_pgsql

# Xdebug configuration
COPY ./build_config/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html

# Set permissions (optional, for dev)
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (Apache default)
EXPOSE 80

# Use the default Apache start command
CMD ["apache2-foreground"]
