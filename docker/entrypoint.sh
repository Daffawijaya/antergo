#!/bin/sh
set -eu

: "${PORT:=10000}"
export PORT

envsubst '${PORT}' \
    < /etc/nginx/templates/antergo.conf.template \
    > /etc/nginx/conf.d/default.conf

rm -f /etc/nginx/sites-enabled/default

mkdir -p \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs

chown -R www-data:www-data \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage

exec "$@"
