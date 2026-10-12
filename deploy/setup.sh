#!/usr/bin/env bash
# Installation du serveur Causeo sur un Ubuntu 24.04 neuf. Idempotent : peut être relancé
# sans risque (après l'émission des certificats, pour passer en HTTPS, ou après une mise
# à jour de deploy/).
#
#   sudo DOMAIN=exemple.bf CERTBOT_EMAIL=contact@exemple.bf bash deploy/setup.sh
#
# Variables :
#   DOMAIN          (obligatoire) domaine nu : le dashboard sur DOMAIN et www.DOMAIN,
#                   l'API sur api.DOMAIN
#   CERTBOT_EMAIL   adresse pour Let's Encrypt. Sans elle, pas de demande de certificat
#                   (les enregistrements DNS doivent déjà pointer vers ce serveur)
#   API_REPO, DASHBOARD_REPO, PRIVACY_REPO   dépôts git (valeurs par défaut ci-dessous)
#
# Ne fait PAS : copier le .env, déployer le code (deploy.sh), activer le planificateur
# (scheduler.sh). Voir deploy/MIGRATION.md.

set -euo pipefail

DOMAIN="${DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
API_REPO="${API_REPO:-https://github.com/NatachaZONGO/causeo-api.git}"
DASHBOARD_REPO="${DASHBOARD_REPO:-git@github.com:NatachaZONGO/causeo-dashboard.git}" # privé : clé de déploiement
PRIVACY_REPO="${PRIVACY_REPO:-https://github.com/NatachaZONGO/causeo-privacy.git}"

