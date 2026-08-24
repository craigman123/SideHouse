#!/bin/sh
set -e

PORT="${PORT:-10000}"

# Fill in the real port nginx should listen on
sed "s/__PORT__/${PORT}/" /etc/nginx/conf.d/app.conf.template > /etc/nginx/conf.d/app.conf

php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan migrate --force

# Start php-fpm in the background
php-fpm -D

# Run nginx in the foreground so the container stays alive and Render
# can see it as the main process. If nginx dies, the container stops
# and Render will report it as crashed instead of silently hanging.
nginx -g "daemon off;"
