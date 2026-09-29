FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip libpq-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/eklinik.ini

WORKDIR /var/www/html
