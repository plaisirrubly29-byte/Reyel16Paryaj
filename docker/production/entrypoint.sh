#!/bin/sh
# Démarrage des conteneurs backend, queue et scheduler (P1-06).
# Les caches sont construits ICI, à partir des variables d'environnement du conteneur, jamais au build :
# l'image ne contient aucun secret et sert à tous les environnements.
set -e

# Réserve de processus php-fpm (php-fpm.conf) jamais plus grande que PHP_FPM_MAX_CHILDREN, sinon php-fpm refuse de
# démarrer (ex. 2 processus sur 1 vCPU).
max_children="${PHP_FPM_MAX_CHILDREN:-10}"
case "$max_children" in
    '' | *[!0-9]* | 0) echo "PHP_FPM_MAX_CHILDREN invalide : « $max_children » (entier ≥ 1 attendu)" >&2; exit 1 ;;
esac
export PHP_FPM_START_SERVERS=$((max_children < 2 ? max_children : 2))
export PHP_FPM_MAX_SPARE_SERVERS=$((max_children < 4 ? max_children : 4))

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
