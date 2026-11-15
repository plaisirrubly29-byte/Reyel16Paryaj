# D-17 — Protection du site : bouclier réseau, robots et limites de fréquence

| | |
|---|---|
| **Tâche** | V2-F04 (#192 backend, #193 écrans) — Cloudflare ou équivalent, limites de fréquence globales, protection des formulaires contre les robots |
| **Statut** | 🟡 À décider — comparatif et recommandation proposés |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-13 |
| **Dépend de** | V2-F01 (Production, #189), D-10 (hébergeur et DNS), revue P8-02 (B2 proxys de confiance, F12 limite par compte, #259) |

> Les fonctions et les prix viennent des pages publiques et de comparatifs (novembre 2026) : **à confirmer sur les
> pages officielles avant la décision.** Aucun compte n'est ouvert, aucun DNS n'est modifié et aucun code n'est écrit
> tant que D-17 n'est pas validée.

## 1. Besoin

Critères de #192 : **« Test de limite de fréquence »** et **« Inscription protégée contre les robots »**.

| Menace | Exemple | Conséquence |
|---|---|---|
| Saturation (DDoS) | Des milliers de requêtes par seconde | Site indisponible pour tous |
| Robots sur les formulaires | Inscriptions en masse pour les crédits de bienvenue ; essais de mots de passe ; mot de passe oublié en boucle | Faux comptes, comptes volés, e-mails envoyés pour rien |
| Abus d'un visiteur | Un script interroge l'API en boucle sans se connecter | Charge du serveur et du fournisseur de cotes |
| Fraude aux codes (après V2-C01) | Des robots déclenchent des SMS en masse | Facture de SMS (D-15, §6) |

## 2. Déjà en place

| Protection | Où |
|---|---|
| Limites par route : connexion (par téléphone, par IP, par compte), inscription (5/min par IP), MFA, mot de passe oublié, paris (10/min), dépôts, catalogue (120/min par IP), webhooks | `AppServiceProvider` |
| Limite globale **par compte connecté** : 120 requêtes/min sur toute l'API | F12, #259 (en relecture) |
| Audit des connexions et alerte d'échecs répétés | F12, #259 |
| En-têtes de sécurité (CSP, HSTS, anti-iframe) | Caddy, revue P8-02 B3 |
| Proxys de confiance déclarés (`TRUSTED_PROXIES`), jamais `*` | Revue P8-02 B2 |

**Manquent** : un bouclier réseau, une protection des formulaires contre les robots, et une limite globale pour les
**visiteurs non connectés** sur toutes les routes.

## 3. Bouclier réseau

| | Cloudflare gratuit | Cloudflare Pro (~20–25 $/mois) | Sans bouclier |
|---|---|---|---|
| Protection contre la saturation | ✅ Illimitée | ✅ | Seulement celle de l'hébergeur |
| Pare-feu applicatif (WAF) | Règles de base gérées | Règles plus complètes | ❌ |
| Blocage des robots connus | ✅ | ✅ Plus fin | ❌ |
| Limite de fréquence au niveau du réseau | **Incertaine en gratuit** (sources contradictoires) | ✅ | Seulement Laravel |
| Présence en Haïti | Centre annoncé à Port-au-Prince (2021), **fonctionnement actuel à vérifier** | Idem | — |

## 4. Protection des formulaires contre les robots

| Outil | Gratuit | Pour le joueur | Données personnelles |
|---|---|---|---|
| **Cloudflare Turnstile** | ~1 million de vérifications par mois (limite pas toujours publiée) | Le plus souvent **invisible**, sans image à reconnaître | Le plus respectueux d'après les comparatifs |
| **hCaptcha** | 100 000 par mois | Images à reconnaître en gratuit ; invisible en payant (99 $/mois) | Correct |
| **Google reCAPTCHA** | **10 000 par mois** depuis avril 2026 | Souvent invisible (v3) | Données utilisées par Google |

**Turnstile fonctionne sans Cloudflare devant le site** : les deux choix sont indépendants.

## 5. Conséquences si Cloudflare est placé devant le site

1. **Vraie adresse IP du joueur** : derrière Cloudflare, Laravel ne voit que les adresses de Cloudflare. Toutes les
   limites par IP, l'audit des connexions et les alertes seraient faussés (même problème que B2). Il faut déclarer les
   plages d'adresses publiées par Cloudflare comme proxys de confiance, dans Caddy et dans `TRUSTED_PROXIES`, et les
   tenir à jour. **Le serveur ne doit plus accepter de connexion directe** en dehors de Cloudflare (pare-feu), sinon
   un attaquant contourne le bouclier.
2. **HTTPS** : Caddy et Let's Encrypt restent compatibles, en mode « Full (strict) ».
3. **DNS** : le domaine est géré chez Cloudflare. **À décider avant la mise en place de la Production**, car le DNS
   doit pointer vers le serveur avant le premier déploiement (D-10).
4. **Cloudflare R2** est déjà proposé pour les sauvegardes hors site (D-10, H-5) : un seul compte pour les deux.
5. **Dépendance** : une panne de Cloudflare rend le site injoignable ; la surveillance externe (D-16) le verrait.

## 6. Recommandation

1. **Cloudflare gratuit** devant le Staging puis la Production : saturation, pare-feu de base, robots connus. Pro
   seulement si la limite au niveau du réseau n'est pas incluse en gratuit et s'avère nécessaire.
2. **Turnstile** sur l'**inscription**, le **mot de passe oublié**, et la **connexion après 3 échecs** pour le même
   compte ou la même IP. Invisible pour la plupart des joueurs, gratuit. Backend : vérification par un appel HTTP à
   Cloudflare, **sans nouveau paquet**. Écrans (#193) : composant ajouté aux formulaires.
3. **Limite globale pour les visiteurs non connectés** : 300 requêtes/min par IP sur toute l'API, valeur large à
   cause des cybercafés ; en plus des limites par route et de la limite par compte (#259).
4. **Plus tard, avec V2-C01** : Turnstile aussi avant l'envoi d'un code SMS (fraude aux SMS, D-15).

Coût : **0 $** avec Cloudflare gratuit et Turnstile ; ~20–25 $/mois si Pro devient nécessaire.

## 7. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| PS-1 | Bouclier réseau | Cloudflare gratuit, Staging puis Production | |
| PS-2 | DNS du domaine | Géré chez Cloudflare, choisi avant la mise en place de la Production | |
| PS-3 | Protection contre les robots | Cloudflare Turnstile | |
| PS-4 | Formulaires protégés | Inscription, mot de passe oublié, connexion après 3 échecs ; envoi d'un code SMS plus tard | |
| PS-5 | Limite globale des visiteurs | 300 requêtes/min par IP sur toute l'API | |
| PS-6 | Accès direct au serveur | Refusé par le pare-feu, sauf depuis Cloudflare (et SSH pour le déploiement) | |
| PS-7 | Si Turnstile est indisponible | Inscription refusée avec un message clair (sécurité d'abord), alerte d'exploitation | |
| PS-8 | Cloudflare Pro | Non au lancement ; à revoir si une attaque ou un besoin de limite réseau apparaît | |

## 8. Questions à confirmer

| # | Question | Réponse |
|---|---|---|
| PSJ-1 | Limite de fréquence et nombre de règles du pare-feu inclus dans l'offre gratuite de Cloudflare | |
| PSJ-2 | Le centre Cloudflare de Port-au-Prince est-il actif ; temps de réponse depuis Digicel et Natcom | |
| PSJ-3 | Limite d'usage gratuite de Turnstile, et données collectées sur les joueurs (J-9) | |

## 9. Conséquences techniques

- **Backend (Benginio)** : vérification Turnstile (appel HTTP, clé secrète dans le `.env` du serveur) sur les routes
  choisies, désactivable en développement et en tests ; limite globale par IP pour les visiteurs (limiteur Laravel) ;
  proxys de confiance de Cloudflare ; nouvelle erreur `422 captcha_failed` dans `docs/api/openapi.yaml` (contrat
  d'abord) ; tests de la limite et de la vérification.
- **Frontend (Rubly, #193)** : composant Turnstile sur les formulaires choisis ; message en cas d'échec.
- **Exploitation** : compte Cloudflare au nom de l'équipe, deux administrateurs, MFA ; DNS ; pare-feu du serveur ;
  mise à jour de `docs/ops/deploiement-production.md` et `deploiement-staging.md`.
- **Recette** : test de la limite des visiteurs (critère 1) ; inscription refusée sans jeton Turnstile valide
  (critère 2) ; vérification que l'IP réelle du joueur apparaît bien dans l'audit derrière Cloudflare.

## 10. Conditions pour passer à « Validée »

1. PS-1 à PS-8 tranchés par écrit.
2. Fonctions de l'offre gratuite confirmées (PSJ-1) et données de Turnstile connues (PSJ-3).
3. Choix du DNS fait avant la mise en place de la Production (PS-2).

## 11. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |

## Sources (consultées le 13 novembre 2026)

- Cloudflare : [offre gratuite 2026 (Costbench)](https://costbench.com/software/cdn-edge/cloudflare/free-plan/),
  [gratuit, Pro, Business (Eastondev)](https://eastondev.com/blog/en/posts/dev/20251201-cloudflare-pricing-compare/),
  [guide de l'offre gratuite (Freetier)](https://freetier.co/cloudflare-free-tier),
  [avis 2026](https://infiniteagent.pages.dev/blog/cloudflare-review-2026/),
  [réseau mondial](https://www.cloudflare.com/en-us/network/),
  [extension du réseau 2021 (Port-au-Prince)](https://www.cloudflare.com/press/press-releases/2021/cloudflare-grows-its-network-to-speed-up-and-secure-the-internet/),
  [Cloudflare Radar — Haïti](https://radar.cloudflare.com/quality/ht)
- Protection contre les robots : [Turnstile vs reCAPTCHA](https://www.phpcaptcha.org/cloudflare-turnstile-vs-recaptcha),
  [hCaptcha vs Turnstile](https://www.phpcaptcha.org/hcaptcha-vs-turnstile),
  [prix de reCAPTCHA 2026](https://www.phpcaptcha.org/recaptcha-pricing),
  [avis hCaptcha 2026](https://www.phpcaptcha.org/hcaptcha-review),
  [comparatif 2026 (PkgPulse)](https://www.pkgpulse.com/guides/turnstile-vs-recaptcha-vs-hcaptcha-captcha-bot-2026)
