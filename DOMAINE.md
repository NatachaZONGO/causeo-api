# Domaine getcauseo.com (VPS Hostinger)

Tout ce qui dépend du domaine, et où le modifier.

> **Migration terminée.** Depuis la bascule du 15 octobre 2026, l'API et le dashboard
> tournent sur le VPS Hostinger. Render est supprimé ; Vercel ne fait plus que rediriger
> `causeo-dashboard.vercel.app` vers `https://getcauseo.com` (chemin et paramètres
> conservés). Le déroulé de la migration est archivé dans `deploy/MIGRATION.md` et
> `deploy/BASCULE.md`.

| | Adresse |
|---|---|
| API | `https://api.getcauseo.com` |
| Dashboard | `https://getcauseo.com` (`www` y renvoie) |
| Pages légales (HTML statique) | `https://getcauseo.com/privacy/` |
| Base de données, fichiers | Supabase |

> **À ne jamais oublier : garder la même `APP_KEY`.**
> Les tokens WhatsApp des businesses (`businesses.whatsapp_token`) sont chiffrés avec
> `APP_KEY`. Avec une nouvelle clé, ils deviennent illisibles et plus aucun business ne
> peut envoyer de message. Le `.env` du VPS en est la seule copie : la sauvegarder.
> S'il faut un jour changer de clé, mettre l'ancienne dans `APP_PREVIOUS_KEYS`.

## 1. Variables d'environnement

### API (`/var/www/causeo-api/.env` sur le VPS)

Modèle : `deploy/.env.production.example`.

| Variable | Valeur | Rôle |
|---|---|---|
| `APP_KEY` | **inchangée** | Déchiffre les tokens WhatsApp (voir plus haut) |
| `APP_URL` | `https://api.getcauseo.com` | Adresse publique de l'API |
| `FRONTEND_URL` | `https://getcauseo.com` | Liens des e-mails (réinitialisation, rappels, connexion), retour de la connexion Google, CORS par défaut |
| `CORS_ALLOWED_ORIGINS` | vide | Origines supplémentaires autorisées à appeler l'API depuis un navigateur ; vide, seul `FRONTEND_URL` l'est |
| `GOOGLE_REDIRECT_URI` | `https://api.getcauseo.com/auth/google/callback` | Doit être identique à l'URL déclarée chez Google |
| `MAIL_FROM_ADDRESS` | une adresse `@getcauseo.com` | Expéditeur des e-mails, sur le domaine vérifié chez Brevo |
| `MAIL_FROM_NAME` | `Causeo` | Nom affiché de l'expéditeur |
| `APP_NAME` | `Causeo` | Affiché dans le pied de page des e-mails |
| `WHATSAPP_BRIDGE_URL` | adresse du bridge | Bridge WhatsApp Express (désactivé par défaut, voir `WHATSAPP_EXPRESS_ENABLED`) |
| `WHATSAPP_BRIDGE_TOKEN` | un vrai secret, seulement si le mode Express est activé | Authentifie le bridge auprès de l'API |

Ne dépendent pas du domaine : base de données et stockage Supabase,
`WHATSAPP_TOKEN`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, `FACEBOOK_APP_ID`,
`FACEBOOK_APP_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ANTHROPIC_API_KEY`,
`GEMINI_API_KEY`, `OPENAI_API_KEY`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`PAYMENT_*`.

Port SMTP : 2525 avec Brevo (`MAIL_PORT`).

La configuration est mise en cache par `deploy/deploy.sh` (`php artisan config:cache`).
Après toute modification du `.env`, lancer
`sudo -u deploy -H bash /var/www/causeo-api/deploy/deploy.sh api`.

### Dashboard (dépôt `causeo-dashboard`)

La seule adresse de nos services écrite dans le dashboard (Angular) est celle de l'API,
dans les fichiers d'environnement.

| Fichier | Clé | Valeur |
|---|---|---|
| `src/environments/environment.vps.ts` (build du VPS) | `apiUrl` | `https://api.getcauseo.com/api/v1` |
| `src/environments/environment.ts` (dev) | `apiUrl` | `http://localhost:8000/api/v1` |
| les deux | `facebookAppId`, `facebookConfigId` | App Meta et configuration de l'Embedded Signup |
| les deux | `supportWhatsapp` | vide (pas de bouton « connexion assistée »), ou numéro du support |

