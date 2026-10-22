# Migration de Render et Vercel vers le VPS Hostinger

> **Migration terminée.** Bascule faite le 15 octobre 2026 (`deploy/BASCULE.md`) ;
> `DOMAINE` est `getcauseo.com`. Render est supprimé, Vercel ne fait plus que rediriger
> `causeo-dashboard.vercel.app` vers `https://getcauseo.com`. Les étapes 1 à 10 sont
> gardées comme archive (et pour réinstaller un serveur : étapes 2 à 6 bis) ; le retour
> vers Render n'est plus possible. Ce qui reste utile au quotidien est dans
> **Exploitation courante**, à la fin.

Procédure pas à pas, de l'achat à la bascule. Elle complète `DOMAINE.md` (à la racine),
qui liste tout ce qui dépend du domaine.

Dans ce document, `DOMAINE` est le nouveau domaine (par exemple `causeo.bf`) et `IP`
l'adresse IPv4 du VPS.

| | Avant | Après |
|---|---|---|
| API | `https://causeo-api.onrender.com` | `https://api.DOMAINE` |
| Dashboard | `https://causeo-dashboard.vercel.app` | `https://DOMAINE` (`www` y renvoie) |
| Pages légales | `natachazongo.github.io` | `https://DOMAINE/privacy/` |
| Base de données, fichiers | Supabase | Supabase (inchangé) |

> **Règle d'or : la même `APP_KEY` que sur Render.** Elle déchiffre les tokens WhatsApp
> des businesses. Avec une autre clé, plus aucun message ne part.

**Principe.** La base et les fichiers restent sur Supabase. L'API de Render et celle du
VPS peuvent donc tourner **en même temps** sur les mêmes données. On prépare et on teste
le VPS sans rien couper, puis on bascule service par service. Les anciennes adresses
restent actives jusqu'à la fin, ce qui permet un retour arrière à tout moment.

**Calendrier proposé**

- **12 octobre** : étapes 1 à 6 (achat, DNS, installation, premier déploiement).
- **13 octobre** : étapes 7 à 9 (tests, préparation des services, dashboard).
- **14 octobre** : étape 10 (bascule), puis surveillance.

---

## 1. Achat

1. **Domaine** : l'acheter chez Hostinger (hPanel > Domaines), pour que le DNS soit au
   même endroit que le VPS.
2. **VPS** : au moins 4 Go de RAM (KVM 1 ou plus) ; le build du dashboard et les
   12 processus PHP en ont besoin. Système : **Ubuntu 24.04**, en version simple, sans
   panneau de contrôle.
3. **Clé SSH** : à la création du VPS, ajouter votre clé publique. Pour en créer une sous
   Windows, dans PowerShell :
   ```powershell
   ssh-keygen -t ed25519 -C "votre@email"
   Get-Content $HOME\.ssh\id_ed25519.pub   # à coller dans hPanel
   ```
   Sans clé, `setup.sh` laisse la connexion root par mot de passe active, pour ne pas
   vous enfermer dehors.
4. Noter l'**IP** du VPS (hPanel > VPS > Vue d'ensemble), puis vérifier l'accès :
   `ssh root@IP`.
5. Optionnel mais conseillé : un **instantané** du VPS (hPanel > VPS > Instantanés) après
   l'étape 6, pour revenir à une installation saine.

## 2. DNS

hPanel > Domaines > DOMAINE > **DNS / Serveurs de noms** :

1. **Supprimer** les enregistrements de parking créés par Hostinger : `A @`, `CNAME www`
   et les éventuels `AAAA @` / `AAAA www`. Un `AAAA` restant vers le parking fait échouer
   les certificats.
2. **Ajouter**, avec un TTL de 300 :

   | Type | Nom | Valeur |
   |---|---|---|
   | A | `@` | IP |
   | A | `www` | IP |
   | A | `api` | IP |

   Si le VPS a une IPv6 (hPanel > VPS), vous pouvez ajouter les mêmes en `AAAA`. Sinon,
   n'en mettez aucun.
3. Ne pas toucher aux enregistrements `MX` ni `TXT` existants. Ceux de Brevo s'ajoutent
   à l'étape 8.
