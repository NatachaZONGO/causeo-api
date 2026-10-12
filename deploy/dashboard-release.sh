#!/usr/bin/env bash
# Versions du dashboard : réception par rsync et bascule atomique.
#
#   /var/www/causeo-dashboard/
#     releases/<id>/   une version construite (index.html, fichiers JS/CSS…)
#     current          lien symbolique vers la version servie (root de nginx)
#     previous         lien vers la version d'avant (pour revenir en arrière)
#
# Activer une version remplace le lien « current » en une seule opération (rename) : un
# visiteur reçoit l'ancienne version ou la nouvelle, jamais une copie à moitié faite.
#
# Utilisé de deux façons :
#   - par GitHub Actions (dépôt causeo-dashboard), avec une clé SSH dont c'est la commande
#     forcée. Deux appels possibles, rien d'autre :
#       rsync … browser/ deploy@VPS:<id>/  reçu par rrsync, en écriture seule dans releases/
#       ssh deploy@VPS activate <id>       bascule sur la version copiée
#   - à la main ou par deploy.sh, en tant que deploy :
#       dashboard-release.sh activate <id>
#       dashboard-release.sh rollback      revenir à la version précédente
#       dashboard-release.sh list          versions présentes, la version servie marquée

set -euo pipefail

# shellcheck source=/dev/null
[[ -f /etc/causeo-deploy.env ]] && source /etc/causeo-deploy.env
DASHBOARD_DIR="${DASHBOARD_DIR:-/var/www/causeo-dashboard}"
RELEASES_DIR="$DASHBOARD_DIR/releases"
# Versions gardées sur le disque (la servie et la précédente le sont toujours).
KEEP=5
# « 20261014093000-1a2b3c4 » : date UTC du build, puis le début du commit.
ID_RE='^[0-9]{14}-[0-9a-f]{7,40}$'

die() { echo "dashboard-release : $*" >&2; exit 1; }
usage() { die "usage : dashboard-release.sh activate <id> | rollback | list"; }

