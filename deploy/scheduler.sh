#!/usr/bin/env bash
# Activer ou couper le planificateur Laravel sur le VPS (cron, chaque minute).
# Un seul environnement doit le faire tourner : l'activer ici au moment de la bascule,
# en même temps qu'on le coupe sur Render (RUN_SCHEDULER=false).
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