PHP_VERSION=8.2
NODE_MAJOR=22 # Angular 21 demande Node 20.19+ ou 22.12+
DEPLOY_USER=deploy
API_DIR=/var/www/causeo-api
DASHBOARD_SRC=/var/www/causeo-dashboard-src
DASHBOARD_DIR=/var/www/causeo-dashboard
PRIVACY_DIR=/var/www/causeo-privacy
ACME_DIR=/var/www/letsencrypt
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" # ce dossier deploy/

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[attention] %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m[erreur] %s\033[0m\n' "$*" >&2; exit 1; }

# Depuis /, lisible par deploy : git échoue si le dossier courant (/root) ne l'est pas.
as_deploy() { (cd / && sudo -u "$DEPLOY_USER" -H "$@"); }

# Installer un fichier seulement s'il a changé ; renvoie 0 si modifié.
install_file() {
    local source="$1" target="$2" mode="${3:-644}"
    if [[ -f "$target" ]] && cmp -s "$source" "$target"; then
        return 1
    fi
    install -D -m "$mode" "$source" "$target"
    echo "    installé : $target"
}

render() { sed "s/__DOMAIN__/${DOMAIN}/g" "$1"; }

# --- Vérifications ------------------------------------------------------------------------

[[ $EUID -eq 0 ]] || die "À lancer en root (sudo)."
[[ -n "$DOMAIN" ]] || die "Indiquez le domaine : DOMAIN=exemple.bf"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "Domaine invalide : $DOMAIN (sans https:// ni www)"
[[ "$DOMAIN" != www.* && "$DOMAIN" != api.* ]] || die "Indiquez le domaine nu (sans www. ni api.)"

if ! grep -q 'VERSION_ID="24.04"' /etc/os-release; then
    warn "Ce script est prévu pour Ubuntu 24.04."
fi

export DEBIAN_FRONTEND=noninteractive

# --- Paquets système ----------------------------------------------------------------------

log "Paquets de base"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip rsync acl cron \
    software-properties-common nginx supervisor certbot ufw

log "PHP ${PHP_VERSION} (dépôt ondrej/php : Ubuntu 24.04 fournit PHP 8.3)"
if ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -y -q "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-readline"
update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null

# pcntl est compilé dans PHP CLI (queue:work en a besoin pour --timeout).
for ext in pcntl pdo_pgsql mbstring xml curl zip bcmath intl; do
    "php${PHP_VERSION}" -m | grep -qi "^${ext}$" || die "Extension PHP manquante : ${ext}"
done

log "Composer"
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || die "Signature de l'installateur Composer invalide."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
else
    composer self-update --quiet || true
fi

log "Node.js ${NODE_MAJOR} (build du dashboard)"
if ! command -v node >/dev/null || [[ "$(node -p 'process.versions.node.split(".")[0]')" -lt $NODE_MAJOR ]]; then
    install -d -m 755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -q
    apt-get install -y -q nodejs
fi

# --- Utilisateur deploy -------------------------------------------------------------------

log "Utilisateur ${DEPLOY_USER}"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/${DEPLOY_USER}/.ssh"

# Même accès SSH que root, s'il n'en a pas encore.
if [[ -s /root/.ssh/authorized_keys && ! -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]]; then
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
fi

# Clé de lecture du dépôt privé du dashboard (à ajouter dans GitHub > Deploy keys).
if [[ ! -f "/home/${DEPLOY_USER}/.ssh/id_ed25519" ]]; then
    as_deploy ssh-keygen -q -t ed25519 -N "" -C "deploy@${DOMAIN}" -f "/home/${DEPLOY_USER}/.ssh/id_ed25519"
fi
if ! as_deploy ssh-keygen -F github.com >/dev/null 2>&1; then
    ssh-keyscan -t ed25519 github.com 2>/dev/null | as_deploy tee -a "/home/${DEPLOY_USER}/.ssh/known_hosts" >/dev/null
fi

# deploy.sh peut recharger php-fpm, nginx et le worker sans mot de passe, et rien d'autre.
sudoers=/etc/sudoers.d/causeo-deploy
cat > /tmp/causeo-deploy.sudoers <<EOF
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm, /usr/bin/systemctl reload nginx, /usr/bin/supervisorctl restart causeo-worker\:*, /usr/bin/supervisorctl start causeo-worker\:*, /usr/bin/supervisorctl update, /usr/bin/supervisorctl status
EOF
visudo -cf /tmp/causeo-deploy.sudoers >/dev/null || die "Fichier sudoers invalide."
install_file /tmp/causeo-deploy.sudoers "$sudoers" 440 || true
rm -f /tmp/causeo-deploy.sudoers

# --- Dossiers et dépôts -------------------------------------------------------------------

log "Dossiers et dépôts"
install -d -m 755 /var/www
install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$API_DIR" "$DASHBOARD_SRC" "$DASHBOARD_DIR" "$PRIVACY_DIR"
install -d -m 755 "$ACME_DIR"

clone() {
    local repo="$1" dir="$2"
    if [[ -d "$dir/.git" ]]; then
        echo "    déjà cloné : $dir"
    elif [[ -n "$(ls -A "$dir" 2>/dev/null)" ]]; then
        warn "$dir n'est pas vide et n'est pas un dépôt git : laissé tel quel."
    elif as_deploy git clone -q "$repo" "$dir"; then
        echo "    cloné : $repo"
    else
        warn "Impossible de cloner $repo dans $dir."
        return 1
    fi
}

clone "$API_REPO" "$API_DIR" || true
clone "$PRIVACY_REPO" "$PRIVACY_DIR" || true
if ! clone "$DASHBOARD_REPO" "$DASHBOARD_SRC"; then
    warn "Le dépôt du dashboard est privé. Ajoutez cette clé dans GitHub > causeo-dashboard > Settings > Deploy keys (lecture seule), puis relancez setup.sh :"
    cat "/home/${DEPLOY_USER}/.ssh/id_ed25519.pub"
fi

# Tout appartient à deploy (un dossier cloné à la main en root le serait sinon).
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$API_DIR" "$DASHBOARD_SRC" "$DASHBOARD_DIR" "$PRIVACY_DIR"

# Laravel écrit dans storage/ et bootstrap/cache (le pool php-fpm tourne sous deploy).
if [[ -d "$API_DIR/storage" ]]; then
    as_deploy mkdir -p "$API_DIR"/storage/framework/{cache,sessions,views} "$API_DIR"/storage/logs "$API_DIR"/bootstrap/cache
fi

cat > /etc/causeo-deploy.env <<EOF
# Lu par deploy/deploy.sh (écrit par setup.sh).
DOMAIN=${DOMAIN}
API_DIR=${API_DIR}
DASHBOARD_SRC=${DASHBOARD_SRC}
DASHBOARD_DIR=${DASHBOARD_DIR}
PRIVACY_DIR=${PRIVACY_DIR}
PHP_VERSION=${PHP_VERSION}
EOF
chmod 644 /etc/causeo-deploy.env

# --- PHP-FPM ------------------------------------------------------------------------------

log "PHP-FPM (pool causeo)"
touch "/var/log/php${PHP_VERSION}-fpm-causeo.log"
chown "$DEPLOY_USER:$DEPLOY_USER" "/var/log/php${PHP_VERSION}-fpm-causeo.log"
if install_file "$SOURCE_DIR/php/causeo-pool.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/causeo.conf"; then
    "php-fpm${PHP_VERSION}" -t
    systemctl restart "php${PHP_VERSION}-fpm"
fi
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null

# --- nginx et certificats -----------------------------------------------------------------

log "nginx"
install_file "$SOURCE_DIR/nginx/causeo-ssl.conf" /etc/nginx/snippets/causeo-ssl.conf || true
rm -f /etc/nginx/sites-enabled/default

has_cert() { [[ -f "/etc/letsencrypt/live/$1/fullchain.pem" ]]; }

# Avant les certificats : un site HTTP qui ne sert que le challenge Let's Encrypt.
write_bootstrap_site() {
    local name="$1"; shift
    cat > "/etc/nginx/sites-available/${name}.conf" <<EOF
# Configuration provisoire (en attente du certificat HTTPS) : relancer setup.sh ensuite.
server {
    listen 80;
    listen [::]:80;
    server_name $*;

    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_DIR};
    }

    location / {
        default_type text/plain;
        return 503 "Certificat HTTPS en cours d'installation.\n";
    }
}
EOF
    ln -sf "/etc/nginx/sites-available/${name}.conf" "/etc/nginx/sites-enabled/${name}.conf"
}

