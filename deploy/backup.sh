#!/usr/bin/env bash
# Sauvegarde quotidienne de la base de production (Supabase, schéma public de Laravel).
#
#   sudo causeo-backup          (installé par setup.sh ; lancé chaque nuit à 3 h par cron)
#
# 1. pg_dump au format custom compressé, vérifié par pg_restore --list ;
# 2. chiffré avec age pour la clé publique AGE_RECIPIENT (la clé privée n'est PAS sur le
#    VPS : le serveur ne peut pas relire ses propres sauvegardes) ;
# 3. gardé RETENTION_DAYS jours dans BACKUP_DIR (dossier root, non public) ;
# 4. copié hors du serveur (bucket S3 privé d'un autre projet, via rclone), gardé
#    OFFSITE_RETENTION_DAYS jours ;
# 5. en cas d'échec : e-mail aux admins (artisan causeo:backup-alert) et signal d'échec au
#    service de surveillance (HEALTHCHECK_URL), qui alerte aussi si la sauvegarde ne tourne plus.
#
# Réglages : /etc/causeo-backup.env (modèle : deploy/backup.env.example). Identifiants de
# la base : lus dans le .env de l'API. Restauration : deploy/restore.sh et SAUVEGARDES.md.

set -Eeuo pipefail
umask 077

CONFIG="${CAUSEO_BACKUP_CONFIG:-/etc/causeo-backup.env}"

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }

STEP="démarrage"
WORK_DIR=""

alert() {
    local reason="$1"
    log "ÉCHEC : ${reason}"

    if [[ -n "${HEALTHCHECK_URL:-}" ]]; then
        curl -fsS -m 10 --retry 3 --data-raw "${reason}" "${HEALTHCHECK_URL%/}/fail" >/dev/null 2>&1 || log "Signal d'échec à HEALTHCHECK_URL impossible."
    fi

    if [[ -f "${API_DIR:-/var/www/causeo-api}/artisan" ]]; then
        (cd / && sudo -u "${DEPLOY_USER:-deploy}" -H "${PHP_BIN:-/usr/bin/php8.2}" "${API_DIR:-/var/www/causeo-api}/artisan" \
            causeo:backup-alert "${reason}" --email="${BACKUP_ALERT_EMAILS:-}") || log "E-mail d'alerte impossible."
    fi
}

on_error() {
    local status=$?
    alert "étape « ${STEP} » en erreur (code ${status}) sur $(hostname)"
    exit "$status"
}
trap on_error ERR

cleanup() { [[ -n "$WORK_DIR" ]] && rm -rf "$WORK_DIR"; }
trap cleanup EXIT

[[ $EUID -eq 0 ]] || { echo "À lancer en root (sudo)." >&2; exit 1; }

# --- Réglages -----------------------------------------------------------------------------

