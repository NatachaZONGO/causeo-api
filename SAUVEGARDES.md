# Sauvegardes de la base de production

L'offre gratuite de Supabase ne sauvegarde pas la base. Le VPS en fait une copie chaque
nuit :

| | |
|---|---|
| Quoi | le schéma `public` (toutes les tables de Laravel), `pg_dump` au format custom compressé |
| Quand | chaque nuit à 3 h (heure du serveur, UTC = heure de Ouagadougou) |
| Chiffrement | [age](https://age-encryption.org), clé publique sur le VPS, **clé privée hors du VPS** |
| Sur le VPS | `/var/backups/causeo/` (root, droits 700), 14 jours |
| Hors du VPS | bucket privé `causeo-backups` d'un autre service, 30 jours |
| Échec | e-mail aux admins Causeo et à `BACKUP_ALERT_EMAILS`, signal au service de surveillance |
| Journal | `/var/log/causeo-backup.log` |

Fichiers : [deploy/backup.sh](deploy/backup.sh) (sauvegarde), [deploy/restore.sh](deploy/restore.sh)
(restauration), [deploy/backup.env.example](deploy/backup.env.example) (réglages),
[deploy/cron/causeo-backup](deploy/cron/causeo-backup) (tâche de 3 h).

> **Ce qui n'est PAS sauvegardé** : les fichiers du stockage Supabase (documents des
> gérants, médias, logos, captures de paiement) et le `.env` du VPS. Le `.env`, et surtout
> `APP_KEY` (sans elle, les tokens WhatsApp de la base sont illisibles), doivent être
> gardés dans le gestionnaire de mots de passe (voir DOMAINE.md).

## 1. Préparer les clés et le bucket (une fois, depuis votre ordinateur)

### Clé de chiffrement age

Sur votre ordinateur (pas sur le VPS) :

```bash
# Windows : winget install FiloSottile.age   —   macOS : brew install age   —   Ubuntu : sudo apt install age
age-keygen -o causeo-backup.key
```

La commande affiche la clé publique (`age1…`) : elle ira dans `/etc/causeo-backup.env`.
Le fichier `causeo-backup.key` est la **clé privée** :

- la ranger dans le gestionnaire de mots de passe, plus une copie hors ligne (clé USB
  rangée, papier) ;
- **sans elle, aucune sauvegarde ne peut être relue** ;
- ne jamais la copier sur le VPS ni dans un dépôt git.

### Bucket hors du VPS

Un bucket S3 privé chez un **autre** service que la production, avec une clé réservée aux
sauvegardes (les fichiers sont chiffrés : même lus, ils sont inutilisables sans la clé privée).

- **Recommandé : Cloudflare R2** (10 Go gratuits, pas de mise en pause). *R2 → Create
  bucket* `causeo-backups`, puis *Manage R2 API Tokens → Create API token*, droits
  *Object Read & Write* limités à ce bucket. Réglages :
  `OFFSITE_S3_PROVIDER=Cloudflare`, `OFFSITE_S3_ENDPOINT=https://<id-du-compte>.r2.cloudflarestorage.com`,
  `OFFSITE_S3_REGION=auto`.
- **Ou un projet Supabase dédié** `causeo-backups` (gratuit, ni la production ni
  causeo-dev) : *Storage → New bucket* `causeo-backups` **privé**, puis *Project Settings →
  Storage → S3 Connection → New access key*. Réglages : `OFFSITE_S3_PROVIDER=Other`,
  endpoint et région affichés sur cette page. Attention : un projet gratuit inactif est mis
  en pause au bout d'une semaine ; si les envois échouent, l'alerte le signale, il faut alors
  le relancer depuis le tableau de bord.

### Surveillance (facultatif, recommandé)

Sur [healthchecks.io](https://healthchecks.io) (gratuit), créer un check « causeo-backup »,
période **1 jour**, tolérance **2 heures**, alertes par e-mail. Copier son URL de ping dans
`HEALTHCHECK_URL` : le service prévient si la sauvegarde échoue **ou ne tourne plus du tout**
(cron arrêté, serveur éteint), ce que l'e-mail d'échec ne peut pas faire.

## 2. Installation sur le VPS

À faire une fois, en SSH, **après le déploiement** du commit qui ajoute ces fichiers
(`/var/www/causeo-api/deploy/backup.sh` doit exister).

```bash
ssh deploy@<ip-du-vps>
sudo -i
cd /var/www/causeo-api

# 1. Version de la base de production (pg_dump doit être au moins de cette version)
sudo -u deploy php artisan tinker --execute="echo DB::scalar('show server_version');"
#    → par exemple « 17.4 » : PG_MAJOR=17 (ou 15 si la base est en 15 : un client 17 convient aussi)

# 2. Client PostgreSQL 17 (dépôt officiel), age et rclone
install -d -m 755 /usr/share/postgresql-common/pgdg
curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
apt-get update
apt-get install -y postgresql-client-17 age rclone
/usr/lib/postgresql/17/bin/pg_dump --version

# 3. Script et dossier des sauvegardes
install -m 755 deploy/backup.sh /usr/local/sbin/causeo-backup
install -d -m 700 /var/backups/causeo

# 4. Réglages (clé publique age, bucket, adresses d'alerte, URL de surveillance)
install -m 600 -o root -g root deploy/backup.env.example /etc/causeo-backup.env
nano /etc/causeo-backup.env

# 5. Tester l'alerte : un e-mail doit arriver
sudo -u deploy php artisan causeo:backup-alert "Test d'alerte, à ignorer" --email=vous@exemple.com

# 6. Première sauvegarde, à la main
causeo-backup
tail -n 20 /var/log/causeo-backup.log 2>/dev/null; ls -lh /var/backups/causeo/
#    vérifier aussi le fichier dans le bucket causeo-backups

# 7. Planifier chaque nuit à 3 h
timedatectl | grep "Time zone"          # UTC attendu (3 h UTC = 3 h à Ouagadougou)
install -m 644 deploy/cron/causeo-backup /etc/cron.d/causeo-backup
```

Le lendemain matin : `tail /var/log/causeo-backup.log` doit finir par « Sauvegarde terminée. ».

Ensuite, `setup.sh` maintient tout cela (paquets, script, tâche de 3 h si
`/etc/causeo-backup.env` existe). Après une modification de `deploy/backup.sh`, le
réinstaller : `sudo install -m 755 /var/www/causeo-api/deploy/backup.sh /usr/local/sbin/causeo-backup`.

## 3. Tester une restauration sur causeo-dev (chaque mois)

Une sauvegarde jamais restaurée n'est pas une sauvegarde. Depuis votre ordinateur, avec
la clé privée et la base **causeo-dev** (voir DEVELOPPEMENT.md) :

**Poste de restauration** : `age`, `pg_restore` et `psql` en version 17 au moins.
Sous Windows, le plus simple est WSL (Ubuntu) avec les commandes d'installation du
paragraphe 2 (étape 2) ; sinon l'installateur PostgreSQL 17 (« Command Line Tools »
seulement) et `winget install FiloSottile.age`, depuis Git Bash.