write_site() {
    local name="$1" template="$2"
    render "$template" > "/tmp/${name}.conf"
    install_file "/tmp/${name}.conf" "/etc/nginx/sites-available/${name}.conf" || true
    rm -f "/tmp/${name}.conf"
    ln -sf "/etc/nginx/sites-available/${name}.conf" "/etc/nginx/sites-enabled/${name}.conf"
}

has_cert "api.${DOMAIN}" || write_bootstrap_site causeo-api "api.${DOMAIN}"
has_cert "${DOMAIN}" || write_bootstrap_site causeo-dashboard "${DOMAIN}" "www.${DOMAIN}"
nginx -t
systemctl enable --now nginx >/dev/null
systemctl reload nginx

if [[ -n "$CERTBOT_EMAIL" ]]; then
    request_cert() {
        local primary="$1"; shift
        has_cert "$primary" && return 0
        local args=()
        for name in "$primary" "$@"; do args+=(-d "$name"); done
        certbot certonly --webroot -w "$ACME_DIR" --non-interactive --agree-tos -m "$CERTBOT_EMAIL" "${args[@]}" \
            || warn "Certificat refusé pour $primary : vérifiez que le DNS pointe vers ce serveur, puis relancez setup.sh."
    }
    log "Certificats Let's Encrypt"
    request_cert "api.${DOMAIN}"
    request_cert "${DOMAIN}" "www.${DOMAIN}"
else
    warn "CERTBOT_EMAIL non défini : aucun certificat demandé."
fi

# Recharger nginx après chaque renouvellement (le timer certbot tourne déjà).
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

has_cert "api.${DOMAIN}" && write_site causeo-api "$SOURCE_DIR/nginx/api.conf"
has_cert "${DOMAIN}" && write_site causeo-dashboard "$SOURCE_DIR/nginx/dashboard.conf"
nginx -t
systemctl reload nginx

# --- Worker de file -----------------------------------------------------------------------

log "Supervisor (worker de file)"
install_file "$SOURCE_DIR/supervisor/causeo-worker.conf" /etc/supervisor/conf.d/causeo-worker.conf || true
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null
if [[ -f "$API_DIR/.env" ]]; then
    supervisorctl update
else
    warn "Pas encore de $API_DIR/.env : le worker démarrera au premier deploy.sh."
fi

# Le planificateur n'est PAS activé ici (voir deploy/scheduler.sh et MIGRATION.md).
install -m 755 "$SOURCE_DIR/scheduler.sh" /usr/local/sbin/causeo-scheduler
install -m 644 "$SOURCE_DIR/cron/causeo-scheduler" /usr/local/share/causeo-scheduler.cron
systemctl enable --now cron >/dev/null

# --- Pare-feu -----------------------------------------------------------------------------

log "Pare-feu UFW (22, 80, 443)"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null
ufw status | sed 's/^/    /'

# --- SSH ----------------------------------------------------------------------------------

log "SSH : plus de connexion root par mot de passe"
if [[ -s /root/.ssh/authorized_keys ]]; then
    # Les fichiers de sshd_config.d sont lus par ordre alphabétique et la première valeur
    # l'emporte : 00- passe avant 50-cloud-init.conf de Hostinger.
    printf '# Causeo : root seulement par clé SSH.\nPermitRootLogin prohibit-password\n' > /tmp/00-causeo.conf
    if install_file /tmp/00-causeo.conf /etc/ssh/sshd_config.d/00-causeo.conf; then
        # Ubuntu 24.04 active ssh par socket : le service peut être arrêté entre deux connexions.
        sshd -t && systemctl try-reload-or-restart ssh
    fi
    rm -f /tmp/00-causeo.conf
else
    warn "root n'a aucune clé SSH (/root/.ssh/authorized_keys) : connexion par mot de passe laissée active pour ne pas vous enfermer dehors. Ajoutez une clé puis relancez setup.sh."
fi

# --- Résumé -------------------------------------------------------------------------------

log "Terminé"
has_cert "api.${DOMAIN}" && echo "    https://api.${DOMAIN} : HTTPS prêt" || echo "    api.${DOMAIN} : en attente du certificat"
has_cert "${DOMAIN}" && echo "    https://${DOMAIN} : HTTPS prêt" || echo "    ${DOMAIN} : en attente du certificat"
[[ -f "$API_DIR/.env" ]] || echo "    À faire : créer $API_DIR/.env (modèle : deploy/.env.production.example), même APP_KEY que Render"
echo "    Ensuite : sudo -u ${DEPLOY_USER} -H bash ${API_DIR}/deploy/deploy.sh"
