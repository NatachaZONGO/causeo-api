#!/bin/sh
set -e

# Les variables d'environnement Render sont disponibles ici (runtime).
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan db:seed --class=BusinessTemplateSeeder --force
php artisan db:seed --class=PlanSeeder --force

# Worker de file en arrière-plan, relancé s'il s'arrête (RUN_QUEUE_WORKER=false pour le couper)
if [ "${RUN_QUEUE_WORKER:-true}" != "false" ]; then
  (
    while true; do
      php artisan queue:work --tries=3 --sleep=3 --timeout=280 --max-time=3600 || true
      sleep 2
    done
  ) &
fi

# Planificateur (billing:sync, rapport hebdomadaire), relancé s'il s'arrête.
# Un seul environnement doit le faire tourner : RUN_SCHEDULER=false sur Render une fois
# le VPS en service (deploy/MIGRATION.md).
if [ "${RUN_SCHEDULER:-true}" != "false" ]; then
  (
    while true; do
      php artisan schedule:work || true
      sleep 2
    done
  ) &
fi

exec "$@"
