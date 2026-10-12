#!/usr/bin/env bash
# Déployer la dernière version de main sur le VPS, en tant qu'utilisateur deploy :
#
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh            # API + dashboard
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api        # API seule
#   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh dashboard  # dashboard seul
#
# API : mêmes étapes que docker-entrypoint.sh sur Render (migrations, seeders, caches),
# puis rechargement de php-fpm et redémarrage du worker.

set -euo pipefail

TARGET="${1:-all}"
[[ "$TARGET" =~ ^(all|api|dashboard)$ ]] || { echo "Usage : deploy.sh [all|api|dashboard]" >&2; exit 2; }
[[ "$(id -un)" == "deploy" ]] || { echo "À lancer en tant que deploy : sudo -u deploy -H bash $0 $TARGET" >&2; exit 1; }

cd / # le dossier courant de l'appelant (/root) peut être illisible pour deploy

# shellcheck source=/dev/null
source /etc/causeo-deploy.env # DOMAIN, API_DIR, DASHBOARD_SRC, DASHBOARD_DIR, PRIVACY_DIR, PHP_VERSION
PHP="/usr/bin/php${PHP_VERSION}"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[attention] %s\033[0m\n' "$*"; }

pull() {
    git -C "$1" fetch --quiet origin main
    git -C "$1" checkout --quiet main
    git -C "$1" pull --ff-only --quiet origin main
    echo "    $(basename "$1") : $(git -C "$1" log -1 --format='%h %s')"
}

deploy_api() {
    log "API : code"
    [[ -f "$API_DIR/.env" ]] || { echo "Il manque $API_DIR/.env (modèle : deploy/.env.production.example)." >&2; exit 1; }
    pull "$API_DIR"
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
