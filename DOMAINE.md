# Passage sur notre propre nom de domaine (VPS Hostinger)

Tout ce qui dépend du domaine, et l'ordre pour basculer sans coupure.

Dans ce document :

| | Aujourd'hui | Demain (exemple) |
|---|---|---|
| API | `https://causeo-api.onrender.com` | `https://api.<domaine>` |
| Dashboard | `https://causeo-dashboard.vercel.app` | `https://app.<domaine>` |

> **À ne jamais oublier : garder la même `APP_KEY`.**
> Les tokens WhatsApp des businesses (`businesses.whatsapp_token`) sont chiffrés avec
> `APP_KEY`. Avec une nouvelle clé, ils deviennent illisibles et plus aucun business ne
> peut envoyer de message. Copier la valeur exacte depuis Render vers le `.env` du VPS.
> S'il faut un jour changer de clé, mettre l'ancienne dans `APP_PREVIOUS_KEYS`.

## 1. Variables d'environnement

### API (Render aujourd'hui, `.env` du VPS demain)

| Variable | Nouvelle valeur | Rôle |
|---|---|---|
| `APP_KEY` | **inchangée** | Déchiffre les tokens WhatsApp (voir plus haut) |
| `APP_URL` | `https://api.<domaine>` | Adresse publique de l'API |
| `FRONTEND_URL` | `https://app.<domaine>` | Liens des e-mails (réinitialisation, rappels, connexion), retour de la connexion Google, CORS par défaut |
| `CORS_ALLOWED_ORIGINS` | `https://causeo-dashboard.vercel.app,https://app.<domaine>` pendant la transition, puis vide | Origines autorisées à appeler l'API depuis un navigateur |
| `GOOGLE_REDIRECT_URI` | `https://api.<domaine>/auth/google/callback` | Doit être identique à l'URL déclarée chez Google |
| `MAIL_FROM_ADDRESS` | `contact@<domaine>` (par exemple) | Expéditeur des e-mails, sur le domaine vérifié chez Brevo |
| `MAIL_FROM_NAME` | `Causeo` | Nom affiché de l'expéditeur |
| `APP_NAME` | `Causeo` | Affiché dans le pied de page des e-mails |
| `WHATSAPP_BRIDGE_URL` | adresse du bridge s'il déménage aussi | Bridge WhatsApp Express (désactivé par défaut, voir `WHATSAPP_EXPRESS_ENABLED`) |
| `WHATSAPP_BRIDGE_TOKEN` | un vrai secret, seulement si le mode Express est activé | Authentifie le bridge auprès de l'API |

Inchangées (ne dépendent pas du domaine) : base de données et stockage Supabase,
`WHATSAPP_TOKEN`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, `FACEBOOK_APP_ID`,
`FACEBOOK_APP_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ANTHROPIC_API_KEY`,
`GEMINI_API_KEY`, `OPENAI_API_KEY`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`PAYMENT_*`.

Port SMTP : Render bloque le 587, d'où le 2525 avec Brevo. Le 2525 fonctionne aussi
depuis un VPS ; vérifier simplement que Hostinger ne le bloque pas (`MAIL_PORT`).

La configuration est mise en cache au démarrage (`php artisan config:cache` dans
`docker-entrypoint.sh`). Après toute modification du `.env`, relancer le conteneur, ou
à défaut cette commande.

### Dashboard (dépôt `causeo-dashboard`, Vercel aujourd'hui)

Vérifié dans le code du dashboard (Angular) : la seule adresse de nos services est celle
de l'API, dans les fichiers d'environnement. Aucun lien vers `causeo-dashboard.vercel.app`,
`causeo-api.onrender.com` ou `natachazongo.github.io` n'est écrit ailleurs.

| Fichier | Clé | Valeur aujourd'hui | Demain |
|---|---|---|---|
| `src/environments/environment.prod.ts` | `apiUrl` | `https://causeo-api.onrender.com/api/v1` | `https://api.<domaine>/api/v1` |
| `src/environments/environment.ts` (dev) | `apiUrl` | `http://localhost:8000/api/v1` | inchangé |
| les deux | `facebookAppId`, `facebookConfigId` | App Meta et configuration de l'Embedded Signup | inchangés |
| les deux | `supportWhatsapp` | vide (pas de bouton « connexion assistée ») | numéro du support, si besoin |

- Ces valeurs sont **compilées** dans le dashboard : `ng build` remplace `environment.ts`
  par `environment.prod.ts` (`fileReplacements` dans `angular.json`). Ce ne sont pas des
  variables d'environnement Vercel : il faut modifier le fichier, committer et redéployer.
- La connexion Google part de l'API : le dashboard redirige vers
  `<apiUrl sans /api/v1>/auth/google/redirect`, donc vers `https://api.<domaine>/auth/google/redirect`
  dès que `apiUrl` change. Rien d'autre à modifier côté dashboard.
