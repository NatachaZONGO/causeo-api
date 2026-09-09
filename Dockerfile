# syntax=docker/dockerfile:1
FROM php:8.2-cli

# Dépendances système + extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpq-dev libxml2-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql mbstring xml curl zip bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Installe les dépendances en premier pour profiter du cache de couches
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copie le reste du projet (voir .dockerignore)
COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && composer run-script post-autoload-dump --no-interaction || true

# Permissions pour storage / cache
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# route:cache est sûr au build ; config:cache est fait au démarrage (les
# variables d'environnement Render ne sont disponibles qu'à l'exécution).
RUN php artisan route:cache || true

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
