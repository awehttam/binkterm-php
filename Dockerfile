#
# BinktermPHP Dockerfile
#
# Multi-stage build for BinktermPHP with Apache, PHP, Node.js, and DOSBox-X support
#
FROM php:8.2-apache AS base

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public_html
ENV DEBIAN_FRONTEND=noninteractive

# Create binkterm user to mirror a normal installation.
# www-data (Apache/PHP) is added to the binkterm group so it can read/write
# data/ and config/ via group permissions, the same as a bare-metal install.
RUN groupadd -r binkterm \
    && useradd -r -g binkterm -d /var/www/html -s /bin/bash binkterm \
    && usermod -aG binkterm www-data

# Install Node.js 20 LTS repository
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libzip-dev \
        nodejs \
        p7zip-full \
        postgresql-client \
        supervisor \
        unzip \
        # DOSBox-X for DOS door support with headless operation
        dosbox-x \
    && docker-php-ext-install -j"$(nproc)" pcntl posix pdo pdo_pgsql pgsql sockets zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache document root
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

# Copy application files
COPY . .

# Install Node.js dependencies for DOS door bridge
RUN cd scripts/dosbox-bridge && npm install --production

# Create necessary directories and set permissions.
# Files are owned by binkterm (mirroring a normal install).
# 775 on data/, config/, and dosbox-bridge/ gives www-data (binkterm group) write access.
RUN mkdir -p \
        data/run \
        data/logs \
        data/inbound \
        data/outbound \
        data/filebase \
        config \
        dosbox-bridge/dos/DROPS \
        dosbox-bridge/dos/DOORS \
    && chown -R binkterm:binkterm /var/www/html \
    && chmod -R 775 data config dosbox-bridge \
    && chmod +x scripts/*.php

# Copy Docker configuration files
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose ports
EXPOSE 80
EXPOSE 2323
EXPOSE 24554
EXPOSE 24555

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
