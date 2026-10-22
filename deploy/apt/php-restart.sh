#!/usr/bin/env bash
# Redémarrer php-fpm et le worker de file quand un paquet PHP vient d'être mis à jour,
# pour qu'ils tournent avec le nouveau PHP (et une opcache vide).
#
# Installé dans /usr/local/sbin/causeo-php-restart par setup.sh ; lancé par dpkg après
# chaque installation (/etc/apt/apt.conf.d/53causeo-php-restart). Compare les versions
# des paquets php${PHP_VERSION}-* à celles du passage précédent : sans changement, rien.
# Le premier passage enregistre seulement l'état de départ.

set -uo pipefail

PHP_VERSION=8.2
if [[ -r /etc/causeo-deploy.env ]]; then
    PHP_VERSION="$(sed -n 's/^PHP_VERSION=//p' /etc/causeo-deploy.env)"
fi
STATE="${CAUSEO_PHP_STATE:-/var/lib/causeo/php-packages}"

current="$(dpkg-query -W -f='${Package} ${Version}\n' "php${PHP_VERSION}*" 2>/dev/null | sort)"
[[ -n "$current" ]] || exit 0

previous=""
[[ -f "$STATE" ]] && previous="$(cat "$STATE")"
[[ "$current" == "$previous" ]] && exit 0

mkdir -p "$(dirname "$STATE")"
printf '%s\n' "$current" > "$STATE"

if [[ -z "$previous" ]]; then
    echo "causeo-php-restart : état initial des paquets PHP ${PHP_VERSION} enregistré."
    exit 0
fi

logger -t causeo-php-restart "Paquets PHP ${PHP_VERSION} mis à jour : redémarrage de php${PHP_VERSION}-fpm et du worker."
echo "causeo-php-restart : PHP ${PHP_VERSION} mis à jour, redémarrage de php${PHP_VERSION}-fpm et du worker."

status=0
systemctl restart "php${PHP_VERSION}-fpm" || status=1

# Supervisor laisse le job en cours finir (stopwaitsecs) avant de relancer le worker.
if [[ -f /etc/supervisor/conf.d/causeo-worker.conf ]]; then
    supervisorctl restart 'causeo-worker:*' >/dev/null || status=1
fi

exit "$status"
