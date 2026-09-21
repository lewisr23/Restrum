#!/bin/sh
set -e

# storage/ is usually a mounted volume, so the public/storage symlink and the
# writable directories have to be established at start rather than baked into
# the image - a volume mount would hide anything created at build time.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true

# Only the PHP-FPM container should touch the schema; the Reverb container
# starts from the same image and would otherwise race it on boot.
if [ "$1" = "php-fpm" ]; then
    echo "Waiting for the database..."
    until php -r "new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        sleep 2
    done

    php artisan migrate --force

    # Cached at runtime, not build time: these bake in environment values, so
    # building them into the image would freeze whatever the builder had set.
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
