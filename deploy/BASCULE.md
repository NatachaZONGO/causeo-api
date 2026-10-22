# Bascule de la production vers le VPS (15 octobre 2026)

> **Archive : bascule faite, migration terminée.** Render est supprimé et Vercel ne fait
> plus que rediriger vers `https://getcauseo.com` : les étapes ci-dessous qui touchent
> Render, ainsi que le retour arrière, ne s'appliquent plus. État actuel : `DOMAINE.md` ;
> dernières vérifications : `deploy/MIGRATION.md`, étape 11.

Feuille de route du jour J, dans l'ordre d'exécution. Elle prend la suite de
`deploy/MIGRATION.md` (étapes 1 à 9 déjà faites : VPS installé, `.env` en place, API et
dashboard déployés, HTTPS actif).

| | Avant | Après |
|---|---|---|
| API | `https://causeo-api.onrender.com` | `https://api.getcauseo.com` |
| Dashboard | `https://causeo-dashboard.vercel.app` | `https://getcauseo.com` |
| Base, fichiers | Supabase | Supabase (inchangé, partagé pendant la transition) |

Les commandes de ce document se lancent **en root sur le VPS** (`ssh root@179.198.213.202`),
sauf mention contraire.

**Vue d'ensemble**

| Quand | Étapes | Impact sur la production |
|---|---|---|
| Matin | 1. Vérifications, 2. Mises à jour d'Ubuntu | aucun (le VPS ne sert pas encore) |
| Matin | 3 A. Meta (ajouts), 4. Google, 5 A-B. DNS Brevo et Zoho | aucun : on **ajoute** des adresses |
| **Fenêtre de bascule**, entre **hh:10 et hh:55** | 3 B. Webhook, 6. Render + planificateur, 7. Vercel, 5 C. Expéditeur | la bascule elle-même |
| Juste après | 8. Tests | |
| En cas de problème | 9. Retour arrière | |
| J+2 / J+3 | 10. Nettoyage | |

Pourquoi entre hh:10 et hh:55 : les deux tâches planifiées tournent à hh:00 (`billing:sync`) et à
hh:05 (`billing:weekly-report`). Dans cette fenêtre, aucune ne tombe pendant la bascule.

---

## 1. Vérifications préalables (HTTPS, sans SSH)

Depuis n'importe quel poste (Git Bash, ou `curl.exe` dans PowerShell). Remplacer `<JETON>` par
la valeur de `WHATSAPP_VERIFY_TOKEN` (Render > Environment, ou sur le VPS :
`grep '^WHATSAPP_VERIFY_TOKEN=' /var/www/causeo-api/.env`).

```bash
A=https://api.getcauseo.com
curl -s -o /dev/null -w '%{http_code}\n' $A/up                                   # 200
curl -s $A/api/v1/plans | head -c 200; echo                                       # {"currency":"XOF",…"plans":[…
curl -s "$A/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=<JETON>&hub.challenge=test123"; echo   # test123
curl -s -o /dev/null -w '%{http_code}\n' "$A/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=x"  # 403
curl -s -o /dev/null -w '%{http_code}\n' -X POST $A/api/webhook/whatsapp-express  # 404 (mode Express coupé)
curl -si -X OPTIONS $A/api/v1/plans -H 'Origin: https://getcauseo.com' -H 'Access-Control-Request-Method: GET' | grep -i '^access-control-allow-origin'  # https://getcauseo.com
curl -s -o /dev/null -w '%{http_code}\n' https://getcauseo.com/auth/login          # 200
```

Le webhook doit renvoyer **exactement** le `hub.challenge` envoyé : c'est ce que Meta vérifie
quand on enregistre l'URL (étape 3 B).

**Résultats du 15 octobre** (relevés en préparant ce document) :