- Le VPS construit le dashboard avec `npm run build:vps` (lancé par `deploy/deploy.sh` ou
  par le workflow du dépôt `causeo-dashboard`), qui prend `environment.vps.ts`
  (`fileReplacements` dans `angular.json`).
- Ces valeurs sont **compilées** dans le dashboard : il faut modifier le fichier, committer
  et redéployer.
- La connexion Google part de l'API : le dashboard redirige vers
  `https://api.getcauseo.com/auth/google/redirect`.
- Pages du dashboard vers lesquelles l'API renvoie (avec `FRONTEND_URL`) :

  | Chemin | Utilisé par |
  |---|---|
  | `/auth/callback?token=…&user=…` | retour de la connexion Google |
  | `/reset-password?token=…&email=…` | e-mail « mot de passe oublié » (lien valable 60 minutes) |
  | `/auth/login` | e-mail « mot de passe modifié » |
  | `/dashboard/billing` | rappels d'échéance et de fin d'essai |
  | `/dashboard` | rapport hebdomadaire |
  | `/dashboard/feedback` | e-mail au gérant : réponse à son retour, retour résolu |
  | `/dashboard/admin/feedback?feedback=…` | e-mail aux admins : nouveau retour d'un gérant (`/admin/feedback` y redirige) |
  | `/conditions-utilisation`, `/confidentialite`, `/suppression-des-donnees` | pages légales du dashboard (liées en bas de la page d'accueil) |

  Le dashboard est une application à page unique : nginx sert `index.html` pour ces
  chemins (`try_files $uri /index.html`, `deploy/nginx/dashboard.conf`).
- Les adresses de Meta (`connect.facebook.net`, origines `www.facebook.com` et
  `web.facebook.com` de l'Embedded Signup) et `wa.me` ne dépendent pas de notre domaine.

## 2. CORS et Sanctum

- **CORS** (`config/cors.php`) : l'API accepte les origines de `CORS_ALLOWED_ORIGINS`
  (liste séparée par des virgules), ou à défaut `FRONTEND_URL`. L'ancien dashboard de
  Vercel ne fait plus que rediriger : il n'appelle plus l'API et n'a pas à y figurer.
- **Sanctum** : le dashboard s'authentifie par token (`Authorization: Bearer …`), sans
  cookie de session. `SANCTUM_STATEFUL_DOMAINS` et `SESSION_DOMAIN` ne servent pas.

## 3. Meta (developers.facebook.com, app Causeo)

| Où | Valeur |
|---|---|
| WhatsApp > Configuration > URL de rappel du webhook | `https://api.getcauseo.com/api/webhook/whatsapp` (jeton de vérification : `WHATSAPP_VERIFY_TOKEN`) |
| Paramètres > Général > Domaines de l'app | `getcauseo.com` |
| Facebook Login for Business > Paramètres > URI de redirection OAuth valides | `https://getcauseo.com/` |
| Facebook Login for Business > Paramètres > Domaines autorisés pour le SDK JavaScript (Embedded Signup) | `https://getcauseo.com` |
| Paramètres > Général > URL de la politique de confidentialité | `https://getcauseo.com/privacy/` |
| Paramètres > Général > URL des instructions de suppression des données | `https://getcauseo.com/privacy/data-deletion.html` |
| Paramètres > Général > URL du site | `https://getcauseo.com` |

La politique de confidentialité et la page de suppression des données viennent du dépôt
`causeo-privacy`. Le dashboard a aussi ses pages (`/confidentialite`,
`/suppression-des-donnees`, `/conditions-utilisation`), mais leur texte est affiché par
JavaScript : un robot qui ne l'exécute pas, comme celui de Meta, ne voit qu'une page vide.
Garder les URL `/privacy/` (HTML statique, servies par nginx, `deploy/nginx/dashboard.conf`)
chez Meta, et aligner leur contenu sur celui du dashboard.

Il n'y a qu'**une** URL de webhook par app. Meta l'appelle en GET pour la vérifier dès
qu'on la change.

## 4. Google (console.cloud.google.com > API et services > Identifiants)

Dans le client OAuth utilisé par `GOOGLE_CLIENT_ID` :

- **URI de redirection autorisés** : `https://api.getcauseo.com/auth/google/callback` ;
- **Origines JavaScript autorisées** : `https://getcauseo.com` et `https://api.getcauseo.com` ;
- **Écran de consentement OAuth** : domaine autorisé `getcauseo.com`, page d'accueil,
  politique de confidentialité et conditions d'utilisation sur ce domaine.

Après la connexion, l'API renvoie vers `FRONTEND_URL/auth/callback`.

## 5. Brevo (e-mails)

Le domaine `getcauseo.com` est authentifié chez Brevo (Expéditeurs, domaines et IP
dédiées > Domaines), avec chez l'hébergeur DNS :

- le **code Brevo** (TXT `brevo-code:…`) ;
- les enregistrements **DKIM** fournis par Brevo ;
- un seul **SPF** : TXT `v=spf1 … include:spf.brevo.com ~all`. Pour ajouter un autre
  service d'envoi, **fusionner** les `include:` dans ce même enregistrement : deux SPF
  invalident les deux ;
- **DMARC** : TXT sur `_dmarc.getcauseo.com`.

Pour vérifier : envoyer un « mot de passe oublié » vers une adresse Gmail et contrôler,
dans « Afficher l'original », que SPF, DKIM et DMARC sont à `PASS`.

## 6. Autres services trouvés dans le code

| Service | Dépend du domaine ? | À savoir |
|---|---|---|
| Supabase (base de données, stockage `documents` et `media`) | Non | Si Supabase limite l'accès par adresse IP, l'IP du VPS doit être autorisée. Les URL des médias restent sur `supabase.co`. |
| Bridge WhatsApp Express (`WHATSAPP_BRIDGE_URL`) | Oui, s'il est activé | Désactivé par défaut (404). S'il est réactivé, le bridge appelle `https://api.getcauseo.com/api/webhook/whatsapp-express` et `/whatsapp-express/status`. |
| Webhook de paiement (`/api/webhook/payment`) | Oui, s'il sert | Il se contente de journaliser. Un prestataire qui l'appelle doit avoir l'URL `https://api.getcauseo.com/api/webhook/payment`. |
| Anthropic, Gemini, OpenAI, Graph API Meta | Non | Appels sortants avec clé d'API. |

## 7. Hébergement sur le VPS

Tout est dans `deploy/` ; l'exploitation courante est décrite à la fin de
`deploy/MIGRATION.md`.

- nginx sert l'API via php-fpm et le dashboard en fichiers statiques ; HTTPS par
  Let's Encrypt (`deploy/setup.sh`).
- nginx transmet directement à php-fpm l'IP du client et HTTPS : aucun en-tête
  `X-Forwarded-*` à croire. `config/trustedproxy.php` fait confiance à la machine elle-même
  par défaut ; `TRUSTED_PROXIES` sert si un autre proxy (Cloudflare…) s'ajoute devant.
- Worker de file : Supervisor (`deploy/supervisor/causeo-worker.conf`).
- Planificateur : cron, activé par `sudo causeo-scheduler on` (`deploy/scheduler.sh`).
  `deploy/setup.sh` ne l'active pas : sur un nouveau serveur, l'activer à la main. **Un
  seul serveur doit le faire tourner** : sinon les rappels d'échéance et le rapport
  hebdomadaire partent en double, puisque la base est partagée.
- Déploiement automatique à chaque push sur `main`, une fois les tests passés
  (`.github/workflows/deploy.yml`).

## 8. Ancienne infrastructure

- **Render** : service supprimé. `causeo-api.onrender.com` ne répond plus ; plus rien
  dans ce dépôt ne s'y rapporte (Dockerfile et `docker-entrypoint.sh` retirés).
- **Vercel** : le projet `causeo-dashboard` ne fait que rediriger
  `causeo-dashboard.vercel.app/<chemin>?<paramètres>` vers `https://getcauseo.com/<chemin>?<paramètres>`
  (`vercel.json` du dépôt `causeo-dashboard`), pour les favoris et les anciens e-mails.
  Il n'appelle plus l'API.
- **GitHub Pages** (`natachazongo.github.io`) : remplacé par `https://getcauseo.com/privacy/`.

Les anciennes adresses déclarées chez Google et Meta se retirent une fois inutiles :
voir la liste de `deploy/MIGRATION.md`, étape 11.
