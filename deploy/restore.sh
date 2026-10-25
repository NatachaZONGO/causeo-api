#!/usr/bin/env bash
# Restaurer une sauvegarde de deploy/backup.sh dans une base PostgreSQL : causeo-dev pour
# le test régulier, ou (catastrophe seulement) la production. À lancer depuis un poste qui
# a la clé privée age, jamais depuis le VPS (qui n'a que la clé publique).
#
#   AGE_IDENTITY=~/causeo-backup.key \
#   TARGET_DATABASE_URL='postgresql://postgres.<projet>:<mot-de-passe>@<hôte-du-pooler>:5432/postgres?sslmode=require' \
#   bash deploy/restore.sh causeo-20261025T030000Z.dump.age
#
# Variables :
#   AGE_IDENTITY         fichier de la clé privée age (obligatoire)
#   TARGET_DATABASE_URL  base cible (Session pooler, port 5432) (obligatoire)
#   ALLOW_PRODUCTION_RESTORE=yes   seule façon de viser la production (voir SAUVEGARDES.md)
#   ASSUME_YES=1         ne pas demander de confirmation
#
# Sur causeo-dev, toutes les tables du schéma public sont d'abord supprimées : les données
# de démonstration sont perdues (php artisan causeo:dev-setup --fresh les recrée). Sur la
# production, seules les tables de la sauvegarde sont remplacées (--clean).

set -Eeuo pipefail
umask 077

PRODUCTION_PROJECT=ppqgsdljypddwfvbxbxz # comme app/Support/ProductionGuard.php

die() { printf '\033[1;31m[erreur] %s\033[0m\n' "$*" >&2; exit 1; }
log() { printf '\033[1;34m==> %s\033[0m\n' "$*"; }

BACKUP="${1:-}"
[[ -n "$BACKUP" && -f "$BACKUP" ]] || die "Usage : restore.sh <fichier.dump.age> (fichier introuvable : ${BACKUP:-aucun})"
[[ -n "${AGE_IDENTITY:-}" && -f "$AGE_IDENTITY" ]] || die "AGE_IDENTITY doit désigner le fichier de la clé privée age."
[[ -n "${TARGET_DATABASE_URL:-}" ]] || die "TARGET_DATABASE_URL manquante (base cible, port 5432)."

if [[ "$TARGET_DATABASE_URL" == *"$PRODUCTION_PROJECT"* && "${ALLOW_PRODUCTION_RESTORE:-}" != "yes" ]]; then
    die "La cible est la base de PRODUCTION. Pour un test, utilisez causeo-dev. En cas de catastrophe seulement : ALLOW_PRODUCTION_RESTORE=yes (voir SAUVEGARDES.md)."
fi

for tool in age pg_restore psql; do
    command -v "$tool" >/dev/null || die "Outil absent : $tool (voir SAUVEGARDES.md, « Poste de restauration »)."
done

# Empreinte publiée à côté de la sauvegarde, si elle a été téléchargée aussi.
if [[ -f "$BACKUP.sha256" ]]; then
    log "Vérification de l'empreinte"
    (cd "$(dirname "$BACKUP")" && sha256sum -c "$(basename "$BACKUP").sha256") || die "Empreinte différente : fichier corrompu."
fi

target_host="$(sed -E 's#^[a-z]+://([^@]*@)?([^:/?]+).*#\2#' <<< "$TARGET_DATABASE_URL")"
target_user="$(sed -E 's#^[a-z]+://([^:@/]+).*#\1#' <<< "$TARGET_DATABASE_URL")"

if [[ "${ASSUME_YES:-}" != "1" ]]; then
    echo "Restaurer $(basename "$BACKUP") dans ${target_user}@${target_host} ?"
    echo "Les tables existantes du schéma public de cette base seront REMPLACÉES."
    read -r -p "Tapez « restaurer » pour continuer : " answer
    [[ "$answer" == "restaurer" ]] || die "Abandon."
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
DUMP="$WORK_DIR/causeo.dump"

log "Déchiffrement"
age --decrypt --identity "$AGE_IDENTITY" --output "$DUMP" "$BACKUP"
pg_restore --list "$DUMP" > "$WORK_DIR/toc.full"

# pgvector : le type est créé dans le même schéma qu'en production (public ou extensions).
vector_schema="$(pg_restore --schema-only --file=- "$DUMP" | grep -m1 -oE '\b(extensions|public)\.vector\b' | cut -d. -f1 || true)"
log "Extension vector (schéma ${vector_schema:-public})"
psql "$TARGET_DATABASE_URL" -X -v ON_ERROR_STOP=1 -q -c "CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA ${vector_schema:-public}"

# Le schéma public existe déjà dans la cible : ne pas le supprimer ni le recréer.
grep -vE ' SCHEMA - public | COMMENT - SCHEMA public ' "$WORK_DIR/toc.full" > "$WORK_DIR/toc.list"

# Hors production : vider le schéma public de la cible (des tables plus récentes que la
# sauvegarde empêcheraient --clean de supprimer celles dont elles dépendent).
if [[ "$TARGET_DATABASE_URL" != *"$PRODUCTION_PROJECT"* ]]; then
    log "Suppression des tables existantes de la cible"
    psql "$TARGET_DATABASE_URL" -X -v ON_ERROR_STOP=1 -q <<'SQL'
DO $$
DECLARE item record;
BEGIN
    FOR item IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('DROP TABLE IF EXISTS public.%I CASCADE', item.tablename);
    END LOOP;
    FOR item IN SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' LOOP
        EXECUTE format('DROP SEQUENCE IF EXISTS public.%I CASCADE', item.sequencename);
    END LOOP;
END $$;
SQL
fi

log "Restauration (peut prendre quelques minutes)"
pg_restore --dbname="$TARGET_DATABASE_URL" --use-list="$WORK_DIR/toc.list" \
    --clean --if-exists --no-owner --no-privileges --single-transaction --exit-on-error "$DUMP"

log "Contrôle"
psql "$TARGET_DATABASE_URL" -X -v ON_ERROR_STOP=1 -P pager=off -c "
    SELECT 'businesses' AS table_name, count(*) AS lignes FROM public.businesses
    UNION ALL SELECT 'conversations', count(*) FROM public.conversations
    UNION ALL SELECT 'messages', count(*) FROM public.messages
    UNION ALL SELECT 'orders', count(*) FROM public.orders
    UNION ALL SELECT 'migrations', count(*) FROM public.migrations;"
psql "$TARGET_DATABASE_URL" -X -At -c "SELECT 'Dernier message : ' || coalesce(max(created_at)::text, 'aucun') FROM public.messages;"

log "Restauration terminée."
if [[ "$TARGET_DATABASE_URL" != *"$PRODUCTION_PROJECT"* ]]; then
    printf '\033[1;33m[attention] Cette base contient maintenant de VRAIES données clients. Une fois le test fini :\n            php artisan causeo:dev-setup --fresh   (remet causeo-dev aux données de démonstration)\033[0m\n'
fi
