#!/bin/sh
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY belum diisi. Pastikan backend/.env berisi APP_KEY (php artisan key:generate)." >&2
    exit 1
fi

# Cache konfigurasi & route dari environment container
php artisan config:cache
php artisan route:cache

php artisan migrate --force

# Data demo hanya dimasukkan bila database masih kosong (DatabaseSeeder memeriksa sendiri)
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
