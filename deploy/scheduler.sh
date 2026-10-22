#!/usr/bin/env bash
# Activer ou couper le planificateur Laravel sur le VPS (cron, chaque minute).
# Il doit être actif sur le serveur de production (sinon ni billing:sync ni le rapport
# hebdomadaire ne partent) et sur un seul serveur à la fois, la base étant partagée.
#
#   sudo causeo-scheduler on|off|status     (installé par setup.sh)

set -euo pipefail

CRON_FILE=/etc/cron.d/causeo-scheduler
TEMPLATE=/usr/local/share/causeo-scheduler.cron

[[ $EUID -eq 0 ]] || { echo "À lancer en root (sudo)." >&2; exit 1; }

case "${1:-status}" in
    on)
        install -m 644 "$TEMPLATE" "$CRON_FILE"
        echo "Planificateur activé sur ce serveur ($CRON_FILE)."
        ;;
    off)
        rm -f "$CRON_FILE"
        echo "Planificateur coupé sur ce serveur."
        ;;
    status)
        if [[ -f "$CRON_FILE" ]]; then echo "Planificateur : actif"; else echo "Planificateur : coupé"; fi
        ;;
    *)
        echo "Usage : causeo-scheduler on|off|status" >&2
        exit 2
        ;;
esac
