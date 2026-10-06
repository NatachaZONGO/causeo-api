#!/bin/sh
set -e

# Les variables d'environnement Render sont disponibles ici (runtime).
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan db:seed --class=BusinessTemplateSeeder --force

# Worker de file en arrière-plan, relancé s'il s'arrête
(
  while true; do
    php artisan queue:work --tries=3 --sleep=3 --timeout=280 --max-time=3600 || true
    sleep 2
  done
) &

exec "$@"
