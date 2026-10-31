#!/bin/sh
# Démarrage des conteneurs backend, queue et scheduler (P1-06).
# Les caches sont construits ICI, à partir des variables d'environnement du conteneur, jamais au build :
# l'image ne contient aucun secret et sert à tous les environnements.
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