| Contrôle | Résultat |
|---|---|
| `/up` | 200 (0,46 s) |
| `/api/v1/plans` | 200, formules `free`, `starter`, `pro`, `business` |
| Webhook, bon jeton | 200, challenge renvoyé à l'identique |
| Webhook, mauvais jeton | 403 |
| Webhook Express | 404 |
| CORS | `getcauseo.com` et `causeo-dashboard.vercel.app` acceptés, autre origine refusée |
| `getcauseo.com`, `/auth/login`, `/confidentialite`, `/suppression-des-donnees`, `/privacy/`, `/privacy/data-deletion.html` | 200 |
| `www.getcauseo.com/x?y=1` | 301 vers `https://getcauseo.com/x?y=1` |
| Render `/up`, Vercel | 200 (toujours en service) |

---

## 2. Mises à jour de sécurité d'Ubuntu

À faire **le matin, avant la bascule** : un redémarrage ne gêne alors personne.

1. **Instantané** du VPS (hPanel > VPS > Instantanés > Créer). Il permet de revenir en arrière si
   une mise à jour casse quelque chose.
2. Mettre à jour :
   ```bash
   apt-get update
   apt list --upgradable                      # la liste (une trentaine de paquets)

   # Sans question : garde nos fichiers de configuration (nginx, php-fpm, sshd) et laisse
   # needrestart relancer seul les services concernés.
   NEEDRESTART_MODE=a DEBIAN_FRONTEND=noninteractive apt-get -y --with-new-pkgs \
       -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade

   apt list --upgradable                      # doit être vide (ou presque : « mises à jour échelonnées »)
   ```
3. Vérifier les services :
   ```bash
   systemctl is-active nginx php8.2-fpm supervisor cron ssh   # 5 fois « active »
   nginx -t                                                    # syntax is ok / test is successful
   supervisorctl status                                        # causeo-worker:causeo-worker_00 RUNNING
   curl -s -o /dev/null -w '%{http_code}\n' https://api.getcauseo.com/up   # 200
   ```
4. Redémarrer **seulement si** le système le demande (nouveau noyau, libc…) :
   ```bash
   [ -f /var/run/reboot-required ] && cat /var/run/reboot-required.pkgs || echo "Pas de redémarrage nécessaire"
   reboot
   ```
   Se reconnecter après une à deux minutes, puis :
   ```bash
   uname -r                                                    # le nouveau noyau
   systemctl is-active nginx php8.2-fpm supervisor cron ssh   # 5 fois « active »
   supervisorctl status                                        # causeo-worker … RUNNING
   causeo-scheduler status                                     # Planificateur : coupé (normal à ce stade)
   systemctl list-timers | grep certbot                        # renouvellement des certificats programmé
   curl -s -o /dev/null -w '%{http_code}\n' https://api.getcauseo.com/up   # 200
   curl -s -o /dev/null -w '%{http_code}\n' https://getcauseo.com/         # 200
   ```

Si un service n'est pas `active` : `systemctl status <service> --no-pager` et
`journalctl -u <service> -n 50 --no-pager` montrent pourquoi.

---

## 3. Meta (developers.facebook.com > Mes apps > Causeo)

### A. Le matin : ajouts, sans impact

Ajouter les nouvelles valeurs **à côté** des anciennes, sans rien retirer.

| Où | Valeur à ajouter |
|---|---|
| Paramètres de l'app > Général > **Domaines de l'app** | `getcauseo.com` |
| Paramètres de l'app > Général > **URL du site** (plateforme « Site web ») | `https://getcauseo.com` |
| Facebook Login for Business > Paramètres > **URI de redirection OAuth valides** | `https://getcauseo.com/` |
| Facebook Login for Business > Paramètres > **Domaines autorisés pour le SDK JavaScript** | `https://getcauseo.com` |
| Facebook Login for Business > Paramètres > **Connexion avec le SDK JavaScript** | `Oui` (déjà le cas pour Vercel) |
| Paramètres de l'app > Général > **URL de la politique de confidentialité** | `https://getcauseo.com/confidentialite` (voir l'avertissement ci-dessous) |
| Paramètres de l'app > Général > **URL des instructions de suppression des données** | `https://getcauseo.com/suppression-des-donnees` |
| Paramètres de l'app > Général > **URL des conditions de service** | `https://getcauseo.com/conditions-utilisation` |

`www.getcauseo.com` n'a pas à être déclaré : il renvoie vers `getcauseo.com`.
`facebookAppId` et `facebookConfigId` ne changent pas : c'est la même app.

