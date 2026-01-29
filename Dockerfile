FROM php:8.2-apache

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public_html

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libzip-dev \
        p7zip-full \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql pgsql sockets zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress

COPY . .
RUN mkdir -p data/run data/logs \
    && chown -R www-data:www-data data config \
    && chmod -R 775 data config

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