4. Vérifier, depuis votre poste (quelques minutes à quelques heures) :
   ```powershell
   nslookup DOMAINE
   nslookup www.DOMAINE
   nslookup api.DOMAINE
   ```
   Les trois doivent renvoyer l'IP du VPS.

## 3. Installation du serveur

En root sur le VPS :

```bash
apt-get update && apt-get install -y git
git clone https://github.com/NatachaZONGO/causeo-api.git /root/causeo-api
DOMAIN=DOMAINE CERTBOT_EMAIL=votre@email bash /root/causeo-api/deploy/setup.sh
```

`setup.sh` est idempotent : on peut le relancer autant de fois que nécessaire. Pour les
relances, utiliser la copie de `/var/www/causeo-api`, tenue à jour par `deploy.sh`, et non
celle de `/root` :

```bash
DOMAIN=DOMAINE CERTBOT_EMAIL=votre@email bash /var/www/causeo-api/deploy/setup.sh
```

Il :

- installe nginx, PHP 8.2 (php-fpm et les extensions pgsql, mbstring, xml, curl, zip,
  bcmath, intl, pcntl), Composer, Node 22, Supervisor, Certbot ;
- crée l'utilisateur `deploy`, qui reçoit votre clé SSH ;
- clone l'API, le dashboard et les pages légales dans `/var/www/` ;
- installe le pool php-fpm, les sites nginx et le worker de file ;
- ouvre seulement les ports 22, 80 et 443 (UFW) ;
- interdit la connexion root par mot de passe.

Le **planificateur n'est pas activé** : il le sera à la bascule (étape 10).

**Dépôt du dashboard (privé).** Au premier passage, `setup.sh` ne peut pas le cloner :
il affiche la clé publique de déploiement (`ssh-ed25519 … (causeo-dashboard, lecture
seule)`). L'ajouter comme **deploy key** en lecture seule (étape 6 bis, B), puis relancer
`setup.sh`.

## 4. Certificats HTTPS

`setup.sh` les demande à Let's Encrypt dès que `CERTBOT_EMAIL` est fourni et que le DNS
pointe vers le VPS. Si le DNS n'était pas encore propagé, relancer simplement `setup.sh`
plus tard : il passe alors chaque site en HTTPS.

```bash
certbot certificates           # api.DOMAINE et DOMAINE (+ www) doivent apparaître
certbot renew --dry-run        # le renouvellement automatique fonctionne
```

## 5. Variables d'environnement

```bash
cd /var/www/causeo-api
sudo -u deploy cp deploy/.env.production.example .env
sudo -u deploy chmod 600 .env
sudo -u deploy nano .env
```

- Recopier **chaque valeur** depuis Render > `causeo-api` > **Environment**.
- **`APP_KEY` : exactement la même** (avec le préfixe `base64:`).
- Changer seulement les lignes marquées `[VPS]` : `APP_URL`, `FRONTEND_URL`,
  `CORS_ALLOWED_ORIGINS`, `GOOGLE_REDIRECT_URI`, `LOG_CHANNEL`.
- Garder pour l'instant le `MAIL_FROM_ADDRESS` de Render. Il change à l'étape 10, une
  fois le domaine authentifié chez Brevo.
- Si Supabase limite l'accès par adresse IP (Database > Network restrictions), y ajouter
  l'IP du VPS.

## 6. Premier déploiement

```bash
sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api
```

C'est la même séquence que l'entrypoint de Render :

1. mise à jour du code et des dépendances ;
2. `migrate --force` (rien de nouveau en principe, la base est partagée) ;
3. seeders ;
4. caches ;
5. rechargement de php-fpm et redémarrage du worker.

**Vérifier tout de suite la clé :**

```bash
cd /var/www/causeo-api
sudo -u deploy php artisan tinker --execute="echo App\Models\Business::whereNotNull('whatsapp_token')->get()->every(fn (\$b) => strlen(\$b->whatsapp_token) > 0) ? 'APP_KEY OK' : 'PROBLEME';"
```

`APP_KEY OK` : les tokens se déchiffrent. Une erreur `The MAC is invalid` signifie que la
clé n'est pas celle de Render : la corriger avant d'aller plus loin.