1. Télécharger la sauvegarde la plus récente (`causeo-AAAAMMJJTHHMMSSZ.dump.age` et son
   `.sha256`) depuis le bucket `causeo-backups` (tableau de bord R2 ou Supabase).
2. Restaurer dans causeo-dev (URL du *Session pooler* de causeo-dev, port 5432) :

   ```bash
   AGE_IDENTITY=~/causeo-backup.key \
   TARGET_DATABASE_URL='postgresql://postgres.<id-causeo-dev>:<mot-de-passe>@aws-0-eu-central-1.pooler.supabase.com:5432/postgres?sslmode=require' \
   bash deploy/restore.sh ~/Téléchargements/causeo-20261025T030000Z.dump.age
   ```

   Le script vérifie l'empreinte, refuse de viser la production, demande confirmation,
   vide le schéma public de causeo-dev, restaure, puis affiche le nombre de businesses,
   conversations, messages et commandes, et la date du dernier message : elle doit être
   celle de la nuit de la sauvegarde.
3. Facultatif : lancer l'API sur causeo-dev et ouvrir le dashboard pour vérifier.
4. **causeo-dev contient alors de vraies données clients** : une fois le test fini,
   `php artisan causeo:dev-setup --fresh` remet les données de démonstration.

## 4. Restaurer la production (catastrophe)

Base de production effacée, corrompue ou projet Supabase perdu. Les données écrites
depuis la dernière sauvegarde (au plus 24 h) sont perdues ; les conversations restent
lisibles dans l'application WhatsApp Business des gérants.

1. **Couper l'application** sur le VPS :

   ```bash
   sudo -u deploy php /var/www/causeo-api/artisan down
   sudo supervisorctl stop 'causeo-worker:*'
   sudo causeo-scheduler off
   sudo rm -f /etc/cron.d/causeo-backup     # ne pas sauvegarder une base vide pendant l'opération
   ```

2. **Choisir la base cible** :
   - le même projet Supabase s'il existe encore ;
   - sinon un nouveau projet (Frankfurt, pgvector activé : voir DEVELOPPEMENT.md), puis
     changer `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` dans `/var/www/causeo-api/.env`, et
     remplacer l'identifiant de production dans `app/Support/ProductionGuard.php` (et dans
     `deploy/restore.sh`) par celui du nouveau projet.
3. **Restaurer** depuis votre ordinateur, avec la sauvegarde la plus récente :

   ```bash
   ALLOW_PRODUCTION_RESTORE=yes AGE_IDENTITY=~/causeo-backup.key \
   TARGET_DATABASE_URL='postgresql://postgres.<id-production>:<mot-de-passe>@<hôte-du-pooler>:5432/postgres?sslmode=require' \
   bash deploy/restore.sh causeo-<date>.dump.age
   ```

   Dans un nouveau projet, `ALLOW_PRODUCTION_RESTORE` est inutile (l'identifiant diffère),
   mais la restauration est la même.
4. **Remettre en route** sur le VPS :

   ```bash
   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api   # migrations, caches, worker
   sudo -u deploy php /var/www/causeo-api/artisan up
   sudo causeo-scheduler on
   sudo install -m 644 /var/www/causeo-api/deploy/cron/causeo-backup /etc/cron.d/causeo-backup
   sudo causeo-backup                       # une sauvegarde tout de suite
   ```

5. Vérifier qu'un message WhatsApp de test reçoit une réponse, et prévenir les gérants si
   des données des dernières heures manquent.

## Dépannage

| Message dans le journal ou l'e-mail | Que faire |
|---|---|
| `réglages absents` / `réglages manquants` | compléter `/etc/causeo-backup.env` (droits 600, root) |
| `la base est en PostgreSQL N mais pg_dump est en M` | `apt-get install postgresql-client-N`, puis `PG_MAJOR=N` |
| `étape « connexion à la base »` | identifiants du `.env`, projet Supabase en pause, port 5432 (pas 6543) |
| `étape « copie hors du serveur »` | clé S3, bucket, projet de sauvegarde en pause |
| `copie distante incomplète` | relancer `sudo causeo-backup` ; si cela se répète, vérifier l'espace du bucket |
| Aucun e-mail d'alerte | tester `causeo:backup-alert` (étape 5) et la configuration e-mail du `.env` |
