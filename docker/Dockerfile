FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev \
    && docker-php-ext-install pdo_mysql intl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/logs storage/cache \
    && chown -R www-data:www-data storage

USER www-data
