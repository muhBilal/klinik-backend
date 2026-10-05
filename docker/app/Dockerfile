# Image tunggal Vertiqo: Nginx + PHP-FPM (API Laravel) + build Vue, dijalankan oleh supervisor.
# Build context = folder induk (berisi backend/ dan frontend/), diatur di backend/docker-compose.yml.

# ---------- 1. Build frontend ----------
FROM node:24-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ .
# Frontend & API satu origin -> cukup path relatif
ARG VITE_API_URL=/api
ENV VITE_API_URL=$VITE_API_URL
RUN npm run build

# ---------- 2. Dependensi PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# ---------- 3. Runtime ----------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor libpq-dev libzip-dev icu-dev \
    && docker-php-ext-install pdo_pgsql zip intl bcmath opcache \
    && rm -f /etc/nginx/http.d/default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY backend/docker/app/php.ini /usr/local/etc/php/conf.d/eklinik.ini
COPY backend/docker/app/nginx.conf /etc/nginx/http.d/eklinik.conf
COPY backend/docker/app/supervisord.conf /etc/supervisord.conf
COPY backend/docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY backend/ .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint.sh

COPY --from=frontend /app/dist /var/www/frontend

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
