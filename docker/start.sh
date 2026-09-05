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

# Laravel's scheduler (routes/console.php: webhook:clean, logs:prune,
# the booking reminder commands, etc.) only runs if something calls
# `php artisan schedule:run` on a regular cadence — Laravel does not
# do this on its own. This loop is that cadence: it invokes the
# scheduler once a minute, in the background, for the lifetime of the
# container. schedule:run itself is a no-op on any minute where nothing
# is due, so this is safe to leave running constantly — each configured
# job still only fires on its own ->daily()/->everyMinute()/etc timing.
#
# Backgrounded with `&` (not `-D`, since artisan has no daemon mode of
# its own) so it doesn't block the `nginx -g "daemon off;"` line below,
# which must stay the foreground process for Render's health checks.
(
    while true; do
        php artisan schedule:run >> /dev/stdout 2>&1
        sleep 60
    done
) &

# Run nginx in the foreground so the container stays alive and Render
# can see it as the main process. If nginx dies, the container stops
# and Render will report it as crashed instead of silently hanging.
nginx -g "daemon off;"