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
2. **pgvector** : rien à faire, les migrations installent `vector` dans le schéma `public`
   (voir « pgvector » au paragraphe 3). Pour l'activer à la main (*Database → Extensions*),
   choisir le schéma **`public`**, pas `extensions` proposé par défaut.
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

### pgvector

Les colonnes `vector(1536)` (documents, médias, réponses apprises, catalogue) attendent le
type dans le schéma **`public`** : le search_path du Session pooler se limite à `public`.
Or un projet Supabase neuf installe l'extension dans `extensions` (choix par défaut de
*Database → Extensions*), et la migration `document_chunks` échouait alors avec
`type "vector" does not exist`.

Les migrations (`App\Support\Pgvector`, SQL dans `database/sql/pgvector.sql`) :

- créent l'extension dans `public` si elle est absente ;
- la laissent telle quelle si elle y est déjà : c'est le cas de la production, où rien ne change ;
- la déplacent dans `public` si elle est ailleurs. Si Supabase refuse le déplacement, elles
  la suppriment et la recréent dans `public`, ce que PostgreSQL n'accepte que si aucune
  colonne ne l'utilise encore (cas d'un projet neuf).

Si la migration s'arrête sur « L'extension vector est dans le schéma « extensions » … »,
des colonnes l'utilisent déjà dans ce schéma : sur causeo-dev, le plus simple est de
désactiver `vector` dans *Database → Extensions* (ce qui supprime ces colonnes), de la
réactiver dans le schéma `public`, puis de relancer `php artisan causeo:dev-setup --fresh`.

Pour vérifier le schéma (*SQL Editor*) :

```sql
SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'vector';
```

### Données de démonstration

Mot de passe de tous les comptes : `demo1234`.

| Compte | Business |
|---|---|
| `boutique@demo.causeo.test` | **Awa Couture** : boutique de mode (wax, Faso Dan Fani, lin), modules Commandes et Catalogue, livraison et retrait. Catalogue avec variantes (tailles, couleurs) et stock, dont une taille en stock faible et une en rupture, document de prix, 3 conversations, une commande en cours, une commande livrée, une escalade en attente. |
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
