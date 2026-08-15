FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
RUN composer dump-autoload \
    --no-dev \
    --optimize \
    --classmap-authoritative \
    --no-interaction

FROM php:8.3-fpm-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        gettext-base \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        nginx \
        supervisor \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        pgsql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/antergo-production.ini
WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY docker/nginx.conf.template /etc/nginx/templates/antergo.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/antergo.conf
COPY docker/entrypoint.sh /usr/local/bin/antergo-entrypoint

RUN chmod +x /usr/local/bin/antergo-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000

ENTRYPOINT ["antergo-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