## 6 bis. Déploiement automatique depuis GitHub

Le workflow `.github/workflows/deploy.yml` du dépôt `causeo-api` fonctionne ainsi, à
chaque push sur `main` :

1. il lance la suite de tests (PHP 8.2, SQLite en mémoire) ;
2. **seulement si elle passe**, il se connecte au VPS en SSH en tant que `deploy` et lance
   `deploy.sh api` sur **le commit testé** ;
3. un seul déploiement tourne à la fois : côté GitHub par le groupe de concurrence, et
   côté serveur par un verrou dans `deploy.sh`, qui couvre aussi les déploiements
   manuels.

Aucune information sensible n'est dans le dépôt. Il y a deux sortes de clés, à ne pas
confondre :

| Clé | Sert à | Clé privée | Clé publique |
|---|---|---|---|
| **Clé de GitHub Actions** | GitHub → VPS (lancer `deploy.sh`) | secret GitHub `VPS_SSH_KEY` | `~deploy/.ssh/authorized_keys` sur le VPS, avec une commande forcée |
| **Deploy key** | VPS → GitHub (cloner le dépôt privé du dashboard) | `~deploy/.ssh/causeo-dashboard_deploy` sur le VPS | GitHub > `causeo-dashboard` > Deploy keys, en lecture seule |

### A. Créer la clé SSH de GitHub Actions (sur votre poste)

Dans PowerShell :

```powershell
ssh-keygen -t ed25519 -C "github-actions@causeo" -N '""' -f $HOME\.ssh\causeo_github_actions
```

Cette commande crée deux fichiers :

- `causeo_github_actions`, la clé **privée**, qui ira **seulement** dans le secret
  GitHub ;
- `causeo_github_actions.pub`, la clé publique, qui ira sur le VPS.

Elle n'a pas de phrase de passe, car GitHub Actions doit pouvoir l'utiliser seul. Sa
sécurité vient de la commande forcée (point B ci-dessous).

### B. Installer les clés sur le VPS

1. **Clé de GitHub Actions.** Afficher la clé publique :
   ```powershell
   Get-Content $HOME\.ssh\causeo_github_actions.pub
   ```
   Puis, en root sur le VPS, relancer `setup.sh` en la passant dans `CI_PUBLIC_KEY` :
   ```bash
   DOMAIN=DOMAINE CERTBOT_EMAIL=votre@email \
   CI_PUBLIC_KEY="ssh-ed25519 AAAA… github-actions@causeo" \
   bash /var/www/causeo-api/deploy/setup.sh
   ```
   `setup.sh` l'ajoute à `/home/deploy/.ssh/authorized_keys` avec
   `restrict,command="/var/www/causeo-api/deploy/deploy.sh"`. Avec cette clé, on ne
   peut **que** lancer `deploy.sh`, qui n'accepte que `api|dashboard|all` et un numéro
   de commit : pas de terminal, pas d'autre commande, pas de redirection de port.
   À la fin, `setup.sh` affiche les valeurs de `VPS_HOST` et `VPS_HOST_KEY`.
2. **Deploy key du dashboard.** Sur le VPS :
   ```bash
   cat /home/deploy/.ssh/causeo-dashboard_deploy.pub
   ```
   Puis, dans GitHub :
   1. ouvrir `causeo-dashboard` > **Settings** > **Deploy keys** > **Add deploy key** ;
   2. Title : `VPS Hostinger (lecture seule)` ; Key : coller la ligne `ssh-ed25519 …` ;
   3. laisser **Allow write access décoché**, puis cliquer sur **Add key**.

   Vérifier depuis le VPS :
   ```bash
   sudo -u deploy ssh -T git@github-causeo-dashboard
   # Hi NatachaZONGO/causeo-dashboard! You've successfully authenticated, but GitHub does not provide shell access.
   ```
   Relancer ensuite `setup.sh`, qui clone le dashboard.

   Si le dépôt `causeo-api` devient privé un jour, il suffit de relancer `setup.sh` avec
   `API_REPO=git@github-causeo-api:NatachaZONGO/causeo-api.git`. Une deploy key est créée
   pour lui (`causeo-api_deploy.pub`), à ajouter de la même façon dans `causeo-api`.
   GitHub refuse la même clé sur deux dépôts : il en faut bien une par dépôt.