> **Avertissement sur les pages légales.** `/confidentialite` et `/suppression-des-donnees` sont
> des pages du dashboard Angular : leur texte est ajouté par JavaScript. Sans JavaScript, ce que
> lit le robot de Meta, elles ne contiennent que **8 mots** (le titre générique de l'application),
> contre 350 à 430 mots pour les pages HTML statiques `/privacy/` et
> `/privacy/data-deletion.html`.
>
> Après l'enregistrement, coller l'URL dans le **débogueur de partage**
> (developers.facebook.com/tools/debug). S'il ne montre pas le texte de la politique, ou si Meta
> signale l'URL comme invalide, mettre plutôt :
> - `https://getcauseo.com/privacy/`
> - `https://getcauseo.com/privacy/data-deletion.html`
>
> Ces pages statiques viennent du dépôt `causeo-privacy` (dernière mise à jour : 17 septembre).
> Elles ne reprennent pas encore les mentions ajoutées au dashboard (NAKO IT SEC SARL) : les
> mettre à jour avant de les déclarer.

### B. Pendant la fenêtre de bascule : le webhook WhatsApp

Il n'y a **qu'une** URL de webhook par app. Elle vaut pour tous les numéros des businesses
abonnés à l'app : un seul changement suffit.

1. Ouvrir **WhatsApp > Configuration** (dans la nouvelle interface : Cas d'utilisation > *Se
   connecter avec les clients via WhatsApp* > Personnaliser > Configuration), bloc **Webhook** >
   **Modifier**.
2. **URL de rappel** : `https://api.getcauseo.com/api/webhook/whatsapp`
3. **Jeton de vérification** : la **même** valeur qu'aujourd'hui, `WHATSAPP_VERIFY_TOKEN`. Meta
   ne la réaffiche pas. La relire :
   - sur le VPS : `grep '^WHATSAPP_VERIFY_TOKEN=' /var/www/causeo-api/.env` ;
   - ou dans Render > `causeo-api` > Environment.
4. Pendant la validation, suivre l'appel de Meta sur le VPS :
   ```bash
   tail -f /var/log/nginx/causeo-api.access.log | grep --line-buffered webhook
   ```
   Cliquer sur **Vérifier et enregistrer**. Une ligne `GET /api/webhook/whatsapp?hub.mode=subscribe… 200` doit s'afficher.
5. Vérifier que les **champs abonnés** sont toujours cochés : `messages` et `account_update`.
   Changer l'URL ne les modifie pas.
6. Envoyer tout de suite un message WhatsApp de test (étape 8.1).

---

## 4. Google Cloud (console.cloud.google.com > API et services > Identifiants)

Ouvrir le client OAuth dont l'ID est `GOOGLE_CLIENT_ID`. **Ajouter**, sans rien retirer :

| Champ | À ajouter | À garder pendant la transition |
|---|---|---|
| **URI de redirection autorisés** | `https://api.getcauseo.com/auth/google/callback` | `https://causeo-api.onrender.com/auth/google/callback` |
| **Origines JavaScript autorisées** | `https://getcauseo.com` et `https://api.getcauseo.com` | celles de Vercel et de Render |

Enregistrer. La prise en compte prend de quelques minutes à quelques heures : le faire le matin.
Le `.env` du VPS a déjà `GOOGLE_REDIRECT_URI=https://api.getcauseo.com/auth/google/callback`.

Optionnel, et pas le jour J : sur l'**écran de consentement OAuth**, ajouter `getcauseo.com`
aux domaines autorisés et passer la page d'accueil, la politique de confidentialité et les
conditions sur le nouveau domaine. Sur une app déjà validée par Google, ce changement peut
déclencher une nouvelle vérification.

---

## 5. Brevo et Zoho Mail (e-mails sur getcauseo.com)

Deux services envoient pour `getcauseo.com` :
- **Brevo**, pour les e-mails de l'application (mot de passe oublié, rappels) ;
- **Zoho Mail**, pour la boîte `contact@getcauseo.com`.

Tout se règle dans hPanel > Domaines > getcauseo.com > **DNS / Serveurs de noms**.

### A. Le matin : enregistrements DNS

**Avant tout, regarder les enregistrements existants.** Hostinger crée souvent un SPF par défaut
(`v=spf1 include:_spf.mail.hostinger.com ~all`) et des MX `mx1/mx2.hostinger.com`.
Ils doivent **disparaître** : le SPF est remplacé par celui ci-dessous, les MX par ceux de Zoho.

1. **Brevo** : Expéditeurs, domaines et IP dédiées > **Domaines** > Ajouter `getcauseo.com`.
   Brevo affiche ses enregistrements. Les recopier **exactement**. Ils ressemblent à :

   | Type | Nom (dans hPanel) | Valeur |
   |---|---|---|
   | TXT | `@` | `brevo-code:…` (code propre au compte) |
   | CNAME | `brevo1._domainkey` | `b1.getcauseo-com.dkim.brevo.com` |
   | CNAME | `brevo2._domainkey` | `b2.getcauseo-com.dkim.brevo.com` |

   Si Brevo propose un DKIM en **TXT** (`mail._domainkey`, valeur `k=rsa;p=…`) au lieu des deux
   CNAME, mettre celui-là.

2. **Zoho** : recopier ce que Zoho affiche pendant la configuration du domaine :
   - le TXT de vérification `zoho-verification=…` ;
   - les MX ;
   - le DKIM (TXT sur un sélecteur propre à Zoho, par exemple `zmail._domainkey`).

   Les sélecteurs DKIM de Brevo et de Zoho sont différents : les deux coexistent sans problème.

3. **Un seul SPF** pour les deux services (deux enregistrements `v=spf1` les invalident tous les deux) :

   | Type | Nom | Valeur |
   |---|---|---|
   | TXT | `@` | `v=spf1 include:spf.brevo.com include:zoho.eu ~all` |

   **`zoho.eu` ou `zoho.com` ?** Cela dépend de la région du compte Zoho. Regarder l'adresse
   utilisée pour se connecter à Zoho Mail : `mail.zoho.eu` → `include:zoho.eu`, `mail.zoho.com` →
   `include:zoho.com`. Les MX suivent la même région (`mx.zoho.eu`, `mx2.zoho.eu`, `mx3.zoho.eu`,
   ou les mêmes en `.com`).

4. **Un seul DMARC** pour les deux :

   | Type | Nom | Valeur |
   |---|---|---|
   | TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:contact@getcauseo.com` |

   Si Brevo propose sa propre adresse de rapports (`rua=mailto:rua@dmarc.brevo.com`), on peut
   mettre les deux, séparées par une virgule. Passer à `p=quarantine` après quelques semaines de
   rapports propres.

5. Vérifier depuis le poste :
   ```powershell
   nslookup -type=TXT getcauseo.com           # UN SEUL "v=spf1 …", plus le brevo-code et zoho-verification
   nslookup -type=TXT _dmarc.getcauseo.com
   nslookup -type=CNAME brevo1._domainkey.getcauseo.com
   nslookup -type=MX getcauseo.com            # MX de Zoho seulement
   ```

### B. Attendre les validations

- Brevo > Domaines : `getcauseo.com` doit passer à **Authentifié**. Il faut quelques minutes à
  quelques heures. Le bouton « Vérifier » relance le contrôle.
- Zoho : le domaine est vérifié et la boîte `contact@getcauseo.com` reçoit un e-mail de test
  envoyé depuis Gmail.

### C. Nouvelle adresse d'expéditeur (une fois Brevo « Authentifié »)

1. Brevo > Expéditeurs > **Ajouter un expéditeur** : nom `Causeo`, adresse
   `contact@getcauseo.com`. Si Brevo demande un code de confirmation, il arrive dans la boîte
   Zoho : c'est pourquoi Zoho doit fonctionner avant.
2. Sur le VPS :
   ```bash
   sed -i 's/^MAIL_FROM_ADDRESS=.*/MAIL_FROM_ADDRESS=contact@getcauseo.com/' /var/www/causeo-api/.env
   grep '^MAIL_FROM_ADDRESS=' /var/www/causeo-api/.env      # MAIL_FROM_ADDRESS=contact@getcauseo.com
   sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api   # recharge la configuration
   ```
   `sed -i` garde le propriétaire `deploy` et les droits 600 du fichier.
3. Tester par un « mot de passe oublié » (étape 8.4).

Tant que Brevo n'est pas authentifié, ne pas toucher à `MAIL_FROM_ADDRESS` : l'adresse actuelle
continue de fonctionner depuis le VPS.

---

## 6. Render et planificateur (fenêtre hh:10 à hh:55)

Le planificateur lance `billing:sync` (statuts d'abonnement, rappels d'échéance) chaque heure à
hh:00, et `billing:weekly-report` à hh:05. S'il tournait à la fois sur Render et sur le VPS, les
rappels partiraient en double.

En plus de l'ordre ci-dessous, un filet de sécurité existe : les deux tâches sont en
`onOneServer()`, avec un verrou dans le cache `database`, donc dans la base partagée. Même si les
deux planificateurs tournaient à la même minute, une seule exécution passerait.

1. **Render > `causeo-api` > Environment**. Modifier en une seule fois, pour un seul
   redéploiement :

   | Variable | Nouvelle valeur | Pourquoi |
   |---|---|---|
   | `RUN_SCHEDULER` | `false` | coupe `schedule:work` sur Render |
   | `RUN_QUEUE_WORKER` | `false` | les jobs (documents, alertes WhatsApp) sont traités par le worker du VPS seul ; les tests de l'étape 8 portent alors bien sur le VPS |
   | `FRONTEND_URL` | `https://getcauseo.com` | les e-mails encore envoyés par Render pointent vers le nouveau dashboard |
   | `CORS_ALLOWED_ORIGINS` | `https://causeo-dashboard.vercel.app,https://getcauseo.com` | sinon, avec le nouveau `FRONTEND_URL`, Render refuserait l'ancien dashboard |

   **Save, rebuild and deploy**.
2. Attendre que le déploiement soit **Live** (Render > Events). L'ancienne instance, qui fait
   tourner le planificateur, s'arrête à ce moment-là.
3. Contrôler les logs de Render depuis le démarrage du nouveau déploiement : aucune ligne
   `Running scheduled tasks` ni `Processing jobs` ne doit apparaître.
4. **Aussitôt**, sur le VPS :
   ```bash
   causeo-scheduler on                       # Planificateur activé sur ce serveur (/etc/cron.d/causeo-scheduler)
   causeo-scheduler status                   # Planificateur : actif
   cd /var/www/causeo-api && sudo -u deploy php8.2 artisan schedule:list   # billing:sync et billing:weekly-report, avec leur prochain passage
   ```
5. Au passage suivant de hh:00, vérifier que cron a bien lancé le planificateur :
   ```bash
   grep causeo /var/log/syslog | grep CRON | tail -3
   ```
   On peut aussi lancer `journalctl -u cron --since "-5 min" --no-pager`.

Si `RUN_SCHEDULER` n'existe pas encore sur Render, la créer : sans elle, la valeur par défaut est
`true`.

---

## 7. Rediriger causeo-dashboard.vercel.app vers getcauseo.com

Dans le dépôt `causeo-dashboard`, créer `vercel.json` à la racine. Seul Vercel le lit : le build
du VPS l'ignore.

```json
{
  "redirects": [
    { "source": "/:path*", "destination": "https://getcauseo.com/:path*", "permanent": false }
  ]
}
```

Committer et pousser sur `main` : Vercel redéploie en une minute environ. `"permanent": false`
envoie une redirection temporaire (307), qu'on peut annuler sans que les navigateurs l'aient
mémorisée. Passer à `true` (308) après quelques jours.

Vérifier que le chemin **et les paramètres** sont conservés. C'est indispensable pour les liens
« mot de passe oublié » déjà envoyés, valables 60 minutes :
```bash
curl -sI "https://causeo-dashboard.vercel.app/reset-password?token=abc&email=a%40b.c" | grep -i '^location'
# location: https://getcauseo.com/reset-password?token=abc&email=a%40b.c
```

Les utilisateurs devront se reconnecter une fois : le token de connexion est stocké dans le
navigateur, domaine par domaine.

---

## 8. Tests après bascule

Garder ouverts, dans un terminal root sur le VPS, les logs à surveiller :
```bash
tail -f /var/www/causeo-api/storage/logs/laravel-$(date +%F).log      # erreurs de l'application
tail -f /var/log/nginx/causeo-api.access.log                         # chaque requête reçue
tail -f /var/log/supervisor/causeo-worker.log                        # jobs traités par le worker
```

| # | Test | Comment | Résultat attendu |
|---|---|---|---|
| 1 | **Message WhatsApp et réponse du bot** | Depuis un téléphone personnel, écrire au numéro d'un business connecté | `POST /api/webhook/whatsapp … 200` dans le log nginx, le bot répond en quelques secondes, la conversation apparaît dans le dashboard. Rien côté Render. |
| 2 | **Connexion classique** | `https://getcauseo.com/auth/login`, e-mail + mot de passe | Arrivée sur `/dashboard`, les données sont là. Recharger une route profonde (F5 sur `/dashboard/billing`) fonctionne. |
| 3 | **Connexion Google** | Bouton Google sur la page de connexion | Passage par `api.getcauseo.com/auth/google/redirect`, puis Google, puis retour sur `getcauseo.com/auth/callback` et `/dashboard`. Une erreur `redirect_uri_mismatch` signifie que l'étape 4 n'est pas encore prise en compte. |
| 4 | **Mot de passe oublié** | Demander une réinitialisation vers une adresse Gmail | E-mail reçu, lien `https://getcauseo.com/reset-password?token=…`, le nouveau mot de passe fonctionne. Après l'étape 5 C : expéditeur `contact@getcauseo.com`, et dans Gmail > « Afficher l'original » : SPF, DKIM et DMARC à `PASS`. |
| 5 | **Déclaration de paiement** | Compte gérant, `/dashboard/billing` : déclarer un paiement avec une capture comme preuve | Le paiement apparaît en attente. Côté admin : notification et preuve visible. Valider (ou refuser) le paiement de test : l'abonnement est mis à jour. |
| 6 | **Notification** | Après le test 5 : cloche de notifications du gérant. Puis, dans les réglages des alertes WhatsApp, bouton de test | Notification de validation (ou de refus) du paiement. Alerte WhatsApp reçue sur le numéro du gérant (job `SendOwnerWhatsAppAlert` dans le log du worker). |
| 7 | **Document uploadé et traité** | Dans un business, ajouter un PDF court à la base de connaissances | Le job `ProcessDocumentJob` apparaît dans le log du worker (`RUNNING` puis `DONE`). Le document passe à l'état traité. Une question au bot sur son contenu reçoit une réponse qui s'en sert. |
| 8 | **Média lourd** | Envoyer un média d'environ 10 Mo | Accepté : nginx et PHP acceptent jusqu'à 20 Mo. |
| 9 | **Embedded Signup** (si possible) | Connexion WhatsApp d'un business de test depuis `getcauseo.com` | La fenêtre Meta s'ouvre, sans erreur de domaine non autorisé. |
| 10 | **Ancien dashboard** | Ouvrir `https://causeo-dashboard.vercel.app/dashboard` | Redirection vers `https://getcauseo.com/dashboard` |

En fin de journée :
```bash
grep -c '"POST /api/webhook/whatsapp' /var/log/nginx/causeo-api.access.log   # augmente au fil des messages
grep -iE 'error|critical' /var/www/causeo-api/storage/logs/laravel-$(date +%F).log | tail
```
Dans les logs de Render, plus aucun appel au webhook ne doit apparaître.

---

## 9. Retour arrière

La base est partagée et Render tourne toujours : on revient en arrière **en changeant des
adresses**, sans restauration de données. Ne refaire que ce qui a été changé, dans cet ordre.

1. **Webhook** (le plus urgent si les messages ne passent plus). Dans Meta > WhatsApp >
   Configuration, remettre `https://causeo-api.onrender.com/api/webhook/whatsapp`, avec le même
   jeton.
2. **Planificateur**. D'abord le couper sur le VPS, puis le rallumer sur Render :
   ```bash
   causeo-scheduler off && causeo-scheduler status      # Planificateur : coupé
   ```
   Puis, sur Render : `RUN_SCHEDULER=true`, `RUN_QUEUE_WORKER=true`,
   `FRONTEND_URL=https://causeo-dashboard.vercel.app`. Enregistrer et redéployer.
3. **Ancien dashboard**. Supprimer `vercel.json` du dépôt `causeo-dashboard` et pousser : Vercel
   redevient le dashboard principal, toujours branché sur Render (`environment.prod.ts`).
4. **Expéditeur**. Rien à faire : Render a gardé l'ancien `MAIL_FROM_ADDRESS`.
5. **Laisser** les ajouts chez Meta, Google et Brevo, ainsi que le VPS allumé, pour comprendre le
   problème. Rien de tout cela ne gêne Render.

**Problème limité au code du VPS** (Render n'y est pour rien) : revenir à la version précédente.
```bash
sudo -u deploy git -C /var/www/causeo-api log --oneline -5
sudo -u deploy git -C /var/www/causeo-api checkout <commit_précédent>
cd /var/www/causeo-api && sudo -u deploy composer install --no-dev --optimize-autoloader \
  && sudo -u deploy php8.2 artisan config:cache && sudo -u deploy php8.2 artisan route:cache \
  && systemctl reload php8.2-fpm && sudo -u deploy php8.2 artisan queue:restart
```
Puis corriger sur `main` (`git revert`). Le `deploy.sh` suivant revient sur `main`.

**Dashboard du VPS défectueux** : revenir à la version précédente, d'un seul coup.
```bash
sudo -u deploy bash /var/www/causeo-api/deploy/dashboard-release.sh rollback
```

**Après une mise à jour d'Ubuntu ratée** (étape 2) : restaurer l'instantané dans hPanel.

---

## 10. À supprimer après 2 ou 3 jours

Seulement si, sur toute la période :
- les logs de Render ne montrent plus d'appel au webhook ni de requête du dashboard ;
- les tests de l'étape 8 sont tous passés ;
- les tâches de hh:00 et hh:05 ont tourné sur le VPS et les rappels sont partis.

**Render**
- Suspendre le service `causeo-api` (Settings > **Suspend Service**). Attendre encore quelques
  jours, puis le **supprimer** (Settings > Delete Web Service).
- Ses variables d'environnement disparaissent avec lui. Le `.env` du VPS en est désormais
  la seule copie, et le fichier `causeo-env-production.txt` du poste aussi. Le ranger en lieu
  sûr ou le supprimer : il contient tous les secrets.

**Vercel**
- **Garder** le projet `causeo-dashboard` avec sa redirection (gratuit), au moins quelques mois :
  des favoris et d'anciens e-mails pointent encore vers `causeo-dashboard.vercel.app`. Passer la
  redirection en `"permanent": true`.
- Désactiver les déploiements automatiques inutiles, si on le souhaite : Settings > Git >
  *Ignored Build Step*.

**Ailleurs**
- `.env` du VPS : retirer `https://causeo-dashboard.vercel.app` de `CORS_ALLOWED_ORIGINS`, qui
  devient `https://getcauseo.com`. L'ancien dashboard ne se charge plus : il redirige. Lancer
  ensuite `deploy.sh api`.
- Google Cloud : retirer l'URI de redirection et les origines de Render et de Vercel.
- Meta : retirer `causeo-dashboard.vercel.app` (et `natachazongo.github.io` s'il y figure) des
  domaines de l'app, des URI de redirection et des domaines du SDK JavaScript.
- GitHub Pages de `causeo-privacy` (`natachazongo.github.io`) : à désactiver une fois les URL
  légales changées chez Meta.
- GitHub : créer la variable `DEPLOY_ENABLED=true` dans `causeo-api` et `causeo-dashboard` pour
  le déploiement automatique (`MIGRATION.md`, étape 6 bis D).
- DNS : remonter le TTL des enregistrements `@`, `www` et `api` (300 s → 3600 s).
