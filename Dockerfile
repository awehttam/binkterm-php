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
        libgmp-dev \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        nodejs \
        p7zip-full \
        postgresql-client \
        rsync \
        supervisor \
        unzip \
        # DOSBox-X for DOS door support with headless operation
        dosbox-x \
    && docker-php-ext-install -j"$(nproc)" dom gmp mbstring pcntl posix pdo pdo_pgsql pgsql sockets xml zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache document root
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files and install dependencies.
# composer.lock is not committed to the repo, so only composer.json is copied here;
# composer resolves and locks versions fresh at build time.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

# Copy application files
COPY . .

# Install Node.js dependencies for DOS door bridge
RUN cd scripts/dosbox-bridge && npm install --production

# Install Node.js dependencies for the optional MCP server (ENABLE_MCP_SERVER)
RUN cd mcp-server && npm install --production

# Stash a pristine copy of the i18n translation catalogs outside config/.
# config/ is mounted as a persistent named volume (see docker-compose.yml) so that
# sysop-edited settings (binkp.json, bbs.json, etc.) survive image rebuilds. But that
# same volume mount also shadows config/i18n/ -- the translation catalogs, which are
# application code that changes every release, not sysop data. Docker only seeds a
# named volume from the image on its first creation, so without this, a sysop's
# volume freezes at whatever i18n catalogs existed when it was first created and
# never picks up new/changed translation keys on later upgrades.
# entrypoint.sh rsyncs this reference copy into the live volume on every container
# start. config/i18n/overrides/ holds sysop-customized phrases and is excluded here
# so it is never overwritten.
RUN mkdir -p /opt/binkterm-i18n-src \
    && cp -a config/i18n/. /opt/binkterm-i18n-src/ \
    && rm -rf /opt/binkterm-i18n-src/overrides

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

# Copy Docker configuration files.
# conf.d.available/ holds one supervisor template per optional daemon (SSH, Gemini,
# FTP, MRC, AI bot, Matterbridge, MCP server); entrypoint.sh copies the ones
# requested via ENABLE_* environment variables into the live include directory.
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/conf.d.available /opt/binkterm-conf.d-available
COPY docker/php-error-logging.ini /usr/local/etc/php/conf.d/zz-error-logging.ini
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose ports
EXPOSE 80
EXPOSE 2323
EXPOSE 24554
EXPOSE 24555
EXPOSE 6010
# Optional daemon ports (only listening if the matching ENABLE_* var is set)
EXPOSE 1965
EXPOSE 2022
EXPOSE 2121
EXPOSE 3740

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
