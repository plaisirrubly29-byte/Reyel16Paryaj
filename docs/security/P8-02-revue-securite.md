# Revue de sécurité — P8-02

| | |
|---|---|
| **Tâche** | P8-02 — Revue de sécurité (OWASP : CORS, CSRF, XSS, injection, en-têtes, secrets, dépendances) |
| **Date** | 25 octobre 2026 |
| **Auteur** | Benginio Jean-Baptiste (@benginio) |
| **Code revu** | Backend : `local/integration` @ `85eead5` (PR #87) · Frontend : `feature/home-redesign` @ `a37fcae` (PR #86) |
| **Méthode** | Lecture du code (backend, frontend, comportement de Laravel 13.36 / Sanctum 4.3 vérifié dans `vendor/`), `composer audit`, `npm audit`, recherche de secrets dans tout l'historique git, revue de la CI |

## Résumé

L'essentiel est solide : **aucune injection, aucun XSS, aucun secret commité, aucune dépendance vulnérable**. CSRF, RBAC, MFA, ledger en entiers avec verrouillage et idempotence résistante à la concurrence sont correctement implémentés.

Avant la production, **3 points bloquants** doivent être réglés : B1 dépend de P4-03, B2 se corrige dans le backend, B3 se règle dans la configuration de l'hébergement. S'y ajoute une **checklist de déploiement** (mode debug, cookies, proxies).

> **État au 25 octobre 2026 : aucun correctif n'est appliqué dans le code.** Les correctifs marqués « ⏳ proposé » sont prêts à être réalisés dans la PR de P8-02 après validation de l'équipe.

| Gravité | Nombre | Correctifs appliqués |
|---|---|---|
| Bloquant | 3 | 0 |
| Élevé | 0 | — |
| Moyen | 5 | 0 |
| Faible | 13 | 0 |
| Info | 8 | — |

Échelle : **Bloquant** = à corriger avant toute mise en production · **Moyen** = à corriger avant le lancement contrôlé · **Faible** = à planifier · **Info** = à connaître.

Statuts : **⏳ proposé** = correctif prêt à être fait dans la PR de P8-02, après validation de l'équipe · **⏳ <responsable>** = à faire par la personne ou la tâche indiquée.

---

## Points bloquants

### B1 — Le pari est placé uniquement dans le navigateur
- **Où :** `frontend/src/features/betting/slip/BetSlipPanel.tsx:199-216`, `frontend/src/features/betting/tickets/ticketStore.ts:35-61`
- **Problème :** « Placer mon pari » (`placeBet()`) n'appelle aucune API : le ticket (betcode, cote combinée, gain potentiel) est fabriqué dans le navigateur et stocké dans `localStorage` (`r16p.tickets.<userId>`). Aucune route `/bets` n'existe encore côté backend. L'écran l'annonce (« Pari de démonstration »), c'est donc un manque assumé en attendant P4-03.
- **Pourquoi c'est bloquant :** cela contredit la **règle n°1 de l'architecture** — toute validation de ticket est décidée côté serveur. Tel quel, le serveur ne connaît aucun ticket : solde non débité, cote non revalidée, limites et statut du compte (suspendu, auto-exclu) non contrôlés, aucun règlement possible. Un joueur auto-exclu peut « parier », et un ticket modifié dans `localStorage` s'affiche comme authentique dans « Mes paris ».
- **Correctif :** dans P4-03/P4-05, remplacer `placeBet()` par `POST /bets` avec `Idempotency-Key`. Envoyer uniquement `{ selections: [{ selection_id, odds_version }], stake }`, jamais la cote ni le gain. Afficher le ticket renvoyé par le serveur ; `MyBetsPage` lit `GET /bets`. Supprimer `ticketStore.ts`.
- **Statut :** ⏳ à faire avec P4-03 / P4-05 (Rubly). C'est un mode démonstration provisoire, mais le même code partirait en production.

### B2 — Proxy non déclaré et cookies sans `Secure` forcé
- **Où :** `backend/bootstrap/app.php` (pas de `trustProxies`), `backend/config/session.php:172` (`secure` = `null`), `.env.example` (pas de `SESSION_SECURE_COOKIE`)
- **Problème :** la proposition d'hébergement D-10 (PR #72) place un reverse proxy (Caddy ou Traefik) devant l'API. Laravel ne fait confiance à aucun proxy par défaut : il voit alors **toutes les requêtes venir de l'IP du proxy**, en HTTP (`$request->isSecure()` faux).
- **Conséquences, même sans attaque :** tous les limiteurs par IP deviennent communs à toute la plateforme :
  - `register` : **5 inscriptions par minute pour tout le site** ;
  - `catalogue` : 120 requêtes par minute pour tout le site, saturées par quelques visiteurs ;
  - `login-ip` : 20 tentatives par minute pour tout le site — un attaquant (ou une simple affluence) **bloque la connexion de tout le monde**.
- **Autres effets :** l'IP enregistrée dans `audit_logs` est celle du proxy ; le cookie de session peut partir sans le drapeau `Secure` (`Response.php:300` ne le pose que si `isSecure()`).
- **Correctif :** `trustProxies(at: TRUSTED_PROXIES)` (IP précises du proxy, jamais `*`) ; `secure` vrai par défaut en production ; variables documentées dans `.env.example`.
- **Statut :** ⏳ proposé (backend).

### B3 — Aucun en-tête de sécurité sur le frontend (hébergement)
- **Où :** vérifié par `curl` — aucun en-tête de sécurité sur le frontend (`:5173`) ni sur l'API (`:8000`) ; rien dans `index.html`, `xampp/htaccess` ni `backend/public/.htaccess`
- **Problème :** les pages HTML du frontend n'envoient ni `Content-Security-Policy`/`frame-ancestors`, ni `X-Content-Type-Options`, ni `Referrer-Policy`, ni HSTS.
- **Scénario :** clickjacking de l'admin (`/admin/users/:id`) affiché dans une iframe transparente qui fait cliquer « Suspendre » ; rétrogradation HTTPS → HTTP sans HSTS ; fuite du jeton de réinitialisation (dans l'URL) par l'en-tête Referer.
- **Correctif :** ces en-têtes se posent sur le **reverse proxy qui sert le frontend** — `Content-Security-Policy: default-src 'self'; connect-src 'self' <API>; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`, plus `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security`. Les en-têtes côté API Laravel sont une défense en profondeur (voir F13).
- **Statut :** ⏳ hébergement (P0-07 / P1-06).

