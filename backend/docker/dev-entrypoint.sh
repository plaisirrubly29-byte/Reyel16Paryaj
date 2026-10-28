#!/bin/sh
# Préparation idempotente du backend en développement (service "backend" uniquement).
# Permet à `docker compose up` de suffire sur un clone neuf.
set -e

[ -f .env ] || cp .env.example .env
[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
php artisan migrate --force

exec "$@"