3. **Vérifier la commande forcée** depuis votre poste. Toute autre commande doit être
   refusée :
   ```powershell
   ssh -i $HOME\.ssh\causeo_github_actions deploy@IP whoami
   # Usage : deploy.sh [all|api|dashboard] [commit]
   ```

### C. Créer les secrets GitHub

GitHub > `causeo-api` > **Settings** > **Secrets and variables** > **Actions** > onglet
**Secrets** > **New repository secret**, une fois par ligne :

| Nom | Valeur |
|---|---|
| `VPS_HOST` | l'IP du VPS (affichée par `setup.sh`). Préférer l'IP au nom `api.DOMAINE`, qui pourrait un jour passer derrière un proxy. |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | **tout** le contenu de la clé privée, lignes `-----BEGIN…` et `-----END…` comprises. Pour la copier : `Get-Content $HOME\.ssh\causeo_github_actions -Raw \| Set-Clipboard` |
| `VPS_HOST_KEY` | la clé publique du serveur affichée par `setup.sh` (`ssh-ed25519 AAAA…`), c'est-à-dire `/etc/ssh/ssh_host_ed25519_key.pub` sans le commentaire. Le workflow vérifie qu'il parle bien au VPS, au lieu de faire confiance à la première connexion. |

Une fois le secret enregistré, **supprimer la clé privée du poste**, car seul GitHub en a
besoin. En cas de perte, on en crée une nouvelle (point A) et on remplace le secret et
`CI_PUBLIC_KEY`.

```powershell
Remove-Item $HOME\.ssh\causeo_github_actions
```

### D. Activer le déploiement

Tant que la variable suivante n'existe pas, le workflow ne lance que les tests. C'est
voulu : on ne déploie pas automatiquement avant que le VPS soit prêt (`.env` en place,
étape 6 réussie).

GitHub > `causeo-api` > **Settings** > **Secrets and variables** > **Actions** > onglet
**Variables** > **New repository variable** : `DEPLOY_ENABLED` = `true`.

Pour couper le déploiement automatique, passer la variable à `false`.

### E. Vérifier

- GitHub > `causeo-api` > **Actions** > **Tests et déploiement** > **Run workflow** (cible
  `api`). Le job « Déploiement sur le VPS » affiche la sortie de `deploy.sh`.
- Un push sur `main` dont les tests échouent : le job de déploiement est **sauté**.

**Dashboard.** Un push sur `causeo-dashboard` ne déclenche pas ce workflow. On peut :

- lancer **Run workflow** avec la cible `dashboard` ;
- ou ajouter dans `causeo-dashboard` un workflow qui utilise les mêmes secrets et
  envoie `dashboard` à la place de `api` (dernière étape de `deploy.yml`).

## 7. Tests de l'API (sans impact sur la production)

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.DOMAINE/up                    # 200
curl -s https://api.DOMAINE/api/v1/plans | head -c 200; echo                         # liste des formules
curl -s "https://api.DOMAINE/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=<WHATSAPP_VERIFY_TOKEN>&hub.challenge=ok123"; echo  # ok123
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.DOMAINE/api/webhook/whatsapp-express   # 404 (mode Express coupé)
curl -si -X OPTIONS https://api.DOMAINE/api/v1/plans -H 'Origin: https://DOMAINE' -H 'Access-Control-Request-Method: GET' | grep -i '^access-control-allow-origin'   # https://DOMAINE
sudo supervisorctl status                                                            # causeo-worker RUNNING
```

**E-mail depuis le VPS**, pour vérifier que Hostinger laisse passer le port 2525 :

```bash
cd /var/www/causeo-api
sudo -u deploy php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Test depuis le VPS', fn (\$m) => \$m->to('votre@email')->subject('Test VPS Causeo'));"
```

**Logs** : `storage/logs/laravel-AAAA-MM-JJ.log` ; nginx dans
`/var/log/nginx/causeo-api.*.log` ; worker dans `/var/log/supervisor/causeo-worker.log`.

## 8. Préparer Google, Meta et Brevo (ajouts seulement)

Ajouter les nouvelles adresses **sans retirer les anciennes**. Le détail est dans
`DOMAINE.md`, sections 3 à 5.

- **Google Cloud** (client OAuth) :
  - URI de redirection : `https://api.DOMAINE/auth/google/callback` ;
  - origines JavaScript : `https://DOMAINE` et `https://api.DOMAINE`.