---

## Checklist de déploiement

Ces points ne sont pas des défauts du code (les valeurs par défaut sont sûres), mais des **conditions obligatoires** de la configuration de production :

- [ ] `APP_ENV=production` et **`APP_DEBUG=false`** — avec debug actif, les erreurs 500 renvoient le message brut (`ApiErrorRenderer.php:56`), y compris le SQL d'une `QueryException`. Le code utilise `false` si la variable est absente (`config/app.php:42`), mais `.env.example` contient `true` (valeur de développement). Proposé : refuser de démarrer si `APP_ENV=production` et `APP_DEBUG=true`, et fournir un `.env.production.example`.
- [ ] `LOG_LEVEL=warning` (ou plus strict).
- [ ] `SESSION_SECURE_COOKIE=true` et `TRUSTED_PROXIES` renseigné (voir B2).
- [ ] `SANCTUM_STATEFUL_DOMAINS` et `CORS_ALLOWED_ORIGINS` renseignés avec les domaines exacts, jamais `*` (voir I3).
- [ ] En-têtes de sécurité du frontend sur le reverse proxy (voir B3).

---

## Points moyens

| # | Problème | Où | Correctif proposé | Statut |
|---|---|---|---|---|
| M1 | **Limite de connexion contournable** : la clé utilise le numéro tel que saisi (`34123456`, `3412-3456`, `+509…` = clés différentes) et combine téléphone + IP ; aucune limite par compte | `AppServiceProvider.php:85-88` | Normaliser le numéro dans le limiteur (helper partagé avec `LoginRequest`) ; ajouter une limite par compte indépendante de l'IP (ex. 30/heure) ou un verrouillage progressif | ⏳ Rubly |
| M2 | **Force brute TOTP sans plafond long** : 5 essais/min = 7 200/jour, ≈ 50 % de réussite en un mois pour qui a le mot de passe admin ; échecs non audités | `AppServiceProvider.php:96`, `MfaService.php:130-140` | Ajouter 20/heure et 50/jour ; déconnexion + alerte après N échecs ; auditer `mfa.failed` | ⏳ Rubly |
| M3 | **Contournement de la double validation par un Super Admin** : il crée un second compte, lui donne `finance` ou `super_admin`, et valide seul ses propres corrections | `RoleAssignmentService.php:31-58` | Attribution d'un rôle admin soumise à l'approbation d'un second `roles.manage`, ou au minimum délai + alerte à tous les Super Admins ; interdire de retirer le dernier `super_admin` | ⏳ Rubly + décision P0-06 |
| M4 | **Idempotence non atomique avec l'effet métier** : si une erreur survient après le commit, ou si le processus meurt, la clé est libérée et l'opération rejouée. Sans impact aujourd'hui, **double mise** possible sur le futur `POST /bets` | `RequireIdempotencyKey.php:52-69,101-104` | Enregistrer la réponse dans la même transaction que l'effet, ou dériver la `reference` du ledger de la clé (`bet:{user}:{key}`) | ⏳ Rubly, **avant P4-03** |
| M5 | **Données personnelles dans les logs** : les valeurs liées des requêtes SQL ne sont pas masquées dans les messages d'erreur (`QueryException.php:89`). Deux inscriptions simultanées avec le même numéro passent la règle `unique`, l'INSERT échoue, et nom, téléphone, email et hash du mot de passe sont écrits dans `laravel.log` (et renvoyés au client si `APP_DEBUG=true`) | `config/database.php:87-100` | `'mask_bindings_in_exception_messages' => true` sur la connexion `pgsql` | ⏳ proposé |

