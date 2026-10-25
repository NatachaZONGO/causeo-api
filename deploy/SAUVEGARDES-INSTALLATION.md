# Installer les sauvegardes sur le VPS : checklist pas à pas

Version détaillée de [SAUVEGARDES.md](../SAUVEGARDES.md), section 2 (installation) et
section 3 (test de restauration). Chaque bloc contient **une seule commande** : la taper
(ou la coller) puis Entrée, et comparer avec « Attendu ».

Préalables (déjà faits) :

- clé age générée, clé privée dans le gestionnaire de mots de passe ; clé publique :
  `age1920mc3fz9qvcvfc47q9uptwuavkv86q7w2lcjqhfa3vw56wvn3as3dysll` ;
- `age` installé sur le VPS ;
- Cloudflare R2 : bucket privé `causeo-backups`, jeton *Object Read & Write* limité à ce
  bucket ; Access Key ID, Secret Access Key et endpoint dans le gestionnaire de mots de passe.

Règles pendant toute l'installation :

- aucun secret ne se tape dans une ligne de commande (il resterait dans l'historique) : les
  secrets se collent **dans nano** ou dans une saisie masquée (`read -rs`) ;
- aucun secret ne s'envoie à personne (ni dans un ticket, ni à un assistant) : en cas
  d'erreur, ne partager que le message d'erreur.

---

## Étape 0 — Se connecter en root

Depuis votre PC :

```bash
ssh deploy@<IP_DU_VPS>
```

```bash
sudo -i
```

Attendu : l'invite se termine par `#` (root).

```bash
cd /var/www/causeo-api
```

---

## Étape 1 — Vérifier que le code des sauvegardes est déployé

### 1.1 Le script existe

```bash
ls -l /var/www/causeo-api/deploy/backup.sh
```

Attendu : une ligne `-rwxr-xr-x … deploy deploy … backup.sh`.
Sinon (`No such file or directory`) : le déploiement n'est pas passé, voir 1.4.

### 1.2 Le commit des sauvegardes est inclus

```bash
sudo -u deploy git -C /var/www/causeo-api merge-base --is-ancestor 6e04e43 HEAD && echo OK
```

Attendu : `OK`. Sinon (rien d'affiché) : voir 1.4.

```bash
sudo -u deploy git -C /var/www/causeo-api log -1 --oneline
```

Attendu : le dernier commit de `main` (au moment de la rédaction : `564a71a pgvector : …`
ou plus récent).

### 1.3 La commande d'alerte est connue de Laravel

```bash
sudo -u deploy php artisan list causeo | grep backup-alert
```

Attendu : une ligne `causeo:backup-alert  Prévenir par e-mail…`.
Sinon : le code est là mais les caches sont anciens ; voir 1.4.

### 1.4 Si une vérification a échoué : redéployer l'API

```bash
sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api
```

Attendu : le déploiement se termine sans `[erreur]`. Reprendre ensuite en 1.1.

---

## Étape 2 — Connexion à la base : Session pooler (port 5432)

La sauvegarde lit les identifiants dans `/var/www/causeo-api/.env`. Ils doivent viser le
**Session pooler** de Supabase (port 5432), pas le Transaction pooler (6543, refusé par le
script) ni la connexion directe `db.<id>.supabase.co`.

### 2.1 Lire les réglages (sans le mot de passe)

```bash
grep -E '^DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|SSLMODE)=' .env
```

Attendu :

```
DB_CONNECTION=pgsql
DB_HOST=aws-0-eu-central-1.pooler.supabase.com     (ou aws-1-…, hôte en « pooler.supabase.com »)
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.ppqgsdljypddwfvbxbxz
DB_SSLMODE=require                                  (ligne facultative : require par défaut)
```

```bash
grep -c '^DB_URL=' .env
```

Attendu : `0` (le script n'utilise pas `DB_URL` ; il lui faut `DB_HOST`, `DB_USERNAME`,
`DB_PASSWORD`).

### 2.2 Si ce n'est pas le Session pooler

Récupérer les bonnes valeurs : tableau de bord Supabase, projet de **production** →
bouton *Connect* → *Connection string* → **Session pooler**. On y lit l'hôte, le port
(5432) et l'utilisateur (`postgres.ppqgsdljypddwfvbxbxz`). Le mot de passe ne change pas.

```bash
nano .env
```

Corriger `DB_HOST`, `DB_PORT=5432` et `DB_USERNAME`, enregistrer (Ctrl+O, Entrée), quitter
(Ctrl+X). Puis appliquer à l'application :

```bash
sudo -u deploy php artisan config:cache
```

```bash
systemctl reload php8.2-fpm
```

```bash
supervisorctl restart 'causeo-worker:*'
```

Attendu : pas d'erreur ; l'application répond toujours (ouvrir le dashboard). Reprendre en 2.1.

---

## Étape 3 — Version de PostgreSQL et client correspondant

### 3.1 Version de la base de production

```bash
sudo -u deploy php artisan tinker --execute="echo DB::scalar('show server_version');"
```

Attendu : un numéro comme `17.6`. Le **premier nombre** est la version majeure (ici 17).
Noter-la : c'est `<VERSION_PG>` dans la suite.

- 15, 16 ou 17 : installer le client **17** (`<VERSION_PG>` = 17 dans la suite).
- 18 ou plus : installer le client de cette version (par exemple 18).

Sinon (erreur de connexion) : revoir l'étape 2, et vérifier que le projet Supabase n'est
pas en pause.

### 3.2 Le client est-il déjà installé ?

`setup.sh` installe peut-être déjà le client 17.

```bash
ls /usr/lib/postgresql/
```

Attendu : un dossier `17` (ou `<VERSION_PG>`). Si oui, passer à 3.5. Sinon
(`No such file or directory` ou autre version), continuer.

### 3.3 Dépôt officiel PostgreSQL

```bash
ls /etc/apt/sources.list.d/pgdg.list
```

Si le fichier existe, passer à 3.4. Sinon :

```bash
apt-get install -y postgresql-common
```

```bash
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
```

Attendu : le script se termine par la mise à jour d'apt, sans `E:`.

### 3.4 Installer le client

```bash
apt-get install -y postgresql-client-17
```

(remplacer `17` par `<VERSION_PG>` si elle est plus récente)

Attendu : se termine sans `E:`.
Sinon `Unable to locate package` : refaire 3.3, puis `apt-get update`.

### 3.5 Vérifier les outils

```bash
/usr/lib/postgresql/17/bin/pg_dump --version
```

Attendu : `pg_dump (PostgreSQL) 17.x`.

```bash
age --version
```

Attendu : `v1.x.x`.

```bash
rclone version
```

Attendu : `rclone v1.xx`. Sinon (`command not found`) :

```bash
apt-get install -y rclone
```

Noter la version de rclone : si elle est **inférieure à 1.59**, il faudra mettre
`OFFSITE_S3_PROVIDER=Other` au lieu de `Cloudflare` à l'étape 5.

---

## Étape 4 — Script et dossier des sauvegardes

```bash
install -m 755 deploy/backup.sh /usr/local/sbin/causeo-backup
```

```bash
install -d -m 700 /var/backups/causeo
```

```bash
command -v causeo-backup
```

Attendu : `/usr/local/sbin/causeo-backup`.

```bash
stat -c '%U %a' /var/backups/causeo
```

Attendu : `root 700`.

---

## Étape 5 — Réglages : `/etc/causeo-backup.env`

Il n'y a **pas de configuration rclone à écrire** : le script décrit le bucket à rclone à
partir de ce seul fichier. Ne pas créer de `rclone.conf` (le secret serait en double).

### 5.1 Le fichier n'existe pas encore

```bash
ls -l /etc/causeo-backup.env
```

Attendu : `No such file or directory`.
S'il existe déjà : **ne pas** faire 5.2 (cela l'écraserait), passer directement à 5.3.

### 5.2 Créer le fichier à partir du modèle, avec les bons droits

```bash
install -m 600 -o root -g root deploy/backup.env.example /etc/causeo-backup.env
```

### 5.3 Le remplir

```bash
nano /etc/causeo-backup.env
```

Modifier les lignes suivantes (le reste du fichier ne change pas). Remplacer **toute** la
partie `<…>`, chevrons compris, sans espaces ni guillemets autour du `=` :

```bash
AGE_RECIPIENT=age1920mc3fz9qvcvfc47q9uptwuavkv86q7w2lcjqhfa3vw56wvn3as3dysll

OFFSITE_S3_PROVIDER=Cloudflare
OFFSITE_S3_ENDPOINT=<ENDPOINT_R2>
OFFSITE_S3_REGION=auto
OFFSITE_S3_ACCESS_KEY_ID=<ACCESS_KEY_ID_R2>
OFFSITE_S3_SECRET_ACCESS_KEY=<SECRET_ACCESS_KEY_R2>
OFFSITE_S3_BUCKET=causeo-backups

BACKUP_ALERT_EMAILS=<VOTRE_EMAIL>

HEALTHCHECK_URL=

PG_MAJOR=<VERSION_PG>
```

| Emplacement | D'où vient la valeur |
|---|---|
| `AGE_RECIPIENT` | la clé **publique** ci-dessus (jamais la clé privée `AGE-SECRET-KEY-…`) |
| `<ENDPOINT_R2>` | gestionnaire de mots de passe (aussi : Cloudflare → R2 → *causeo-backups* → *Settings* → *S3 API*). Forme : `https://<id-du-compte>.r2.cloudflarestorage.com`. **Enlever** un éventuel `/causeo-backups` à la fin |
| `<ACCESS_KEY_ID_R2>` | gestionnaire de mots de passe : *Access Key ID* du jeton R2 (32 caractères) — pas le « Token value » |
| `<SECRET_ACCESS_KEY_R2>` | gestionnaire de mots de passe : *Secret Access Key* du jeton R2 (64 caractères) |
| `OFFSITE_S3_PROVIDER` | `Cloudflare` ; `Other` si rclone < 1.59 (étape 3.5) |
| `<VOTRE_EMAIL>` | votre adresse ; plusieurs : séparées par des virgules, sans espace. Les admins Causeo sont prévenus en plus |
| `HEALTHCHECK_URL` | laisser **vide** pour l'instant (étape 9) |
| `<VERSION_PG>` | `17` (ou la version installée à l'étape 3.4) |

Coller dans nano : clic droit, ou Maj+Inser (selon le terminal). Enregistrer : Ctrl+O puis
Entrée. Quitter : Ctrl+X.

### 5.4 Vérifier le fichier (sans afficher les secrets)

```bash
stat -c '%U:%G %a' /etc/causeo-backup.env
```

Attendu : `root:root 600`. Sinon :

```bash
chown root:root /etc/causeo-backup.env
```

```bash
chmod 600 /etc/causeo-backup.env
```

```bash
grep -nE '^[A-Z_]+=.*<' /etc/causeo-backup.env
```

Attendu : **rien**. Sinon : un emplacement `<…>` n'a pas été remplacé (numéro de ligne affiché).

```bash
grep -nE '^[A-Z_]+=$' /etc/causeo-backup.env
```

Attendu : seulement `HEALTHCHECK_URL=`.

```bash
grep -E '^(OFFSITE_S3_(PROVIDER|ENDPOINT|REGION|BUCKET)|PG_MAJOR)=' /etc/causeo-backup.env
```

Attendu : vos valeurs (aucune n'est secrète), endpoint en `https://….r2.cloudflarestorage.com`.

---

## Étape 6 — Tester l'accès au bucket R2 (avant la première sauvegarde)

Dans un **sous-shell**, qui sera fermé à la fin : les secrets lus dans le fichier ne restent
pas dans votre session root.

```bash
bash
```

```bash
set -a; . /etc/causeo-backup.env; set +a
```

```bash
export RCLONE_S3_PROVIDER="$OFFSITE_S3_PROVIDER"
```

```bash
export RCLONE_S3_ENDPOINT="$OFFSITE_S3_ENDPOINT"
```

```bash
export RCLONE_S3_REGION="$OFFSITE_S3_REGION"
```

```bash
export RCLONE_S3_ACCESS_KEY_ID="$OFFSITE_S3_ACCESS_KEY_ID"
```

```bash
export RCLONE_S3_SECRET_ACCESS_KEY="$OFFSITE_S3_SECRET_ACCESS_KEY"
```

```bash
rclone lsl ":s3:$OFFSITE_S3_BUCKET"
```

Attendu : **rien** (bucket vide) et pas d'erreur. Sinon :

| Message | Cause |
|---|---|
| `SignatureDoesNotMatch` / `InvalidAccessKeyId` | Access Key ID ou Secret mal collé (espace, caractère en trop) : refaire 5.3 |
| `AccessDenied` | le jeton n'a pas les droits sur `causeo-backups` : vérifier le jeton dans Cloudflare |
| `NoSuchBucket` | nom du bucket, ou endpoint qui se termine par `/causeo-backups` |
| `no such host` / `dial tcp` | endpoint mal recopié |

Garder ce sous-shell ouvert pour l'étape 7.2, ou le fermer maintenant :

```bash
exit
```

---

## Étape 7 — Première sauvegarde, à la main

### 7.1 Lancer la sauvegarde

Lancé à la main, le script écrit à l'écran ; `tee` en garde aussi une copie dans le journal.

```bash
causeo-backup 2>&1 | tee -a /var/log/causeo-backup.log
```

Attendu (en une à quelques minutes), quatre lignes de ce genre :

```
2026-10-25T10:12:01Z Base PostgreSQL 17, pg_dump 17.
2026-10-25T10:12:09Z Sauvegarde chiffrée : /var/backups/causeo/causeo-20261025T101201Z.dump.age (… octets, dump … octets).
2026-10-25T10:12:12Z Copie distante : offsite:causeo-backups/causeo-20261025T101201Z.dump.age.
2026-10-25T10:12:13Z Sauvegarde terminée.
```

Sinon : une ligne `ÉCHEC : …` indique l'étape ; voir le tableau *Dépannage* de
[SAUVEGARDES.md](../SAUVEGARDES.md). Un e-mail d'alerte part aussi (c'est normal).

```bash
ls -lh /var/backups/causeo/
```

Attendu : `causeo-AAAAMMJJTHHMMSSZ.dump.age`, son `.sha256` et `last-success`, tous à `root`.

### 7.2 Vérifier l'arrivée dans R2

**Dans le tableau de bord Cloudflare** : R2 → `causeo-backups` → *Objects*. Attendu : les
deux fichiers `causeo-….dump.age` et `causeo-….dump.age.sha256`, la taille du `.dump.age`
égale à celle affichée par `ls -lh`.

**Ou depuis le VPS** : refaire l'étape 6 (sous-shell, `set -a…`, les cinq `export`), puis :

```bash
rclone lsl ":s3:$OFFSITE_S3_BUCKET"
```

Attendu : les deux fichiers avec leur taille en octets ; comparer avec :

```bash
ls -l /var/backups/causeo/
```

```bash
exit
```

### 7.3 Vérifier l'alerte e-mail en cas d'échec

On simule un échec avec une **copie** des réglages qui demande une version de pg_dump
inexistante (le vrai fichier n'est pas touché, la base non plus).

```bash
install -m 600 -o root -g root /etc/causeo-backup.env /root/causeo-backup-test.env
```

```bash
sed -i 's/^PG_MAJOR=.*/PG_MAJOR=99/' /root/causeo-backup-test.env
```

```bash
CAUSEO_BACKUP_CONFIG=/root/causeo-backup-test.env causeo-backup
```

Attendu à l'écran :

```
… ÉCHEC : outil absent : /usr/lib/postgresql/99/bin/pg_dump (voir l'installation dans SAUVEGARDES.md)
Alerte envoyée à N destinataire(s).
```

puis, dans les minutes qui suivent, un e-mail « **Échec de la sauvegarde de la base
Causeo** » dans votre boîte (et chez les admins Causeo). Regarder aussi les spams.

Supprimer la copie de test :

```bash
rm /root/causeo-backup-test.env
```

Sinon :

- `E-mail d'alerte impossible` ou `Envoi impossible à …` : la configuration e-mail du `.env`
  (Brevo, voir DOMAINE.md) est en cause. Tester l'envoi seul :

  ```bash
  sudo -u deploy php artisan causeo:backup-alert "Test d'alerte, à ignorer" --email=<VOTRE_EMAIL>
  ```

  et lire l'erreur affichée.
- `Aucun destinataire` : `BACKUP_ALERT_EMAILS` est vide ou mal écrit (étape 5.3).
- Message affiché mais aucun e-mail : vérifier les spams, puis les journaux Brevo.

---

## Étape 8 — Activer la sauvegarde de 3 h

### 8.1 Fuseau horaire du serveur

```bash
timedatectl | grep "Time zone"
```

Attendu : `Time zone: Etc/UTC (UTC, +0000)` (3 h UTC = 3 h à Ouagadougou). Si un autre
fuseau s'affiche, la sauvegarde tournera à 3 h de ce fuseau : acceptable, mais le noter.

### 8.2 Installer la tâche

```bash
install -m 644 -o root -g root deploy/cron/causeo-backup /etc/cron.d/causeo-backup
```

### 8.3 Vérifier qu'elle est active

```bash
stat -c '%U %a' /etc/cron.d/causeo-backup
```

Attendu : `root 644` (cron ignore un fichier modifiable par d'autres que root).

```bash
grep '^0 3' /etc/cron.d/causeo-backup
```

Attendu : `0 3 * * * root /usr/local/sbin/causeo-backup >> /var/log/causeo-backup.log 2>&1`.

```bash
systemctl is-active cron
```

Attendu : `active`. Sinon :

```bash
systemctl enable --now cron
```

cron relit `/etc/cron.d/` tout seul : pas de redémarrage nécessaire.

### 8.4 Le lendemain matin

```bash
tail -n 5 /var/log/causeo-backup.log
```

Attendu : des lignes datées d'environ `T03:00` se terminant par `Sauvegarde terminée.`

```bash
cat /var/backups/causeo/last-success
```

Attendu : la date de la nuit (`AAAAMMJJT0300…Z`).

```bash
journalctl -u cron --since yesterday | grep causeo-backup
```

Attendu : une ligne `(root) CMD (/usr/local/sbin/causeo-backup …)` vers 3 h.

Et un nouveau fichier dans R2 (étape 7.2).

---

## Étape 9 (facultatif, recommandé) — Surveillance healthchecks.io

L'e-mail d'échec ne part pas si la sauvegarde **ne tourne plus du tout** (cron arrêté,
serveur éteint) ; healthchecks.io le détecte.

### 9.1 Créer le check (navigateur)

1. Créer un compte gratuit sur [healthchecks.io](https://healthchecks.io).
2. *Add Check* : nom `causeo-backup` ; *Schedule* : *Simple*, **Period 1 day**,
   **Grace Time 2 hours** ; enregistrer.
3. *Integrations* : vérifier que l'e-mail du compte est activé (c'est le cas par défaut).
4. Copier l'URL de ping du check : `https://hc-ping.com/<UUID>` (`<URL_HEALTHCHECK>` ci-dessous).

### 9.2 La renseigner sur le VPS

```bash
nano /etc/causeo-backup.env
```

Remplacer la ligne `HEALTHCHECK_URL=` par :

```bash
HEALTHCHECK_URL=<URL_HEALTHCHECK>
```

Enregistrer (Ctrl+O, Entrée), quitter (Ctrl+X). Les droits ne changent pas, mais vérifier :

```bash
stat -c '%U:%G %a' /etc/causeo-backup.env
```

Attendu : `root:root 600`.

### 9.3 Tester

Signal de réussite (sans lancer de sauvegarde) :

```bash
bash -c '. /etc/causeo-backup.env && curl -fsS -m 10 "$HEALTHCHECK_URL"'
```

Attendu : `OK`, et le check passe au vert (*up*) sur healthchecks.io.

Signal d'échec : refaire les quatre commandes de l'étape 7.3. Attendu : le check passe au
rouge (*down*) et healthchecks.io envoie un e-mail, en plus de l'e-mail Causeo.

Remettre le check au vert :

```bash
bash -c '. /etc/causeo-backup.env && curl -fsS -m 10 "$HEALTHCHECK_URL"'
```

Sinon (`curl: (22)` ou `(6)`) : URL mal collée ; refaire 9.2.

---

## Étape 10 — Test de restauration sur causeo-dev, depuis Windows avec WSL

À refaire **chaque mois**. Tout se passe dans le terminal **Ubuntu (WSL)**, sauf le
nettoyage final (PowerShell). Le disque `C:` de Windows y est visible sous `/mnt/c`.

### 10.1 Ouvrir WSL

Menu Démarrer → *Ubuntu* (ou `wsl` dans PowerShell). Attendu : une invite
`<utilisateur>@<pc>:~$`.

### 10.2 Installer les outils (la première fois seulement)

```bash
sudo apt-get update
```

```bash
sudo apt-get install -y age postgresql-common
```

```bash
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
```

```bash
sudo apt-get install -y postgresql-client-17
```

(même version que sur le VPS, étape 3)

```bash
age --version
```

Attendu : `v1.x.x`.

```bash
pg_restore --version
```

Attendu : `pg_restore (PostgreSQL) 17.x`. Si une version plus ancienne s'affiche :

```bash
export PATH=/usr/lib/postgresql/17/bin:$PATH
```

### 10.3 Mettre la clé privée dans un fichier (temporaire)

```bash
nano ~/causeo-backup.key
```

Coller **tout** le contenu de la clé privée depuis le gestionnaire de mots de passe :

```
# created: <DATE>
# public key: age1920mc3fz9qvcvfc47q9uptwuavkv86q7w2lcjqhfa3vw56wvn3as3dysll
<CLE_PRIVEE_AGE-SECRET-KEY-1…>
```

(au minimum la ligne `AGE-SECRET-KEY-1…`). Enregistrer (Ctrl+O, Entrée), quitter (Ctrl+X).

```bash
chmod 600 ~/causeo-backup.key
```

```bash
age-keygen -y ~/causeo-backup.key
```

Attendu : exactement `age1920mc3fz9qvcvfc47q9uptwuavkv86q7w2lcjqhfa3vw56wvn3as3dysll`.
Sinon : la clé collée n'est pas la bonne, ou est tronquée.

### 10.4 Télécharger la sauvegarde la plus récente

Dans Cloudflare (navigateur Windows) : R2 → `causeo-backups` → *Objects*, télécharger le
`causeo-….dump.age` le plus récent **et** son `.dump.age.sha256`, dans *Téléchargements*.

Dans WSL, les copier dans votre dossier Linux :

```bash
cp /mnt/c/Users/HP/Downloads/causeo-*.dump.age* ~/
```

```bash
ls -l ~/causeo-*
```

Attendu : les deux fichiers. Noter le nom complet du `.dump.age` : `<FICHIER_SAUVEGARDE>`
(par exemple `causeo-20261026T030001Z.dump.age`).

### 10.5 Préparer la connexion à causeo-dev

Hôte et utilisateur du Session pooler de **causeo-dev** : ceux du `.env` de votre PC (ou
Supabase → projet causeo-dev → *Connect* → *Session pooler*).

```bash
grep -E '^DB_(HOST|PORT|USERNAME)=' /mnt/c/Users/HP/Desktop/causeo-api/.env
```

Attendu : un hôte en `pooler.supabase.com`, `DB_PORT=5432`, un utilisateur
`postgres.<id-causeo-dev>` **différent de** `postgres.ppqgsdljypddwfvbxbxz` (production).
Ces valeurs sont `<HOTE_POOLER_DEV>` et `<ID_CAUSEO_DEV>` ci-dessous.

Mot de passe de causeo-dev, en saisie masquée (rien ne s'affiche, c'est normal) : coller le
mot de passe depuis le gestionnaire puis Entrée.

```bash
read -rs PGPASSWORD; export PGPASSWORD
```

Adresse de la base, **sans** le mot de passe (il est pris dans `PGPASSWORD`) :

```bash
export TARGET_DATABASE_URL='postgresql://postgres.<ID_CAUSEO_DEV>@<HOTE_POOLER_DEV>:5432/postgres?sslmode=require'
```

```bash
export AGE_IDENTITY=~/causeo-backup.key
```

Tester la connexion :

```bash
psql "$TARGET_DATABASE_URL" -XAtc 'select current_user'
```

Attendu : `postgres.<ID_CAUSEO_DEV>`. Sinon : `password authentication failed` → refaire le
`read -rs` ; `could not translate host name` → hôte mal recopié ; délai dépassé → le projet
causeo-dev est peut-être en pause (*Restore project* dans Supabase).

### 10.6 Restaurer

```bash
cd /mnt/c/Users/HP/Desktop/causeo-api
```

```bash
bash deploy/restore.sh ~/<FICHIER_SAUVEGARDE>
```

Attendu, dans l'ordre :

1. `==> Vérification de l'empreinte` puis `…: OK` ;
2. la question `Restaurer … dans postgres.<ID_CAUSEO_DEV>@<HOTE_POOLER_DEV> ?` : vérifier
   que c'est bien causeo-dev, puis taper `restaurer` et Entrée ;
3. `Déchiffrement`, `Extension vector`, `Suppression des tables existantes de la cible`,
   `Restauration` ;
4. un tableau de comptages (businesses, conversations, messages, orders, migrations), non
   nuls et cohérents avec la production ;
5. `Dernier message : <date>` : la date doit être celle de la nuit de la sauvegarde ;
6. `Restauration terminée.` et l'avertissement « VRAIES données clients ».

Sinon :

| Message | Que faire |
|---|---|
| `Empreinte différente` | retélécharger les deux fichiers (10.4) |
| `La cible est la base de PRODUCTION` | `TARGET_DATABASE_URL` vise la production : la corriger (10.5). **Ne jamais** ajouter `ALLOW_PRODUCTION_RESTORE` pour un test |
| `no identity matched any of the recipients` | mauvaise clé privée (10.3) |
| `Outil absent` | refaire 10.2 |
| `$'\r': command not found` | fins de ligne Windows dans le dépôt : dans PowerShell, `git checkout -- deploy/restore.sh` |
| erreur pendant `Restauration` | rien n'est appliqué (transaction unique) ; noter le message, vérifier la version de `pg_restore` |

### 10.7 Nettoyer causeo-dev et les fichiers

causeo-dev contient maintenant de **vraies données clients**. Ne pas lancer de worker ni de
planificateur dessus (envois de messages ou d'e-mails à de vrais clients).

**Dans WSL**, effacer la clé privée, les sauvegardes téléchargées et le mot de passe :

```bash
shred -u ~/causeo-backup.key
```

```bash
rm ~/causeo-*.dump.age*
```

```bash
rm /mnt/c/Users/HP/Downloads/causeo-*.dump.age*
```

```bash
unset PGPASSWORD TARGET_DATABASE_URL AGE_IDENTITY
```

```bash
ls ~/causeo-* /mnt/c/Users/HP/Downloads/causeo-*
```

Attendu : `No such file or directory` pour les deux.

**Dans PowerShell**, dans le dossier du projet, remettre les données de démonstration :

```powershell
cd C:\Users\HP\Desktop\causeo-api
```

```powershell
php artisan causeo:dev-setup --fresh
```

Confirmer quand la commande le demande. Attendu : la commande se termine sans erreur
(elle refuse de s'exécuter si le `.env` vise la production). Vérification :

```powershell
php artisan tinker --execute="echo App\Models\Business::count();"
```

Attendu : le petit nombre de businesses de démonstration, pas celui de la production.

---

## Récapitulatif des fichiers et droits

| Fichier | Propriétaire | Droits | Contenu |
|---|---|---|---|
| `/usr/local/sbin/causeo-backup` | root | 755 | script (copie de `deploy/backup.sh`) |
| `/etc/causeo-backup.env` | root:root | **600** | réglages, dont la clé R2 (secret) |
| `/etc/cron.d/causeo-backup` | root | 644 | tâche de 3 h |
| `/var/backups/causeo/` | root | 700 | sauvegardes chiffrées, 14 jours |
| `/var/log/causeo-backup.log` | root | 644 | journal |
| `~/causeo-backup.key` (WSL) | vous | 600 | clé privée, **supprimée** après le test |

Après une modification de `deploy/backup.sh` dans le dépôt, la réinstaller :
`install -m 755 /var/www/causeo-api/deploy/backup.sh /usr/local/sbin/causeo-backup`
(un passage de `setup.sh` le fait aussi).