- Pages du dashboard vers lesquelles l'API renvoie (avec `FRONTEND_URL`) : elles doivent
  exister sur le nouveau domaine, au même chemin.

  | Chemin | Utilisé par |
  |---|---|
  | `/auth/callback?token=…&user=…` | retour de la connexion Google |
  | `/reset-password?token=…&email=…` | e-mail « mot de passe oublié » (lien valable 60 minutes) |
  | `/auth/login` | e-mail « mot de passe modifié » |
  | `/dashboard/billing` | rappels d'échéance et de fin d'essai |
  | `/dashboard` | rapport hebdomadaire |

  Le dashboard est une application à page unique : l'hébergement doit servir `index.html`
  pour ces chemins (réglage par défaut de Vercel pour Angular ; sur le VPS, une règle
  `try_files $uri /index.html` dans Nginx).
- Les adresses de Meta (`connect.facebook.net`, origines `www.facebook.com` et
  `web.facebook.com` de l'Embedded Signup) et `wa.me` ne dépendent pas de notre domaine.

Les utilisateurs devront se reconnecter une fois : le token de connexion est stocké
dans le navigateur, par domaine.

## 2. CORS et Sanctum

- **CORS** (`config/cors.php`) : l'API accepte les origines de `CORS_ALLOWED_ORIGINS`
  (liste séparée par des virgules), ou à défaut `FRONTEND_URL`. Pendant la transition,
  y mettre l'ancien et le nouveau dashboard.
- **Sanctum** : le dashboard s'authentifie par token (`Authorization: Bearer …`), sans
  cookie de session. `SANCTUM_STATEFUL_DOMAINS` et `SESSION_DOMAIN` n'ont donc pas à
  changer.

## 3. Meta (developers.facebook.com, app Causeo)

Ajouter les nouvelles adresses **à côté** des anciennes, et ne retirer les anciennes
qu'à la fin.

| Où | Valeur |
|---|---|
| WhatsApp > Configuration > URL de rappel du webhook | `https://api.<domaine>/api/webhook/whatsapp` (même jeton de vérification : `WHATSAPP_VERIFY_TOKEN`) |
| Paramètres > Général > Domaines de l'app | `<domaine>` (en plus des domaines actuels pendant la transition) |
| Facebook Login for Business > Paramètres > URI de redirection OAuth valides | `https://app.<domaine>/` |
| Facebook Login for Business > Paramètres > Domaines autorisés pour le SDK JavaScript (Embedded Signup) | `https://app.<domaine>` |
| Paramètres > Général > URL de la politique de confidentialité | nouvelle adresse de la page |
| Paramètres > Général > URL des instructions de suppression des données | nouvelle adresse de la page |
| Paramètres > Général > URL du site | `https://<domaine>` |

L'API ne sert ni la politique de confidentialité ni la page de suppression des données :
elles sont hébergées ailleurs (probablement `natachazongo.github.io`). Les déplacer sur
le nouveau domaine, puis mettre à jour les deux URL ci-dessus. Meta vérifie qu'elles
répondent.

Il n'y a qu'**une** URL de webhook par app. On la change en dernier, quand la nouvelle
API répond (voir l'ordre de bascule). Meta l'appelle aussitôt en GET pour la vérifier.

## 4. Google (console.cloud.google.com > API et services > Identifiants)

Dans le client OAuth utilisé par `GOOGLE_CLIENT_ID` :

- **URI de redirection autorisés** : ajouter `https://api.<domaine>/auth/google/callback`
  (garder `https://causeo-api.onrender.com/auth/google/callback` pendant la transition) ;
- **Origines JavaScript autorisées** : ajouter `https://app.<domaine>` et
  `https://api.<domaine>` ;
- **Écran de consentement OAuth** : domaines autorisés (`<domaine>`), page d'accueil,
  politique de confidentialité et conditions d'utilisation sur le nouveau domaine.

Après la connexion, l'API renvoie vers `FRONTEND_URL/auth/callback`.

## 5. Brevo (e-mails)

1. **Expéditeurs, domaines et IP dédiées > Domaines** : ajouter `<domaine>`.
2. Créer chez l'hébergeur DNS les enregistrements que Brevo donne :
   - **code Brevo** (TXT `brevo-code:…`) pour prouver la propriété ;
   - **DKIM** (enregistrements fournis par Brevo) ;
   - **SPF** : un seul enregistrement TXT `v=spf1 … include:spf.brevo.com ~all` sur
     `<domaine>`. S'il en existe déjà un (pour la messagerie Hostinger par exemple),
     **fusionner** les `include:` dans le même enregistrement : deux SPF invalident les deux ;
   - **DMARC** : TXT sur `_dmarc.<domaine>`, pour commencer
     `v=DMARC1; p=none; rua=mailto:dmarc@<domaine>`, puis `p=quarantine` une fois les
     rapports propres.
3. Attendre que Brevo affiche le domaine comme authentifié.
4. Créer l'expéditeur `contact@<domaine>` et mettre `MAIL_FROM_ADDRESS` à jour.
5. Envoyer un « mot de passe oublié » vers une adresse Gmail et vérifier, dans
   « Afficher l'original », que SPF, DKIM et DMARC sont à `PASS`.

## 6. Autres services trouvés dans le code

| Service | Dépend du domaine ? | À faire |
|---|---|---|
| Supabase (base de données, stockage `documents` et `media`) | Non | Si Supabase limite l'accès par adresse IP, autoriser l'IP du VPS. Les URL des médias restent sur `supabase.co`. |
| Bridge WhatsApp Express (`WHATSAPP_BRIDGE_URL`) | Oui, s'il est activé | Désactivé par défaut (404). S'il est réactivé, le bridge appelle `https://api.<domaine>/api/webhook/whatsapp-express` et `/whatsapp-express/status` : mettre à jour l'adresse de l'API dans **sa** configuration. |
| Webhook de paiement (`/api/webhook/payment`) | Oui, s'il sert | Il se contente de journaliser. Si un prestataire l'appelle, lui donner la nouvelle URL. |
| Anthropic, Gemini, OpenAI, Graph API Meta | Non | Rien (appels sortants avec clé d'API). |

## 7. Hébergement sur le VPS

- Reverse proxy (Nginx ou Caddy) avec HTTPS (Let's Encrypt) devant l'API, qui écoute
  sur `PORT` (8080 par défaut).
- Derrière ce proxy, Laravel voit des requêtes en `http`. Déclarer le proxy comme fiable
  (`$middleware->trustProxies(at: '127.0.0.1')` dans `bootstrap/app.php`), sinon les
  liens générés par l'API, comme ceux de la pagination, seront en `http://`.
- Le conteneur lance aussi le worker de file et le planificateur (`docker-entrypoint.sh`).
  **Un seul environnement doit faire tourner le planificateur** : sinon les rappels
  d'échéance et le rapport hebdomadaire partent en double, puisque les deux instances
  partagent la même base.

## 8. Ordre de bascule sans coupure

Principe : la base (Supabase) est partagée. L'ancienne et la nouvelle API peuvent donc
tourner en même temps. On ajoute partout les nouvelles adresses, on bascule, puis on
retire les anciennes.

1. **J-2 : DNS.** Baisser le TTL des enregistrements du domaine (300 s). Créer
   `api.<domaine>` et `app.<domaine>`.
2. **J-2 : Brevo.** Authentifier le domaine (section 5). Cela n'impacte rien tant que
   `MAIL_FROM_ADDRESS` ne change pas.
3. **Nouvelle API sur le VPS.** Même `APP_KEY`, même base, `APP_URL` et
   `GOOGLE_REDIRECT_URI` sur le nouveau domaine,
   `CORS_ALLOWED_ORIGINS=https://causeo-dashboard.vercel.app,https://app.<domaine>`.
   **Planificateur désactivé** sur le VPS pour l'instant. Vérifier
   `https://api.<domaine>/api/v1/plans`.
4. **Ancienne API (Render).** Ajouter aussi les deux origines dans
   `CORS_ALLOWED_ORIGINS`. À l'étape 7, `FRONTEND_URL` change sur Render : sans cette
   liste, l'ancien dashboard serait refusé par le CORS.
5. **Google et Meta.** Ajouter les nouvelles adresses à côté des anciennes (sections 3
   et 4). Ne pas toucher encore au webhook.
6. **Nouveau dashboard.** Le déployer sur `app.<domaine>`, pointé sur
   `https://api.<domaine>`. Tester la connexion (e-mail et Google), l'Embedded Signup
   et un « mot de passe oublié ».
7. **Bascule de l'API.**
   - Sur Render, `FRONTEND_URL=https://app.<domaine>` : les nouveaux e-mails pointent
     vers le nouveau dashboard.
   - Changer l'URL du webhook WhatsApp chez Meta, puis vérifier qu'un message de test
     reçoit une réponse.
   - Si le mode Express est activé, mettre à jour l'adresse de l'API dans le bridge.
   - Activer le planificateur sur le VPS et le désactiver sur Render.
8. **Expéditeur.** `MAIL_FROM_ADDRESS=contact@<domaine>` sur le VPS, puis test d'envoi.
9. **Ancien dashboard.** Le rediriger (Vercel) vers `https://app.<domaine>` en
   **conservant le chemin et les paramètres**. Les liens de réinitialisation déjà
   envoyés, valables 60 minutes, restent ainsi utilisables.
10. **Après une à deux semaines sans trafic sur les anciennes adresses.**
    - Retirer les anciennes adresses chez Google et Meta.
    - Vider `CORS_ALLOWED_ORIGINS`, ce qui revient à `FRONTEND_URL` seul.
    - Arrêter le service Render.
    - Remonter le TTL DNS.
