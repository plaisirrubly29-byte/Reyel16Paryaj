# syntax=docker/dockerfile:1
#
# Images de Staging / Production (P1-06). Contexte de build : la RACINE du dépôt (voir /.dockerignore).
#
#   docker build -f docker/production/Dockerfile --target backend -t reyel16-backend .   # API (php-fpm), queue, scheduler
#   docker build -f docker/production/Dockerfile --target web -t reyel16-web .           # Caddy : HTTPS, frontend, proxy API
#
# Aucun secret dans les images : la configuration vient des variables d'environnement au démarrage
# (docker/staging/docker-compose.yml + .env du serveur). L'image de développement reste backend/Dockerfile.

# ---------------------------------------------------------------------------------------------------------------
# Backend : PHP 8.3 FPM (même base Debian et mêmes extensions que l'image de développement).
# ---------------------------------------------------------------------------------------------------------------
FROM php:8.3-fpm AS backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip libfcgi-bin \
    && docker-php-ext-install pdo_pgsql pgsql zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-reyel16.ini"
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-reyel16.conf
# Processus php-fpm au plus (docker/production/php-fpm.conf) ; à fixer selon les vCPU du serveur dans le .env.
ENV PHP_FPM_MAX_CHILDREN=10
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Dépendances d'abord (couche mise en cache tant que composer.lock ne change pas), sans outils de développement.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && rm -f /usr/bin/composer \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/reyel16-entrypoint

USER www-data
ENTRYPOINT ["reyel16-entrypoint"]
CMD ["php-fpm"]
EXPOSE 9000

# ---------------------------------------------------------------------------------------------------------------
# Frontend : build statique de React (API sur la même origine : ni CORS ni cookies tiers).
# ---------------------------------------------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
ENV VITE_API_URL=/api/v1
RUN npm run build

# ---------------------------------------------------------------------------------------------------------------
# Web : Caddy (certificat Let's Encrypt automatique), fichiers du frontend, API transmise à php-fpm.
# ---------------------------------------------------------------------------------------------------------------
FROM caddy:2-alpine AS web

COPY docker/production/Caddyfile /etc/caddy/Caddyfile
COPY --from=frontend /app/dist /srv/frontend
# Point d'entrée PHP : Caddy vérifie que le fichier existe avant de le confier à php-fpm (même chemin des deux côtés).
COPY backend/public /var/www/backend/public