- **Meta** (app Causeo) :
  - Domaines de l'app : `DOMAINE` ;
  - Facebook Login for Business : URI de redirection `https://DOMAINE/` et domaine
    autorisé pour le SDK JavaScript `https://DOMAINE`.
  - **Ne pas changer le webhook maintenant.**
- **Brevo** : authentifier `DOMAINE`, en ajoutant le code Brevo, DKIM, SPF et DMARC dans
  le DNS Hostinger. Si un SPF existe déjà, fusionner les `include:` dans un seul
  enregistrement. Attendre l'état « authentifié ».
- **Pages légales** : vérifier que `https://DOMAINE/privacy/` et
  `https://DOMAINE/privacy/data-deletion.html` s'affichent.

## 9. Dashboard

1. Dans le dépôt `causeo-dashboard`, remplacer `__DOMAINE__` dans
   `src/environments/environment.vps.ts` :
   ```ts
   apiUrl: 'https://api.DOMAINE/api/v1',
   ```
   Committer et pousser sur `main`. `deploy.sh` construit le dashboard avec
   `npm run build:vps`, qui refuse de construire tant que `__DOMAINE__` est présent.

   `environment.prod.ts` (build de Vercel) ne change pas : l'ancien dashboard continue
   d'appeler Render jusqu'à sa redirection (étape 10.6). Les deux API partagent la base.
2. Sur le VPS :
   ```bash
   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh dashboard
   ```
   Ou automatiquement : le workflow du dépôt `causeo-dashboard` construit sur GitHub et copie
   sur le VPS à chaque push (secrets et clé `CI_DASHBOARD_PUBLIC_KEY` : `DEPLOY.md` de ce dépôt).
   Dans les deux cas, chaque version va dans `/var/www/causeo-dashboard/releases/<id>/`, puis le
   lien `current` (servi par nginx) bascule d'un coup ; `deploy/dashboard-release.sh rollback`
   revient à la précédente.
3. Tester sur `https://DOMAINE` :
   - connexion par e-mail et par Google ;
   - une route profonde rechargée (F5), par exemple `/dashboard/billing` ;
   - envoi d'un média de 10 Mo et d'un retour avec capture ;
   - « mot de passe oublié » : le lien de l'e-mail pointe encore vers Vercel tant que
     `FRONTEND_URL` de Render n'a pas changé, ce qui est normal ;
   - si possible, l'Embedded Signup avec un business de test.

## 10. Bascule (14 octobre)

Dans cet ordre, en vérifiant chaque point :

1. **Render > Environment** :
   - `FRONTEND_URL=https://DOMAINE` ;
   - `CORS_ALLOWED_ORIGINS=https://causeo-dashboard.vercel.app,https://DOMAINE`.

   Les e-mails envoyés par Render pointent alors vers le nouveau dashboard.
2. **Webhook WhatsApp** (Meta > WhatsApp > Configuration) :
   - URL de rappel : `https://api.DOMAINE/api/webhook/whatsapp`, avec le même jeton de
     vérification ;
   - envoyer un message de test à un numéro de business et vérifier la réponse ;
   - `tail -f storage/logs/laravel-*.log` sur le VPS.
3. **Planificateur**, sans période où il tourne en double :
   - Render : `RUN_SCHEDULER=false` (Render redéploie) ;
   - VPS : `sudo causeo-scheduler on`, puis `sudo causeo-scheduler status` ;
   - `cd /var/www/causeo-api && sudo -u deploy php artisan schedule:list` montre les
     prochains passages.
4. **Meta**, URL légales :
   - politique de confidentialité : `https://DOMAINE/privacy/` ;
   - suppression des données : `https://DOMAINE/privacy/data-deletion.html`.
