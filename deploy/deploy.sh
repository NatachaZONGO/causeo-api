#!/usr/bin/env bash
# Déployer main sur le VPS, en tant qu'utilisateur deploy :
#
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh            # API + dashboard
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api        # API seule
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh dashboard  # dashboard seul
#   … deploy.sh api <commit>   # l'API à ce commit précis (s'il est sur main)
#
# GitHub Actions passe par une clé SSH dont la seule commande permise est ce script
# (commande forcée) : la cible et le commit arrivent alors dans SSH_ORIGINAL_COMMAND.
#
# API : mêmes étapes que docker-entrypoint.sh sur Render (migrations, seeders, caches),
# puis rechargement de php-fpm et redémarrage du worker.

set -euo pipefail

usage() { echo "Usage : deploy.sh [all|api|dashboard] [commit]" >&2; exit 2; }

if [[ $# -eq 0 && -n "${SSH_ORIGINAL_COMMAND:-}" ]]; then
    # Découpé en mots puis validé ci-dessous : jamais exécuté tel quel.
    read -r -a ssh_args <<< "$SSH_ORIGINAL_COMMAND"
    set -- "${ssh_args[@]}"
fi

[[ $# -le 2 ]] || usage
TARGET="${1:-all}"
REF="${2:-}"
[[ "$TARGET" =~ ^(all|api|dashboard)$ ]] || usage
[[ -z "$REF" || "$REF" =~ ^[0-9a-f]{40}$ ]] || usage
[[ "$(id -un)" == "deploy" ]] || { echo "À lancer en tant que deploy : sudo -u deploy -H bash $0 $TARGET" >&2; exit 1; }

cd / # le dossier courant de l'appelant (/root) peut être illisible pour deploy

# Un seul déploiement à la fois sur ce serveur (GitHub Actions et manuel compris).
exec 9>"$HOME/.causeo-deploy.lock"
if ! flock -n 9; then
    echo "Un déploiement est déjà en cours ; attente de sa fin (30 min max)…"
    flock -w 1800 9 || { echo "Toujours en cours après 30 minutes : abandon." >&2; exit 1; }
fi

# shellcheck source=/dev/null
source /etc/causeo-deploy.env # DOMAIN, API_DIR, DASHBOARD_SRC, DASHBOARD_DIR, PRIVACY_DIR, PHP_VERSION
PHP="/usr/bin/php${PHP_VERSION}"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[attention] %s\033[0m\n' "$*"; }

# Mettre un dépôt à jour sur main, ou jusqu'au commit demandé (en avance rapide seulement).
pull() {
    local dir="$1" ref="${2:-}"
    git -C "$dir" fetch --quiet origin main
    git -C "$dir" checkout --quiet main

    if [[ -z "$ref" ]]; then
        git -C "$dir" merge --ff-only --quiet origin/main
    elif ! git -C "$dir" merge-base --is-ancestor "$ref" origin/main; then
        echo "Le commit $ref n'est pas sur main : rien n'est déployé." >&2
        exit 1
    elif git -C "$dir" merge-base --is-ancestor "$ref" HEAD; then
        # Un déploiement plus récent est déjà passé : on ne revient pas en arrière.
        warn "$ref est déjà inclus dans la version en place ($(git -C "$dir" rev-parse --short HEAD))."
    else
        git -C "$dir" merge --ff-only --quiet "$ref"
    fi

    echo "    $(basename "$dir") : $(git -C "$dir" log -1 --format='%h %s')"
}

deploy_api() {
    log "API : code"
    [[ -f "$API_DIR/.env" ]] || { echo "Il manque $API_DIR/.env (modèle : deploy/.env.production.example)." >&2; exit 1; }
    pull "$API_DIR" "$REF"
    cd "$API_DIR"

    log "API : dépendances"
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

    log "API : base de données"
    "$PHP" artisan migrate --force
    "$PHP" artisan db:seed --class=BusinessTemplateSeeder --force
    "$PHP" artisan db:seed --class=PlanSeeder --force

    log "API : caches"
    "$PHP" artisan config:cache
    "$PHP" artisan route:cache
    "$PHP" artisan view:cache

    log "API : redémarrage"
    sudo /usr/bin/systemctl reload "php${PHP_VERSION}-fpm" # vide aussi l'OPcache
    # Le worker finit son job en cours puis s'arrête ; Supervisor le relance avec le nouveau code.
    "$PHP" artisan queue:restart
    sudo /usr/bin/supervisorctl update >/dev/null
    sudo /usr/bin/supervisorctl start 'causeo-worker:*' >/dev/null 2>&1 || true
    sudo /usr/bin/supervisorctl status

    if curl -fsS --max-time 10 "https://api.${DOMAIN}/up" >/dev/null 2>&1; then
        echo "    https://api.${DOMAIN}/up répond."
    else
        warn "https://api.${DOMAIN}/up ne répond pas (normal si le DNS ou le certificat ne sont pas encore prêts)."
    fi
}

deploy_dashboard() {
    log "Dashboard : code"
    pull "$DASHBOARD_SRC"

    # Build « vps » (environment.vps.ts) : environment.prod.ts reste celui de Vercel jusqu'à
    # la bascule. npm run build:vps échoue tant que __DOMAINE__ n'y est pas remplacé.
    local env_file="$DASHBOARD_SRC/src/environments/environment.vps.ts"
    local build_cmd=(npm run build:vps)
    if [[ ! -f "$env_file" ]]; then
        env_file="$DASHBOARD_SRC/src/environments/environment.prod.ts"
        build_cmd=(npx ng build --configuration production)
    fi
    if ! grep -q "https://api.${DOMAIN}/api/v1" "$env_file"; then
        warn "apiUrl de $env_file ne pointe pas vers https://api.${DOMAIN}/api/v1 :"
        grep -n "apiUrl" "$env_file" || true
        warn "Le dashboard servi ici appellera cette adresse. Voir MIGRATION.md, étape 9."
    fi

    log "Dashboard : build (${build_cmd[*]})"
    cd "$DASHBOARD_SRC"
    npm ci --no-audit --no-fund
    "${build_cmd[@]}"

    local build="$DASHBOARD_SRC/dist/causeo-dashboard/browser"
    [[ -f "$build/index.html" ]] || { echo "Build introuvable : $build/index.html" >&2; exit 1; }

    log "Dashboard : publication"
    # Les nouveaux fichiers d'abord, index.html en dernier : un visiteur ne charge jamais
    # un index.html qui pointe vers des fichiers absents.
    rsync -a --exclude index.html "$build/" "$DASHBOARD_DIR/"
    rsync -a "$build/index.html" "$DASHBOARD_DIR/index.html"
    rsync -a --delete "$build/" "$DASHBOARD_DIR/"

    log "Pages légales"
    if [[ -d "$PRIVACY_DIR/.git" ]]; then
        pull "$PRIVACY_DIR"
    else
        warn "$PRIVACY_DIR n'est pas un dépôt git : pages légales non mises à jour."
    fi
}

main() {
    case "$TARGET" in
        api) deploy_api ;;
        dashboard) deploy_dashboard ;;
        all) deploy_api; deploy_dashboard ;;
    esac

    log "Déploiement terminé"
}

# Sur une seule ligne, avec exit : bash a tout lu avant que git pull ne réécrive ce fichier.
main; exit $?