# --- Appel par la clé SSH de GitHub Actions (commande forcée) ------------------------------
if [[ $# -eq 0 && -n "${SSH_ORIGINAL_COMMAND:-}" ]]; then
    if [[ "$SSH_ORIGINAL_COMMAND" == "rsync --server "* ]]; then
        # rrsync (fourni avec rsync) limite l'écriture à releases/ et refuse « .. » ; -wo :
        # écriture seule, rien ne peut être lu depuis le serveur.
        rrsync="$(command -v rrsync || true)"
        [[ -n "$rrsync" ]] || die "rrsync introuvable (paquet rsync 3.2.4 ou plus récent)."
        install -d -m 755 "$RELEASES_DIR"
        exec "$rrsync" -wo "$RELEASES_DIR"
    fi
    # Découpé en mots puis validé ci-dessous : jamais exécuté tel quel.
    read -r -a ssh_args <<< "$SSH_ORIGINAL_COMMAND"
    set -- "${ssh_args[@]}"
fi

[[ "$(id -un)" == "deploy" ]] || die "à lancer en tant que deploy (sudo -u deploy -H bash $0 …)."

lock() {
    # Même verrou que deploy.sh ; il le prend déjà quand c'est lui qui nous appelle.
    [[ "${CAUSEO_DEPLOY_LOCKED:-}" == "1" ]] && return
    exec 9>"$HOME/.causeo-deploy.lock"
    if ! flock -n 9; then
        echo "Un déploiement est déjà en cours ; attente de sa fin (30 min max)…"
        flock -w 1800 9 || die "toujours en cours après 30 minutes : abandon."
    fi
}

current_id() { basename "$(readlink "$DASHBOARD_DIR/current" 2>/dev/null)" 2>/dev/null || true; }

# Remplace un lien d'un coup : nouveau lien à côté, puis rename par-dessus l'ancien.
switch_link() {
    local name="$1" id="$2"
    ln -sfn "releases/$id" "$DASHBOARD_DIR/.$name.tmp"
    mv -Tf "$DASHBOARD_DIR/.$name.tmp" "$DASHBOARD_DIR/$name"
}

# Ancienne disposition (fichiers du build directement dans DASHBOARD_DIR) : on les range
# dans une version, pour pouvoir y revenir.
adopt_legacy_files() {
    [[ -L "$DASHBOARD_DIR/current" || ! -f "$DASHBOARD_DIR/index.html" ]] && return
    local legacy
    legacy="$(date -u +%Y%m%d%H%M%S)-0000000"
    mkdir -p "$RELEASES_DIR/$legacy"
    find "$DASHBOARD_DIR" -mindepth 1 -maxdepth 1 ! -name releases ! -name 'current' ! -name 'previous' \
        -exec mv -t "$RELEASES_DIR/$legacy/" {} +
    switch_link current "$legacy"
    echo "Ancienne copie du dashboard rangée dans releases/$legacy."
}

# Un onglet ouvert avant la bascule charge encore les fichiers JS de l'ancienne version (pages
# chargées à la demande). On les ajoute à la nouvelle version (liens physiques, pas de copie) ;
# seulement ceux de l'ancienne version elle-même, pour ne pas les accumuler d'une fois à l'autre.
carry_previous_assets() {
    local old="$1" new="$2" file
    # Liste des fichiers propres à cette version, écrite une seule fois (avant tout ajout).
    [[ -f "$new/.release-files" ]] || (cd "$new" && find . -type f -printf '%P\n' | sort) > "$new/.release-files"
    [[ -f "$old/.release-files" ]] || return 0
    while IFS= read -r file; do
        [[ "$file" =~ \.(js|css)$ && ! -e "$new/$file" ]] || continue
        mkdir -p "$(dirname "$new/$file")"
        ln "$old/$file" "$new/$file" 2>/dev/null || cp -p "$old/$file" "$new/$file"
    done < "$old/.release-files"
}

prune() {
    local keep_current keep_previous id count=0
    keep_current="$(current_id)"
    keep_previous="$(basename "$(readlink "$DASHBOARD_DIR/previous" 2>/dev/null)" 2>/dev/null || true)"
    # Les plus récentes d'abord (les identifiants commencent par la date).
    while IFS= read -r id; do
        count=$((count + 1))
        if (( count > KEEP )) && [[ "$id" != "$keep_current" && "$id" != "$keep_previous" ]]; then
            rm -rf -- "${RELEASES_DIR:?}/$id"
        fi
    done < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
}

activate() {
    local id="$1"
    [[ "$id" =~ $ID_RE ]] || die "identifiant de version invalide : $id"
    local new="$RELEASES_DIR/$id"
    [[ -f "$new/index.html" ]] || die "releases/$id/index.html introuvable : la copie a-t-elle réussi ?"

    lock
    adopt_legacy_files

    local old_id
    old_id="$(current_id)"
    if [[ "$old_id" == "$id" ]]; then
        echo "releases/$id est déjà la version servie."
        return
    fi
    if [[ -n "$old_id" && -d "$RELEASES_DIR/$old_id" ]]; then
        carry_previous_assets "$RELEASES_DIR/$old_id" "$new"
        switch_link previous "$old_id"
    else
        carry_previous_assets /nonexistent "$new"
    fi
    # Lisible par nginx, quels que soient les droits envoyés par rsync.
    chmod -R u=rwX,go=rX "$new"

    switch_link current "$id"
    echo "Dashboard : releases/$id en ligne${old_id:+ (avant : $old_id)}."
    prune
}

rollback() {
    local previous
    previous="$(basename "$(readlink "$DASHBOARD_DIR/previous" 2>/dev/null)" 2>/dev/null || true)"
    [[ -n "$previous" && -d "$RELEASES_DIR/$previous" ]] || die "aucune version précédente."
    activate "$previous"
}

list() {
    local current id
    current="$(current_id)"
    find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort -r | while IFS= read -r id; do
        [[ "$id" == "$current" ]] && echo "* $id (en ligne)" || echo "  $id"
    done
}

case "${1:-}" in
    activate) [[ $# -eq 2 ]] || usage; activate "$2" ;;
    rollback) [[ $# -eq 1 ]] || usage; rollback ;;
    list) [[ $# -eq 1 ]] || usage; list ;;
    *) usage ;;
esac
