# Causeo API

API Laravel de Causeo : assistant WhatsApp des commerces (réponses automatiques avec
Claude, commandes, rendez-vous, escalades au gérant), dashboard des gérants et
administration.

| Document | Contenu |
|---|---|
| [DEVELOPPEMENT.md](DEVELOPPEMENT.md) | Poste de développement : projet Supabase causeo-dev, `.env` local, données de démonstration, tests |
| [DOMAINE.md](DOMAINE.md) | Production : domaine, variables du VPS, adresses |
| [SAUVEGARDES.md](SAUVEGARDES.md) | Sauvegarde quotidienne de la base, restauration (test sur causeo-dev, catastrophe) |
| [deploy/](deploy/) | Déploiement sur le VPS (scripts, nginx, Supabase, migration) |

> Le poste de développement n'utilise jamais la base de production : l'application refuse
> de démarrer en local si elle pointe dessus (voir DEVELOPPEMENT.md).
