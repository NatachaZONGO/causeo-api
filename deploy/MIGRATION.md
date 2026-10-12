# Migration de Render et Vercel vers le VPS Hostinger

Procédure pas à pas, de l'achat à la bascule, avec le retour arrière. Elle complète
`DOMAINE.md` (à la racine), qui liste tout ce qui dépend du domaine.

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

**Dépôt du dashboard (privé).** Au premier passage, `setup.sh` affiche une clé publique
`ssh-ed25519 …`. L'ajouter dans GitHub > `causeo-dashboard` > Settings > **Deploy keys**,
en lecture seule, puis relancer `setup.sh`.

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

## 11. Après la bascule

- **48 heures de surveillance** : logs Laravel, `sudo supervisorctl status`, réponses du
  bot, e-mails reçus.
- **Après une à deux semaines sans trafic sur les anciennes adresses** (DOMAINE.md,
  étape 10) :
  - retirer les anciennes adresses chez Google et Meta ;
  - vider `CORS_ALLOWED_ORIGINS` dans le `.env` du VPS, puis lancer `deploy.sh api` ;
  - suspendre puis supprimer le service Render et le projet Vercel ;
  - remonter le TTL DNS à 3600.

## 12. Retour arrière

Possible à tout moment tant que Render n'est pas supprimé : les données sont sur
Supabase, rien n'est perdu. Revenir dans l'ordre inverse :

1. **Webhook Meta** : remettre `https://causeo-api.onrender.com/api/webhook/whatsapp`.
2. **Planificateur** : VPS `sudo causeo-scheduler off`, puis Render `RUN_SCHEDULER=true`.
3. **Render** : `FRONTEND_URL=https://causeo-dashboard.vercel.app`.
4. **Dashboard** : remettre l'ancien `apiUrl` dans `environment.prod.ts` et retirer la
   redirection `vercel.json`, puis pousser. Vercel redevient le dashboard principal.
5. Laisser le VPS allumé pour comprendre le problème. Les nouvelles adresses déclarées
   chez Google et Meta peuvent rester.

**Version de code défectueuse** (sur le VPS seulement) : corriger ou annuler le commit
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
| Déployer | `sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh [api\|dashboard]` |
| État du worker | `sudo supervisorctl status` |
| Planificateur | `sudo causeo-scheduler on\|off\|status` |
| Logs Laravel | `tail -f /var/www/causeo-api/storage/logs/laravel-$(date +%F).log` |
| Logs nginx | `/var/log/nginx/causeo-api.error.log`, `causeo-dashboard.error.log` |
| Logs PHP | `/var/log/php8.2-fpm-causeo.log` |
| Après modification de `deploy/` | `deploy.sh api`, puis `DOMAIN=DOMAINE CERTBOT_EMAIL=… bash /var/www/causeo-api/deploy/setup.sh` |