STEP="lecture des réglages"
[[ -f "$CONFIG" ]] || { alert "réglages absents : ${CONFIG} (modèle : deploy/backup.env.example)"; exit 1; }
# Fichier de root, illisible par les autres : il contient la clé d'accès au bucket.
[[ "$(stat -c '%U %a' "$CONFIG")" == "root 600" ]] || { alert "${CONFIG} doit appartenir à root avec les droits 600"; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"

API_DIR="${API_DIR:-/var/www/causeo-api}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.2}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/causeo}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
OFFSITE_RETENTION_DAYS="${OFFSITE_RETENTION_DAYS:-30}"
PG_MAJOR="${PG_MAJOR:-17}"
PG_BIN="/usr/lib/postgresql/${PG_MAJOR}/bin"

missing=()
for name in AGE_RECIPIENT OFFSITE_S3_ENDPOINT OFFSITE_S3_REGION OFFSITE_S3_ACCESS_KEY_ID OFFSITE_S3_SECRET_ACCESS_KEY OFFSITE_S3_BUCKET BACKUP_ALERT_EMAILS; do
    [[ -n "${!name:-}" ]] || missing+=("$name")
done
(( ${#missing[@]} == 0 )) || { alert "réglages manquants dans ${CONFIG} : ${missing[*]}"; exit 1; }

for tool in "$PG_BIN/pg_dump" "$PG_BIN/pg_restore" "$PG_BIN/psql" /usr/bin/age /usr/bin/rclone; do
    [[ -x "$tool" ]] || { alert "outil absent : ${tool} (voir l'installation dans SAUVEGARDES.md)"; exit 1; }
done

# Valeur d'une variable du .env de l'API (sans l'exécuter : un mot de passe peut contenir $ ou `).
env_value() {
    local line
    line="$(grep -E "^$1=" "$API_DIR/.env" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%$'\r'}"
    if [[ "$line" =~ ^\"(.*)\"$ || "$line" =~ ^\'(.*)\'$ ]]; then line="${BASH_REMATCH[1]}"; fi
    printf '%s' "$line"
}

STEP="identifiants de la base"
export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD PGSSLMODE PGCONNECT_TIMEOUT=20
PGHOST="$(env_value DB_HOST)"
PGPORT="$(env_value DB_PORT)"; PGPORT="${PGPORT:-5432}"
PGDATABASE="$(env_value DB_DATABASE)"; PGDATABASE="${PGDATABASE:-postgres}"
PGUSER="$(env_value DB_USERNAME)"
PGPASSWORD="$(env_value DB_PASSWORD)"
PGSSLMODE="$(env_value DB_SSLMODE)"; PGSSLMODE="${PGSSLMODE:-require}"
[[ -n "$PGHOST" && -n "$PGUSER" && -n "$PGPASSWORD" ]] || { alert "DB_HOST, DB_USERNAME ou DB_PASSWORD absent de ${API_DIR}/.env"; exit 1; }

# Le pooler Supabase en mode transaction (port 6543) ne convient pas à pg_dump.
[[ "$PGPORT" != "6543" ]] || { alert "DB_PORT=6543 (pooler en mode transaction) : pg_dump demande le mode session (port 5432)"; exit 1; }

# Une seule sauvegarde à la fois.
exec 9>/run/causeo-backup.lock
flock -n 9 || { log "Une sauvegarde est déjà en cours : abandon."; exit 0; }

install -d -m 700 "$BACKUP_DIR"
WORK_DIR="$(mktemp -d "$BACKUP_DIR/.work.XXXXXX")"

# --- Version du serveur -------------------------------------------------------------------

STEP="connexion à la base"
server_major="$("$PG_BIN/psql" -XAtq -c 'SHOW server_version_num')"
server_major=$(( server_major / 10000 ))
if (( server_major > PG_MAJOR )); then
    alert "la base est en PostgreSQL ${server_major} mais pg_dump est en ${PG_MAJOR} : installer postgresql-client-${server_major} et régler PG_MAJOR"
    exit 1
fi
log "Base PostgreSQL ${server_major}, pg_dump ${PG_MAJOR}."

# --- Dump, vérification, chiffrement ------------------------------------------------------

STAMP="$(date -u '+%Y%m%dT%H%M%SZ')"
NAME="causeo-${STAMP}.dump"
PLAIN="$WORK_DIR/$NAME"
ENCRYPTED="$BACKUP_DIR/$NAME.age"

STEP="pg_dump"
# Schéma public seulement : celui de Laravel (les schémas auth, storage… de Supabase ne
# sont pas utilisés et ne se restaurent pas dans un autre projet).
"$PG_BIN/pg_dump" --format=custom --compress=9 --schema=public --file="$PLAIN"

STEP="vérification du dump"
toc="$("$PG_BIN/pg_restore" --list "$PLAIN")"
for table in migrations businesses conversations messages; do
    grep -qE "TABLE DATA public ${table} " <<< "$toc" || { alert "dump incomplet : données de la table ${table} absentes"; exit 1; }
done
dump_size=$(stat -c '%s' "$PLAIN")

STEP="chiffrement"
age --recipient "$AGE_RECIPIENT" --output "$ENCRYPTED.partial" "$PLAIN"
mv "$ENCRYPTED.partial" "$ENCRYPTED"
rm -f "$PLAIN"
(cd "$BACKUP_DIR" && sha256sum "$NAME.age" > "$NAME.age.sha256")
log "Sauvegarde chiffrée : ${ENCRYPTED} ($(stat -c '%s' "$ENCRYPTED") octets, dump ${dump_size} octets)."

# --- Copie hors du serveur ----------------------------------------------------------------

STEP="copie hors du serveur"
# Distant rclone « offsite » décrit par variables d'environnement : aucun fichier de config.
export RCLONE_CONFIG_OFFSITE_TYPE=s3
export RCLONE_CONFIG_OFFSITE_PROVIDER="${OFFSITE_S3_PROVIDER:-Other}"
export RCLONE_CONFIG_OFFSITE_ENDPOINT="$OFFSITE_S3_ENDPOINT"
export RCLONE_CONFIG_OFFSITE_REGION="$OFFSITE_S3_REGION"
export RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID="$OFFSITE_S3_ACCESS_KEY_ID"
export RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY="$OFFSITE_S3_SECRET_ACCESS_KEY"
export RCLONE_CONFIG_OFFSITE_FORCE_PATH_STYLE=true
export RCLONE_CONFIG_OFFSITE_NO_CHECK_BUCKET=true

rclone copyto --retries 5 "$ENCRYPTED" "offsite:${OFFSITE_S3_BUCKET}/${NAME}.age"
rclone copyto --retries 5 "$ENCRYPTED.sha256" "offsite:${OFFSITE_S3_BUCKET}/${NAME}.age.sha256"

STEP="vérification de la copie"
remote_size="$(rclone size --json "offsite:${OFFSITE_S3_BUCKET}/${NAME}.age" | grep -oE '"bytes":[0-9]+' | cut -d: -f2)"
[[ "$remote_size" == "$(stat -c '%s' "$ENCRYPTED")" ]] || { alert "copie distante incomplète (${remote_size:-0} octets)"; exit 1; }
log "Copie distante : offsite:${OFFSITE_S3_BUCKET}/${NAME}.age."

# --- Rétention ----------------------------------------------------------------------------

STEP="rétention"
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'causeo-*.dump.age*' -mtime "+$(( RETENTION_DAYS - 1 ))" -print -delete \
    | sed 's/^/Supprimé (local) : /'
rclone delete --min-age "${OFFSITE_RETENTION_DAYS}d" --include 'causeo-*.dump.age*' "offsite:${OFFSITE_S3_BUCKET}"

printf '%s %s\n' "$STAMP" "$NAME.age" > "$BACKUP_DIR/last-success"

if [[ -n "${HEALTHCHECK_URL:-}" ]]; then
    curl -fsS -m 10 --retry 3 "$HEALTHCHECK_URL" >/dev/null 2>&1 || log "Signal de réussite à HEALTHCHECK_URL impossible."
fi

log "Sauvegarde terminée."
