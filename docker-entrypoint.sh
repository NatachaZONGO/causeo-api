#!/bin/sh
set -e

# Les variables d'environnement Render sont disponibles ici (runtime).
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan db:seed --class=BusinessTemplateSeeder --force

exec "$@"
