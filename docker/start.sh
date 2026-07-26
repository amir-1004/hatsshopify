#!/bin/sh
set -e

# Render doesn't let a blueprint generate a valid Laravel APP_KEY (needs the
# base64: prefix), so generate one at boot when unset. Sessions reset per
# deploy — acceptable for this app (no login).
if [ -z "$APP_KEY" ]; then
    export APP_KEY="base64:$(openssl rand -base64 32)"
fi

php artisan migrate --force
php artisan db:seed --force

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