5. **Brevo**, une fois le domaine authentifié :
   - créer l'expéditeur `contact@DOMAINE` ;
   - mettre `MAIL_FROM_ADDRESS` à jour dans le `.env` du VPS, puis lancer
     `deploy.sh api` ;
   - tester un « mot de passe oublié » et vérifier SPF, DKIM et DMARC à `PASS` (Gmail >
     Afficher l'original).
6. **Ancien dashboard** : rediriger Vercel vers `https://DOMAINE` en conservant le chemin
   et les paramètres. Par exemple, un `vercel.json` dans le dépôt du dashboard, lu
   seulement par Vercel :
   ```json
   { "redirects": [{ "source": "/:path*", "destination": "https://DOMAINE/:path*", "permanent": false }] }
   ```
   Vérifier qu'un lien `…vercel.app/reset-password?token=…&email=…` arrive bien sur
   `https://DOMAINE/reset-password?token=…&email=…`.

Le worker de file peut rester actif sur Render : les deux workers se partagent la même
file sans traiter deux fois un job. À couper avec `RUN_QUEUE_WORKER=false` avant
l'arrêt de Render.

## 11. Après la bascule (terminé)

- [x] Surveillance après la bascule : logs Laravel, `sudo supervisorctl status`, réponses
  du bot, e-mails reçus.
- [x] Service Render supprimé ; Vercel garde seulement la redirection vers
  `https://getcauseo.com`.
- [x] Dockerfile, `docker-entrypoint.sh` et `.dockerignore` (propres à Render) retirés du
  dépôt.
- [ ] `.env` du VPS : retirer `https://causeo-dashboard.vercel.app` de
  `CORS_ALLOWED_ORIGINS` (l'ancien dashboard redirige et n'appelle plus l'API), puis
  `deploy.sh api` :
  ```bash
  sudo -u deploy sed -i '/^CORS_ALLOWED_ORIGINS=/{s#https://causeo-dashboard\.vercel\.app##;s#,,#,#;s#=,#=#;s#,$##}' /var/www/causeo-api/.env
  grep '^CORS_ALLOWED_ORIGINS' /var/www/causeo-api/.env
  sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api
  ```
- [ ] Google Cloud : retirer l'URI de redirection `https://causeo-api.onrender.com/auth/google/callback`
  et les origines de Render et de Vercel, s'ils y sont encore.
- [ ] Meta : retirer `causeo-dashboard.vercel.app` (et `natachazongo.github.io`) des domaines
  de l'app, des URI de redirection et des domaines du SDK JavaScript, s'ils y sont encore.
- [ ] DNS : TTL des enregistrements `@`, `www` et `api` remonté à 3600 s.

## 12. Retour arrière

Render étant supprimé, il n'y a plus d'ancienne infrastructure vers laquelle revenir. Les
données restent sur Supabase.

**Version de code défectueuse** : corriger ou annuler le commit
sur `main` (`git revert`), puis relancer `deploy.sh`. Pour une urgence, redéployer un
commit précis :

```bash
sudo -u deploy git -C /var/www/causeo-api checkout <commit>
```

Puis, depuis `/var/www/causeo-api`, relancer `composer install --no-dev` et
`php artisan config:cache`, et recharger php-fpm. Le prochain `deploy.sh` revient sur
`main`.

## Exploitation courante

| Action | Commande |
|---|---|
| Déployer | automatique à chaque push sur `main` (étape 6 bis), ou `sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh [api\|dashboard]` |
| Couper le déploiement automatique | variable GitHub `DEPLOY_ENABLED=false` |
| État du worker | `sudo supervisorctl status` |
| Planificateur | `sudo causeo-scheduler on\|off\|status` |
| Logs Laravel | `tail -f /var/www/causeo-api/storage/logs/laravel-$(date +%F).log` |
| Logs nginx | `/var/log/nginx/causeo-api.error.log`, `causeo-dashboard.error.log` |
| Logs PHP | `/var/log/php8.2-fpm-causeo.log` |
| Après modification de `deploy/` | `deploy.sh api`, puis `DOMAIN=DOMAINE CERTBOT_EMAIL=… bash /var/www/causeo-api/deploy/setup.sh` |
