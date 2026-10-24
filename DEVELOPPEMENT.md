# Développement local

Le poste de développement travaille sur un projet Supabase à part, **causeo-dev**, jamais
sur la base de production : de vrais clients utilisent Causeo.

> **Protection.** Hors production (`APP_ENV` différent de `production`), l'application et
> toutes les commandes artisan refusent de démarrer si la base utilisée ou le stockage
> pointe sur le projet de production (`ppqgsdljypddwfvbxbxz`, dans `DB_HOST`, `DB_USERNAME`,
> `DB_URL`, `SUPABASE_STORAGE_ENDPOINT`, `SUPABASE_STORAGE_MEDIA_URL` ou `SUPABASE_URL`).
> Voir `app/Support/ProductionGuard.php`. Tant que le `.env` local pointe sur la production,
> `composer install` échoue aussi (il lance `artisan package:discover`).
>
> Le `.env` du VPS (`/var/www/causeo-api/.env`, `APP_ENV=production`) n'est pas concerné :
> on n'y touche pas.

## 1. Créer le projet Supabase causeo-dev (gratuit)

1. Sur [supabase.com](https://supabase.com/dashboard), **New project** :
   - nom : `causeo-dev` ;
   - mot de passe de la base : en générer un et le garder (gestionnaire de mots de passe) ;
   - région : **Central EU (Frankfurt)**, comme la production ;
   - formule : **Free**.
2. **pgvector** : *Database → Extensions*, activer `vector`. Les migrations lancent aussi
   `CREATE EXTENSION IF NOT EXISTS vector`, mais l'activer à la main évite toute surprise.
3. **Stockage** : *Storage → New bucket*, créer :
   - `documents` : **privé** (documents envoyés par les gérants) ;
   - `media` : **public** (images du catalogue, logos).
4. **Clés S3 du stockage** : *Project Settings → Storage → S3 Connection*, activer la
   connexion S3, puis *New access key*. Noter l'endpoint, la région, l'access key ID et le
   secret (affiché une seule fois).
5. **Connexion à la base** : bouton *Connect* en haut du tableau de bord, onglet
   *Connection string*, **Session pooler** (compatible IPv4, port 5432). L'utilisateur est
   de la forme `postgres.<identifiant-du-projet-dev>`.

Un projet gratuit est mis en pause après une semaine sans activité : le relancer depuis le
tableau de bord (*Restore project*).

## 2. Le `.env` local

Partir de `.env.example` (`cp .env.example .env`, puis `php artisan key:generate`) ou
modifier le `.env` existant. À changer pour causeo-dev :

| Variable | Valeur |
|---|---|
| `APP_ENV` | `local` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | hôte du Session pooler, par exemple `aws-0-eu-central-1.pooler.supabase.com` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | `postgres.<identifiant-du-projet-dev>` |
| `DB_PASSWORD` | mot de passe de la base causeo-dev |
| `DB_SSLMODE` | `require` |
| `SUPABASE_STORAGE_ENDPOINT` | `https://<identifiant-du-projet-dev>.supabase.co/storage/v1/s3` |
| `SUPABASE_STORAGE_REGION` | région affichée dans *S3 Connection* (par exemple `eu-central-1`) |
| `SUPABASE_STORAGE_KEY` / `SUPABASE_STORAGE_SECRET` | clé S3 créée à l'étape 4 |
| `SUPABASE_STORAGE_DOCUMENTS_BUCKET` | `documents` |
| `SUPABASE_STORAGE_MEDIA_BUCKET` | `media` |
| `SUPABASE_STORAGE_MEDIA_URL` | `https://<identifiant-du-projet-dev>.supabase.co/storage/v1/object/public/media` |

Recommandé aussi, pour ne rien envoyer à de vrais clients depuis un poste de développement :

| Variable | Valeur |
|---|---|
| `APP_KEY` | une clé propre au poste (`php artisan key:generate`), **jamais** celle du VPS |
| `MAIL_MAILER` | `log` (les e-mails vont dans `storage/logs/laravel.log`) |
| `WHATSAPP_TOKEN`, `CAUSEO_NOTIFY_PHONE_NUMBER_ID` | vides, ou un numéro de test Meta |
| `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` | des clés de développement (Gemini sert aux embeddings du catalogue) |

Puis vider le cache de configuration : `php artisan config:clear`.

## 3. Préparer la base

```bash
php artisan causeo:dev-setup --fresh
```

La commande (refusée en production et sans PostgreSQL) :

1. lance les migrations (`--fresh` supprime d'abord toutes les tables, après confirmation) ;
2. crée les formules (`PlanSeeder`) et les modèles de business (`BusinessTemplateSeeder`) ;
3. crée les données de démonstration (`DemoSeeder`, sauf avec `--without-demo`).

Sans `--fresh`, elle applique seulement les nouvelles migrations et remplace les données de
démonstration (les autres données de la base sont gardées).

### Données de démonstration

Mot de passe de tous les comptes : `demo1234`.

| Compte | Business |
|---|---|
| `boutique@demo.causeo.test` | **Awa Couture** : boutique de mode (wax, Faso Dan Fani, lin), module Commandes, livraison et retrait. Catalogue avec prix, 3 conversations, une commande en cours, une commande livrée, une escalade en attente. |
| `coiffure@demo.causeo.test` | **Salon Belle Tresse** : coiffure, module Rendez-vous. Prestations et tarifs, 3 conversations, une demande de rendez-vous à confirmer, un rendez-vous confirmé. |
| `admin@demo.causeo.test` | administrateur Causeo (pages admin du dashboard) |

Les deux businesses sont en formule Pro. Le catalogue n'est trouvé par l'assistant
(recherche vectorielle) que si `GEMINI_API_KEY` est renseignée au moment du seeder ; sinon
la commande l'indique : relancer `php artisan db:seed --class=DemoSeeder` une fois la clé
en place.

## 4. Lancer l'API et les tests

```bash
php artisan serve            # http://localhost:8000
php artisan queue:work       # réponses du bot, alertes : passent par la file
vendor/bin/phpunit           # tests (SQLite en mémoire, voir phpunit.xml)
```

Les tests PostgreSQL (`tests/Postgres`) ne tournent qu'avec `CAUSEO_PGSQL_TESTS=true` et
vident la base : ne jamais les lancer sur causeo-dev si elle contient des données utiles.
En CI, ils tournent sur un PostgreSQL jetable (job « Tests (PostgreSQL) »).