---

## Points faibles

| # | Problème | Où | Correctif | Statut |
|---|---|---|---|---|
| F1 | **Un admin sans email ne peut pas activer le MFA** (erreur 500) : `email` est devenu facultatif avec la connexion par téléphone, mais `Totp::provisioningUri()` exige une chaîne | `MfaService.php:54`, `Totp.php:64` | `$user->email ?? $user->phone` | ⏳ proposé |
| F2 | **`X-Request-Id` du client non validé** : au-delà de 64 caractères, PostgreSQL refuse l'insertion dans `audit_logs` → 500 et action annulée ; identifiant forgeable | `AuditLogger.php:60`, colonne `varchar(64)` | Ne garder qu'un identifiant sûr de 64 caractères maximum, sinon en générer un. À aligner avec P1-04 (PR #91), qui accepte jusqu'à 128 caractères | ⏳ proposé |
| F3 | **`confirm` du MFA ne change pas l'identifiant de session** (contrairement à `verify`) | `MfaService.php:77` | `$session->migrate(true)` avant le `put` | ⏳ Rubly |
| F4 | **Paramètre envoyé en tableau → 500** (`phone[]=x` : « Array to string conversion ») | `AppServiceProvider.php:85,99` | N'utiliser la valeur que si c'est une chaîne | ⏳ proposé |
| F5 | **Rejeu d'un code TOTP ou de secours en requêtes simultanées** (lecture puis écriture sans verrou) | `MfaService.php:130-155` | `lockForUpdate()` dans une transaction | ⏳ Rubly |
| F6 | **Codes de secours régénérables sans réauthentification ni audit** : une session admin volée obtient 8 codes durables | `routes.php:30`, `MfaService.php:100-103` | Exiger un code TOTP courant ; `throttle:mfa` ; audit | ⏳ Rubly |
| F7 | **Le motif interne d'une compensation est visible par le joueur** (« soupçon de fraude… ») | `LedgerService.php:92`, `LedgerEntryResource.php:25` | Motif dans `metadata`, libellé neutre dans `description` | ⏳ Rubly |
| F8 | **Double compensation simultanée → 500** au lieu d'un rejeu (la contrainte unique protège bien les données) | `LedgerService.php:80-83` | Vérifier sous verrou, ou intercepter la violation d'unicité | ⏳ Rubly |
| F9 | **Ledger et audit non protégés contre `TRUNCATE`** ; le rôle applicatif peut supprimer les déclencheurs | migrations `…000300` et `…000500` | Déclencheur `BEFORE TRUNCATE` ; en production, rôle de migration distinct et `REVOKE UPDATE, DELETE, TRUNCATE` | ⏳ Rubly + hébergement |
| F10 | **Données personnelles en clair dans `idempotency_keys.response_body`** (24 h) | `RequireIdempotencyKey.php:61-66` | Ne stocker qu'un corps minimal ou chiffrer la colonne | ⏳ Rubly |
| F11 | **Coupon et tickets du joueur précédent visibles sur un appareil partagé** (cybercafé) | `BetSlipContext.tsx:86-93`, `AdminLayout.tsx:66` | Vider `r16p.betslip` à la déconnexion ; lier le coupon à l'id du joueur | ⏳ Rubly |
| F12 | **Sessions longues et pas de limite de débit globale** : 120 min d'inactivité sans expiration absolue (y compris admins) ; aucune limite sur `/me`, `/wallet`, `/admin/*` ; pas de « déconnecter tous les appareils » ; événements de connexion non audités | `config/session.php`, `bootstrap/app.php`, `AuditAction.php` | Revalider le MFA après ~30 min pour les admins ; `throttleApi()` ; route de déconnexion globale ; auditer connexions et échecs | ⏳ à planifier |
| F13 | **Pas d'en-têtes de sécurité sur l'API, et version de PHP révélée** (`X-Powered-By: PHP/8.3.35`, vérifié par `curl`). L'API ne renvoie que du JSON : c'est une défense en profondeur, le vrai risque est sur le frontend (B3) | `bootstrap/app.php`, configuration PHP | Middleware `SecurityHeaders` (`nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Referrer-Policy: no-referrer`) ; `expose_php = Off` | ⏳ proposé |

## Pour information

- **I1 — Énumération des comptes à l'inscription :** les règles `unique` révèlent qu'un numéro ou un email existe (5 essais/min/IP). Risque à accepter et documenter, ou vérification par SMS.
- **I2 — Le rôle Support voit téléphone et email** sans `users.view_personal_data` (`AdminUserResource.php:25-26`), alors que la matrice parle de « données minimales ». À trancher dans la matrice des permissions (P0-06).
- **I3 — CORS/Sanctum configurables en `*` par variable d'environnement :** non exploitable seul, mais à interdire au démarrage en production ; `max_age=0` provoque une requête preflight à chaque appel (~600 conseillé).
- **I4 — Dépassement d'entier :** `LedgerService.php:138` peut basculer en float si le solde déborde. Non exploitable aujourd'hui ; le futur pari doit plafonner mise et gain.
- **I5 — URL d'API par défaut en `http://`** (`client.ts:8`) ; le build de production doit exiger `VITE_API_URL`. La lecture du cookie `XSRF-TOKEN` suppose que l'API et le frontend partagent le même domaine (à prévoir avec l'hébergement).
- **I6 — Google Fonts** chargé depuis Google (adresse IP des visiteurs transmise) : auto-héberger la police.
- **I7 — `docker-compose.yml`** publie PostgreSQL et Redis (sans mot de passe) sur toutes les interfaces : en local, préférer `127.0.0.1:5432:5432`. Ne rien reprendre de ce fichier pour le staging ou la production.
- **I8 — CI :** pas de bloc `permissions: contents: read` (le jeton GitHub garde ses droits par défaut) ; actions référencées par étiquette (`@v4`) et non par empreinte SHA.

---

## Ce qui a été vérifié et est correct

**Dépendances et secrets**
- `composer audit` et `npm audit` (dépendances de production et de développement) : aucune vulnérabilité connue.
- Aucun fichier `.env` ni aucune clé dans tout l'historique git, toutes branches confondues. Les chaînes hexadécimales trouvées sont des `content-hash` de `composer.lock` et un identifiant de test.
- La clé The Odds API reste dans `.env`, est masquée dans les messages d'erreur et n'est jamais enregistrée en base.

**CSRF, CORS, sessions**
- `statefulApi()` applique `EncryptCookies`, `StartSession`, la vérification CSRF et `AuthenticateSession` aux requêtes du frontend ; aucune route exclue du CSRF.
- Une requête d'une origine étrangère n'obtient aucune session (401) : pas de CSRF possible sur une route authentifiée.
- Cookies `HttpOnly` et `SameSite=Lax` ; sessions en base, sérialisation JSON.
- Nouvel identifiant de session à la connexion, à l'inscription et après `mfa/verify` ; déconnexion avec invalidation ; un compte suspendu perd immédiatement sa session.
- Réinitialisation de mot de passe : même réponse que le compte existe ou non, jeton à usage unique de 30 min, toutes les sessions fermées, lien construit sur `FRONTEND_URL` (pas d'empoisonnement par l'en-tête Host).
- Frontend : appel de `/sanctum/csrf-cookie` avant toute écriture, en-tête `X-XSRF-TOKEN`, nouvel essai unique sur 419 avec la même clé d'idempotence.

**Authentification, MFA, autorisations**
- Connexion sans énumération (hash factice, même erreur, statut révélé seulement après un mot de passe correct).
- Secret MFA de 160 bits chiffré en base, comparaisons à temps constant, anti-rejeu par pas de temps, codes de secours hachés et à usage unique ; MFA obligatoire pour tous les rôles admin.
- Chaque route `/admin` exige `auth:sanctum`, `active`, `mfa` et une permission, vérifiés **avant** la résolution des identifiants (403 sans sondage) — couvert par les tests de P2-07 (PR #93).
- Pas d'auto-modification de rôle ni de statut ; suspendre un admin exige `roles.manage` ; le portefeuille est lié à l'utilisateur connecté (pas d'IDOR).

**Injection, validation, données**
- Aucune saisie utilisateur interpolée dans du SQL ; recherche admin avec paramètres liés et caractères `%`, `_`, `\` échappés.
- Aucun appel système, aucun `unserialize`, aucun SSRF (URL du fournisseur fixée dans `.env`, segment de sport validé).
- `status` et rôles non assignables en masse ; validations par enum ; `per_page` plafonné à 100.
- Ledger : montants entiers, verrou sur le portefeuille, découvert refusé, référence unique, écriture seule garantie par le modèle et par déclencheur PostgreSQL.
- `password`, `remember_token`, `mfa_secret`, codes de secours jamais renvoyés par l'API ; erreurs 5xx génériques hors mode debug.

**Frontend**
- Aucun `dangerouslySetInnerHTML`, `innerHTML` ou `eval` ; toutes les données de l'API sont rendues en texte échappé ; aucun lien construit à partir de données de l'API.
- Aucun jeton ni donnée utilisateur dans `localStorage` (la session tient dans le cookie) ; redirection après connexion limitée aux chemins internes ; clés d'idempotence par `crypto.randomUUID()`.
- Liens externes en `noopener noreferrer` ; pas de `console.*` ; pas de source maps en production.

---

## Actions

| Qui | Quoi | Quand |
|---|---|---|
| Équipe | Valider ce rapport et les correctifs « ⏳ proposé » | Avant toute modification du code |
| Benginio | B2, M5, F1, F2, F4, F13 et le garde-fou `APP_DEBUG` de la checklist — PR de P8-02 | Après validation |
| Rubly | B1 (`POST /bets` réel) et M4 (idempotence atomique) | Avec P4-03 / P4-05, **avant tout pari** |
| Rubly | M1, M2, F3, F5, F6 (renforcement connexion et MFA) | Avant le lancement contrôlé |
| Rubly + Widsondelne7376 | M3 (double validation des rôles) et I2 (données visibles par le Support) | Décision P0-06 |
| Rubly | F7, F8, F9, F10, F11, F12 | À planifier |
| Hébergement (P0-07 / P1-06) | B3 (en-têtes du frontend), checklist de déploiement, HTTPS, rôle PostgreSQL restreint (F9) | Avant la mise en ligne du staging |
