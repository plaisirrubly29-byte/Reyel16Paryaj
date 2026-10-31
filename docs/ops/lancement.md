# Lancement — checklist, plan de rollback et recette (P9-01)

| | |
|---|---|
| **Tâche** | P9-01 — Checklist de lancement et plan de rollback (ticket #56) |
| **Critère** | Checklist **signée** (§7), procès-verbal de recette (§5), limites connues (§6) |
| **Dépend de** | P8-01 (E2E), P8-02 (sécurité), P8-04 (sauvegardes) |
| **Liés** | [Runbook d'incident](runbook-incident.md) · [Sauvegarde et restauration](sauvegarde-restauration.md) · [Monitoring et alertes](monitoring-alertes.md) · [Revue de sécurité](../security/P8-02-revue-securite.md) |

Ce document décide si l'on peut ouvrir la plateforme au public, et comment revenir en arrière. Il se remplit
**sur l'environnement visé** (Staging pour la répétition générale, puis Production), une ligne après l'autre :
chaque case cochée porte les initiales et la date de la personne qui l'a vérifiée.

Le lancement V1 est un **lancement en fonds fictifs** (démonstration). Toute activité en argent réel exige en plus
la validation juridique écrite (§1, G1) et le prestataire de paiement (P7-01), aujourd'hui bloqués.

## 1. Prérequis bloquants (go / no-go)

Un seul « non » = **pas de lancement**.

| # | Condition | Où en est-on (30 oct. 2026) | Responsable | OK |
|---|---|---|---|---|
| G1 | Cadre juridique validé par écrit (territoire, âge, KYC, données) — D-01/D-09 | 🟡 proposition, avis d'un conseil juridique à obtenir. **Indispensable pour l'argent réel** ; pour la démo en fonds fictifs, accord écrit du chef de projet | Rubly (décision), Benginio (dossier) | [ ] |
| G2 | Hébergement choisi et budgété — D-10 | 🟡 proposition à valider (P0-07) | Rubly (décision), Benginio | [ ] |
| G3 | Toutes les PR du lancement fusionnées et CI verte sur `develop` puis `main` | ⏳ #120, #121, #122 (admin), #123 (Staging), #124 (E2E), #130 (charge), #131 (monitoring) en relecture | Rubly (relecture), Benginio | [ ] |
| G4 | Revue de sécurité : aucun point bloquant ouvert | ⏳ **B3** : en-têtes du site (Content-Security-Policy, `frame-ancestors`) à ajouter au Caddyfile (#123) | Benginio | [ ] |
| G5 | Revue de sécurité : points faibles acceptés ou corrigés par écrit | ⏳ F3, F5 à F12 (Rubly), F9 (Rubly + hébergement) | Rubly | [ ] |
| G6 | Staging en service en HTTPS, E2E (P8-01) réussi **sur le Staging** | ⏳ serveur à installer (P1-06 §3) | Benginio | [ ] |
| G7 | Restauration testée **sur le serveur** dans le délai visé (RTO 4 h) | ✅ testée en local (P8-04, 29 oct.) ; ⏳ à refaire sur le serveur | Benginio | [ ] |
| G8 | Alertes testées et astreinte acceptée | ⏳ `monitoring:check --test` sur le serveur ; règle d'astreinte à valider (P9-02) | Benginio, Rubly | [ ] |
| G9 | Capacité suffisante pour l'affluence attendue | ✅ mesurée (P8-03) : paris 30/s ; catalogue ~480 visiteurs simultanés sur 10 cœurs, ~150 à 200 sur 4 vCPU | Rubly (accepter la limite) | [ ] |
| G10 | Procès-verbal de recette signé, sans anomalie bloquante (§5) | ⏳ | Rubly, Benginio | [ ] |

## 2. Checklist de mise en service

### J-7 — Préparation

| Étape | Vérification | Qui | OK |
|---|---|---|---|
| Serveur | VPS dimensionné selon G9 ; Docker ; utilisateur `deploy` ; pare-feu 22/80/443 seulement ; mises à jour de sécurité | Benginio | [ ] |
| DNS et HTTPS | Enregistrements A/AAAA ; certificat Let's Encrypt obtenu par Caddy ; `https://<domaine>/api/v1/health` répond | Benginio | [ ] |
| Base de données | PostgreSQL managé avec restauration à un instant donné (D-10) ou volume sauvegardé ; rôle de migration distinct (F9) | Benginio | [ ] |
| `.env` | `chmod 600`, jamais dans Git ; valeurs ci-dessous vérifiées une par une | Benginio | [ ] |
| GitHub | Environnement de déploiement, secrets SSH, empreinte du serveur, variable d'activation (P1-06 §3.2) | Benginio | [ ] |
| Sauvegardes hors site | Dump quotidien chiffré vers un stockage objet d'un autre fournisseur (P8-04 §2) | Benginio | [ ] |
| Surveillance externe | healthchecks.io et UptimeRobot branchés sur le groupe Telegram d'astreinte (P9-02 §2.3) | Benginio | [ ] |

**Valeurs du `.env` à vérifier** (P8-02 « Checklist de déploiement », P1-06, P8-03, P9-02) :

- [ ] `APP_ENV=production`, **`APP_DEBUG=false`**, `APP_KEY` propre à l'environnement, `LOG_LEVEL=warning`
- [ ] `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`, `APP_URL` : domaine exact, jamais `*`
- [ ] `TRUSTED_PROXIES` vide si Caddy transmet en FastCGI (P1-06), sinon l'IP exacte du proxy (P8-02 B2)
- [ ] Mots de passe base et Redis générés (`openssl rand -hex 32`), différents du Staging
- [ ] `DB_PERSISTENT=true`, `CATALOGUE_CACHE_SECONDS=10`, `PHP_FPM_MAX_CHILDREN` ≈ 2 par vCPU (P8-03)
- [ ] `SPORTS_DATA_PROVIDER` et sa clé selon D-02 ; quota suffisant pour le mois
- [ ] `betting.allow_live` désactivé (paris en direct exclus de la V1, P8-02 E1)
- [ ] `MONITORING_*` renseignés, mailer SMTP réel (P9-02)
- [ ] `WALLET_WELCOME_CREDIT_MINOR` conforme à la démo (fonds fictifs)

### J-1 — Répétition générale sur le Staging

| Étape | Vérification | Qui | OK |
|---|---|---|---|
| Déploiement | Déploiement automatique de la version candidate réussi (contrôles de santé verts) | Benginio | [ ] |
| Retour arrière | `staging-deploy.sh rollback` puis redéploiement : chronométrés, < 10 min | Benginio | [ ] |
| E2E | Parcours complet (P8-01) contre le Staging : réussi | Benginio | [ ] |
| Charge | `load/` (P8-03) contre le Staging : résultats notés, comparés à G9 | Benginio | [ ] |
| Restauration | Restauration d'une sauvegarde dans une base neuve, vérifiée (`verify.sh`), temps noté (G7) | Benginio | [ ] |
| Alertes | `monitoring:check --test` reçu sur Telegram **et** par e-mail par les deux personnes d'astreinte | Benginio, Rubly | [ ] |
| Recette | Procès-verbal (§5) rempli et signé | Rubly, Benginio | [ ] |
| Go / no-go | Toutes les lignes du §1 cochées ; décision écrite dans le groupe d'astreinte | Rubly | [ ] |

### Jour J — Ouverture

| Heure | Action | Qui | OK |
|---|---|---|---|
| H-2 | Gel des fusions sur `main` ; sauvegarde complète de la base, empreinte vérifiée | Benginio | [ ] |
| H-1 | Déploiement de la version validée en Production ; contrôles de santé ; migrations OK | Benginio | [ ] |
| H-1 | Premier Super Admin (`identity:grant-role`), MFA activé ; comptes de démonstration **absents** | Benginio | [ ] |
| H-1 | Synchronisation du catalogue ; matchs et cotes visibles ; marchés ouverts | Benginio | [ ] |
| H-30 min | Parcours manuel : inscription, pari, consultation du ticket, solde ; console admin (Vue d'ensemble, Système) | Rubly | [ ] |
| H | Ouverture (annonce) ; astreinte active ; écran Système ouvert | Rubly | [ ] |
| H+1 | Aucune alerte critique ; taux d'erreurs API normal ; rapprochement sans écart | Benginio | [ ] |

### J+1 à J+7 — Surveillance renforcée

- [ ] Chaque jour : rapprochement sans écart, tickets réglés dans les délais, sauvegarde du jour présente et vérifiée.
- [ ] Revue des alertes reçues : faux positifs → seuils ajustés (P9-02 §4) ; vrais incidents → post-mortem (runbook §5).
- [ ] J+7 : bilan de lancement (fréquentation réelle comparée à G9, incidents, limites à lever).

## 3. Plan de rollback

**Qui décide :** le responsable d'incident (runbook §2), après avis de l'autre personne d'astreinte si elle est joignable
en moins de 15 minutes. **Toute décision et toute commande sont notées** dans le journal d'incident.

### 3.1 Quand revenir en arrière

| Signal (après un déploiement ou à l'ouverture) | Décision |
|---|---|
| Alerte « Erreurs de l'API » ou « Travailleur de file arrêté » liée au déploiement | Retour à la version précédente (niveau 1) |
| Parcours de pari ou de connexion impossible pour tous | Niveau 1, puis diagnostic |
| **Écart de rapprochement**, pari accepté à tort, montant faux | **Maintenance immédiate** (niveau 2), puis niveau 1 ou 3 selon l'analyse |
| Données corrompues ou perdues par une migration | Niveau 3 (restauration), sur décision explicite |
| Problème limité à une fonction (un marché, un écran) | Pas de retour arrière : suspension ciblée (niveau 2) et correctif |

### 3.2 Niveaux de retour arrière

| Niveau | Action | Comment | Durée visée |
|---|---|---|---|
| **1 — Application** | Remettre les images de la version précédente | Automatique si le déploiement échoue ; sinon `staging-deploy.sh rollback` sur le serveur, ou redéploiement d'un tag `sha-…` déjà publié (P1-06 §4). **Les migrations ne sont pas défaites** : elles doivent rester compatibles avec la version précédente (ajouter avant de supprimer, en deux déploiements) | < 10 min |
| **2 — Fonction** | Couper sans revenir en arrière | Maintenance : `php artisan down --secret=<jeton>` (l'équipe garde l'accès) ; suspension de marchés (écran Événements, motif) ; paramètres versionnés (P6-08 : combinés, direct, plafonds) ; arrêt de `queue` et `scheduler` si l'argent est en jeu | < 5 min |
| **3 — Données** | Restaurer la base | Sauvegarde prise avant la migration (déploiement) ou PITR du fournisseur ; restauration **dans une base neuve**, vérification (`verify.sh`), puis bascule (P8-04 §4). Les écritures faites depuis la sauvegarde sont perdues : à reconstituer depuis le journal d'audit et le ledger, jamais à la main | < 4 h (RTO) |

### 3.3 Après un retour arrière

1. Vérifier : `/api/v1/health`, connexion, un pari (Staging) ou un ticket existant (Production), rapprochement.
2. Lever la maintenance (`php artisan up`), relancer `queue` et `scheduler`.
3. Informer les joueurs (bannière, réseaux) si l'incident était visible.
4. Post-mortem sous 5 jours ouvrés (runbook §5) ; la version fautive ne repart qu'avec un correctif et un test.

## 4. Communication

| Moment | Message | Canal | Qui |
|---|---|---|---|
| J-1 | Date et heure d'ouverture ; rappel « fonds fictifs, 18+ » | Réseaux | Rubly |
| Incident visible | Service perturbé, en cours de traitement, prochaine information à H+x | Bannière, réseaux | Responsable d'incident |
| Fin d'incident | Service rétabli ; ce qui a été fait ; aucune perte de fonds (fictifs) | Bannière, réseaux | Responsable d'incident |

## 5. Procès-verbal de recette

À remplir sur le Staging (répétition générale), avec la version candidate exacte (tag `sha-…`).

| Version testée | Date | Environnement | Testeurs |
|---|---|---|---|
| `sha-…` | | Staging | |

| # | Scénario | Résultat attendu | Référence | Testé par | OK / anomalie |
|---|---|---|---|---|---|
| R1 | Inscription (18+), connexion, déconnexion, mot de passe oublié | Compte créé, crédit de bienvenue fictif, session fermée | P2-02, P2-03 | | |
| R2 | Catalogue : sports, matchs, cotes, marchés d'un match | Seuls les marchés ouverts et à jour sont affichés | P3-05, P3-06 | | |
| R3 | Pari simple et combiné ; mise hors limites refusée | Ticket créé, solde débité ; message clair si refus | P4-03, D-06 | | |
| R4 | Cote modifiée entre l'affichage et le pari | Confirmation demandée, aucun ticket à l'ancienne cote | P4-05 | | |
| R5 | Résultat et règlement ; « Mes paris » et solde | Ticket gagné/perdu/remboursé, gain crédité une seule fois | P5-05, P8-01 | | |
| R6 | Report de plus de 48 h | Sélection annulée selon D-05 | D-05 | | |
| R7 | Jeu responsable : limite de mise, pause, auto-exclusion | Pari refusé au-delà de la limite ; connexion bloquée pendant l'exclusion | P7-02 | | |
| R8 | Admin : connexion avec MFA ; rôle sans permission refusé | 403 sur toute page non autorisée | P2-05, P6-09 | | |
| R9 | Correction de résultat à double validation | Le demandeur ne peut pas valider ; réévaluation des tickets après validation | P5-06 | | |
| R10 | Correction financière à double validation ; rapprochement | Écriture compensatoire ; aucun écart | P6-04, P6-08 | | |
| R11 | Suspension d'un marché, d'un compte (motif) ; journal d'audit et export | Action journalisée avec motif ; export CSV journalisé | P6-06, P6-08 | | |
| R12 | Alertes : test des canaux, une alerte réelle provoquée et résolue | Message reçu puis « Résolu » | P9-02 | | |
| R13 | Déploiement, retour arrière, restauration | Durées notées, conformes au §3.2 | P1-06, P8-04 | | |

Anomalies : **bloquante** (empêche le lancement), **majeure** (contournement nécessaire, correctif avant J+7), **mineure**
(liste des limites connues). Toute anomalie est consignée dans un ticket GitHub référencé ici.

## 6. Limites connues de la V1

| Limite | Conséquence | Suite prévue |
|---|---|---|
| **Fonds fictifs uniquement** | Aucun dépôt ni retrait ; aucun gain réel | Argent réel après G1 et P7-01 |
| Paiements (P7-01) bloqués | Pas de prestataire intégré | Après validation juridique écrite |
| Paris en direct désactivés | Matchs en cours visibles, non pariables | Décision d'équipe, cotes de quelques secondes requises (P8-02 E1) |
| Capacité (P8-03) | Catalogue : objectif de 200 ms tenu jusqu'à ~480 visiteurs simultanés sur 10 cœurs (~150 à 200 sur 4 vCPU) ; paris : 30/s | Cache HTTP devant PHP, Laravel Octane ou plus de serveurs (tâche à créer) |
| Cotes du catalogue mises en cache 10 s | Une cote affichée peut avoir 10 s de retard ; le pari la revérifie toujours | — |
| Un seul serveur applicatif | Disponibilité visée 99,5 %/mois (≈ 3 h 40 d'arrêt possible) | 2 serveurs et un répartiteur pour 99,9 % (D-10) |
| Règlement relancé seulement par commande serveur | Pas de bouton d'administration (D-07 : ligne « — ») | — |
| Points faibles de sécurité ouverts (P8-02) | F3, F5 à F12 : défense en profondeur, sans faille bloquante connue | Correctifs planifiés (G5) |
| Quota du fournisseur de données (D-02) | Synchronisation limitée par les crédits ; alerte sous la réserve | Offre payante si l'audience le justifie |

## 7. Signatures

La checklist (§1 et §2) et le procès-verbal (§5) sont validés par :

| Rôle | Nom | Date | Décision (go / no-go) | Signature |
|---|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | | |
| Développement et infrastructure | Benginio Jean-Baptiste | | | |
| Conseil juridique (**argent réel seulement**) | | | | |
