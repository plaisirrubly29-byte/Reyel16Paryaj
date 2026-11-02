# Plan de tâches — REYEL 16 PARYAJ V2

> Répartition : **backend → Benginio Jean-Baptiste**, **frontend → Rubly PLAISIR**. Rubly termine aussi les mises à
> jour frontend de la V1. Quand une tâche a une partie backend et une partie écrans, chacun prend sa partie, et elle a
> deux issues GitHub liées entre elles. Les numéros d'issues (#137 à #198, label `version:V2`) sont à côté de chaque
> responsable.

## Objectif de la V2

La V1 est un site de paris **complet mais en argent simulé**. Le Super Admin crédite les soldes à la main, et il n'y a ni
paiement, ni KYC, ni Production. Le Staging tourne sur https://staging.reyel16paryaj.com depuis le 2026-11-02.

La V2 doit en faire **un vrai site de paris en argent réel**, sûr et utilisable sur téléphone en Haïti.

## Point de départ (analyse du 2026-11-02)

Cette analyse croise le site en ligne, vu comme un joueur, et un inventaire du code de `develop`.

| Domaine | État | Constat |
|---|---|---|
| Paiements | ❌ | Le module `Payments` est vide et `wallet.simulated = true`. Aucun dépôt ni retrait par MonCash ou NatCash. Le joueur doit « contacter l'opérateur ». |
| KYC | ❌ | L'âge est seulement déclaré à l'inscription. Aucune pièce d'identité, aucun code SMS, aucune vérification d'e-mail. |
| AML / fraude | ❌ | Aucune règle d'alerte, aucune détection de multi-comptes. |
| Jeu responsable | 🟡 | Les limites de mise, la pause et l'auto-exclusion existent. Il manque les limites de dépôt et de pertes, et les rappels de durée de session. |
| Offre de paris | 🟡 | Simple et combiné, Premier League seulement, 1X2 seulement en pratique. Pas de live, pas de marge opérateur, pas de modification des cotes par l'admin. |
| Site joueur | 🟡 | Le menu « Basketball » affiche du football. L'onglet Live est vide. Les filtres DC, +/- et LDEM sont affichés mais aucun match ne les propose. Le pays se tape à l'inscription. |
| Pages d'information | ❌ | `/conditions` et `/jeu-responsable` affichent « Page introuvable — À implémenter ». Pas de bannière cookies. |
| Langues | ❌ | Français seulement, écrit en dur dans le frontend. Aucun créole. |
| Notifications | ❌ | `MAIL_MAILER=log` : aucun e-mail ne part. Pas de SMS. La messagerie est « bientôt ». |
| Sécurité | 🟡 | Il reste 11 points ⏳ dans la revue P8-02. Les jetons Sanctum n'expirent jamais. Aucune limite de fréquence sur `POST /bets`. |
| Exploitation | 🟡 | Il n'existe qu'un Staging sur un VPS à 1 vCPU. La Production (D-10) n'est pas décidée. Les sauvegardes ne sont pas copiées hors du serveur. |
| Données sportives | 🟡 | The Odds API en plan gratuit (500 crédits par mois). L'accord écrit du fournisseur pour un usage commercial n'est pas reçu. |

**Priorité :**
- **P1** : nécessaire pour l'argent réel.
- **P2** : nécessaire pour un lancement crédible.
- **P3** : après le lancement.

**Colonnes Backend et Frontend :** « — » veut dire qu'il n'y a pas de travail de ce côté.

## Phase A — Décisions techniques et fournisseurs

**Critère de sortie :** fournisseurs choisis et comptes sandbox ouverts. Hébergement de la Production décidé.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-A01 | Accord écrit de The Odds API pour un usage commercial, et choix du plan payant | P1 | Benginio #137 | — | — | Accord archivé. Plan et budget de crédits choisis selon les sports de V2-D01. |
| V2-A02 | Choisir les prestataires de paiement : MonCash, NatCash, autres (D-08) | P1 | Benginio #138 | — | — | Comptes marchands ouverts. Accès API sandbox obtenus. Frais et délais notés. |
| V2-A03 | Hébergement de la Production, avec base managée et PITR (D-10) | P1 | Benginio #139 | — | — | Fournisseur et budget validés. D-10 passe de « Reportée » à « Validée ». |
| V2-A04 | Politique de cotes : marge de l'opérateur, arrondi, modification manuelle des cotes | P2 | Benginio #140 | — | — | Marge par sport ou marché décidée. Rôles autorisés à modifier une cote fixés. |
| V2-A05 | Langues du site : créole, français, anglais | P2 | — | Rubly #141 | — | Langues et langue par défaut décidées. Relecteur créole désigné. |

## Phase B — Argent réel : dépôts et retraits

**Critère de sortie :** un dépôt et un retrait réels passent de bout en bout, rapprochés avec le ledger, sans double
crédit.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-B01 | Payments Adapter : interface commune, webhooks signés et idempotents, journal des transactions | P1 | Benginio #142 | — | V2-A02 | Webhook rejoué sans double crédit. Signature vérifiée. Tests avec un faux prestataire. |
| V2-B02 | Dépôt MonCash (et NatCash si retenu) | P1 | Benginio #143 | — | V2-B01 | Dépôt sandbox crédité automatiquement dans le ledger. Échec et expiration gérés. |
| V2-B03 | Retraits : demande du joueur, validation admin à 4 yeux, versement, statuts | P1 | Benginio #144 | Rubly (écran admin de validation) #145 | V2-B01, V2-C03 | Aucun retrait sans KYC validé. Montant réservé pendant la demande. Annulation possible. Tout est audité. |
| V2-B04 | Passer en argent réel : `wallet.simulated=false`, rapprochement automatique prestataire ↔ ledger | P1 | Benginio #146 | — | V2-B02, V2-B03 | Rapport quotidien des écarts. Alerte si écart. Les crédits manuels restent tracés comme corrections. |
| V2-B05 | Page portefeuille : déposer, retirer, suivre ses transactions | P1 | — | Rubly #147 | V2-B02, V2-B03 | Parcours testés sur téléphone. Messages clairs pour chaque statut. |
| V2-B06 | Limites de dépôt, quotidienne, hebdomadaire et mensuelle, choisies par le joueur | P1 | Benginio #148 | Rubly (réglage dans l'espace joueur) #149 | V2-B02 | Même règle que les limites de mise : baisse immédiate, hausse après un délai. |

## Phase C — Identité, KYC, AML et sécurité

**Critère de sortie :** chaque compte qui retire de l'argent est vérifié. Les comportements suspects remontent à
l'admin.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-C01 | Vérification du téléphone par code SMS, à l'inscription et au changement de numéro | P1 | Benginio #150 | Rubly (saisie du code) #151 | — | Prestataire SMS branché. Code limité en essais et en durée. Numéro unique. |
| V2-C02 | Vrais e-mails (prestataire transactionnel) et vérification de l'adresse e-mail | P2 | Benginio #152 | Rubly (écran de confirmation) #153 | — | `MAIL_MAILER` réel en Staging et en Production. Mot de passe oublié reçu. Adresse confirmée. |
| V2-C03 | KYC : envoi de la pièce d'identité et d'un selfie, revue admin, statut du compte | P1 | Benginio #154 | Rubly (envoi des documents, écran de revue admin) #155 | — | Documents chiffrés et à accès restreint. Seuils configurables. Décision auditée. Retrait bloqué tant que le KYC n'est pas validé. |
| V2-C04 | AML : règles d'alerte et file de revue admin | P1 | Benginio #156 | Rubly (file d'alertes admin) #157 | V2-B02 | Exemples de règles : gros dépôt, dépôt suivi d'un retrait sans jeu, seuils cumulés. Alertes traitées et auditées. |
| V2-C05 | Détection des multi-comptes : même téléphone, appareil ou pièce d'identité | P2 | Benginio #158 | — | V2-C03 | Comptes liés signalés à l'admin. Aucun blocage automatique sans revue. |
| V2-C06 | Sessions : expiration des jetons Sanctum, déconnexion des autres appareils, limite de fréquence sur `POST /bets` | P1 | Benginio #159 | Rubly (liste des appareils connectés) #160 | — | Expiration configurée. Test de limite de fréquence. Liste des appareils connectés. |
| V2-C07 | Fermer les 11 points ⏳ de la revue de sécurité P8-02 (F1 à F11) | P1 | Benginio #161 | Rubly (points frontend, ex. F11 coupon sur appareil partagé) #162 | — | Chaque point corrigé ou accepté par écrit dans `docs/security/`. |
| V2-C08 | Revue de sécurité V2 des paiements, du KYC et des webhooks (relecture croisée) | P1 | — | Rubly (relecture) #163 | V2-B04, V2-C03 | Rapport écrit. Aucun point bloquant ouvert. |

## Phase D — Offre de paris et gestion du risque

**Critère de sortie :** les sports annoncés sur le site ont vraiment des matchs. Le risque est plafonné.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-D01 | Couverture : basketball (NBA), baseball (MLB) et plus de football (Ligue des champions, Liga, Serie A, Ligue 1…) | P1 | Benginio #164 | — | V2-A01 | Chaque sport du menu a des matchs. Un sport sans match n'est pas renvoyé par l'API. Budget de crédits respecté. |
| V2-D02 | Marchés en plus du 1X2 : plus/moins, double chance, les deux équipes marquent | P2 | Benginio #165 | Rubly (masquer les filtres vides) #166 | V2-A01 | Les filtres DC, +/- et LDEM affichent de vrais marchés, ou sont masqués quand ils sont vides. |
| V2-D03 | Marge de l'opérateur appliquée aux cotes, et modification manuelle des cotes par l'admin | P2 | Benginio #167 | Rubly (écran admin des cotes) #168 | V2-A04 | Marge configurable. Modification auditée. Cote modifiée signalée dans le catalogue. |
| V2-D04 | Saisie manuelle des résultats par l'admin quand le fournisseur échoue, avec règle du temps réglementaire | P1 | Benginio #169 | Rubly (écran admin de saisie) #170 | — | Résultat saisi à 4 yeux puis réglé. Règle du temps réglementaire testée (D-03). |
| V2-D05 | Risque : plafonds d'engagement par match et par marché, exposition des combinés, limites de mise par joueur | P1 | Benginio #171 | Rubly (écrans admin des plafonds) #172 | — | Pari refusé au-delà du plafond. Combinés inclus dans le pire cas. Limite individuelle posée par l'admin. |
| V2-D06 | Paris en direct (live) : cotes en direct, délai d'acceptation, suspension sur événement | P3 | Benginio #173 | Rubly (onglet Live, coupon en direct) #174 | V2-A01, V2-D05 | `allow_live` activé seulement après test de charge et budget de crédits validés. |
| V2-D07 | Cash-out (encaisser avant la fin) | P3 | Benginio #175 | Rubly (bouton et confirmation) #176 | V2-D06 | Montant calculé côté serveur. Cote revérifiée. Ledger cohérent. |

## Phase E — Expérience joueur

**Critère de sortie :** un joueur haïtien comprend le site dans sa langue et trouve les règles. Il est prévenu de ses
gains et de ses paiements.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-E01 | Pages d'information : conditions d'utilisation, confidentialité, règlement des paris, jeu responsable, case à cocher à l'inscription, bannière cookies | P1 | Benginio (enregistrer l'acceptation et sa version) #177 | Rubly #178 | — | Plus aucune « Page introuvable ». Acceptation enregistrée avec la version du texte. |
| V2-E02 | Traduction en créole (et en anglais si décidé) : bibliothèque i18n | P2 | Benginio (messages de l'API) #179 | Rubly #180 | V2-A05 | Aucun texte écrit en dur. Choix de langue mémorisé. Créole relu par un locuteur. |
| V2-E03 | Corrections du site : menu Basketball, onglet Live vide, pays en liste à l'inscription (Haïti par défaut) | P2 | — | Rubly #181 | — | Bugs constatés sur le Staging le 2026-11-02 corrigés, avec tests E2E. |
| V2-E04 | Notifications joueur : ticket gagnant, dépôt, retrait, KYC (e-mail ou SMS), et messagerie dans le site | P2 | Benginio #182 | Rubly (messagerie, choix des canaux) #183 | V2-C01, V2-C02 | Le joueur choisit ses canaux. La rubrique « Messagerie » n'est plus « bientôt ». |
| V2-E05 | Application installable (PWA) et site léger sur réseau mobile lent | P2 | — | Rubly #184 | — | Manifest et service worker. Score Lighthouse mobile ≥ 80. Test sur une connexion 3G simulée. |
| V2-E06 | Jeu responsable : limites de pertes, rappel de durée de session, historique de ses dépenses | P2 | Benginio #185 | Rubly #186 | V2-B06 | Rappel affiché à l'intervalle choisi. Limite de pertes bloquante. |
| V2-E07 | Fidélité, parrainage et bonus, avec règles anti-abus | P3 | Benginio #187 | Rubly #188 | V2-C05 | Conditions de mise affichées. Abus détectés. |

## Phase F — Production et exploitation

**Critère de sortie :** la Production est surveillée et sauvegardée hors site. Elle se déploie depuis `main` avec
retour arrière.

| ID | Tâche | Prio | Backend | Frontend | Dépend de | Terminé quand |
|---|---|---|---|---|---|---|
| V2-F01 | Environnement de Production : serveur, base managée, déploiement depuis `main` avec validation manuelle | P1 | Benginio #189 | — | V2-A03 | Workflow « Deploy Production ». Retour arrière testé. Secrets séparés du Staging. |
| V2-F02 | Sauvegardes hors site chiffrées et test de restauration sur un volume réaliste | P1 | Benginio #190 | — | V2-F01 | Restauration chronométrée et documentée. Alerte si une sauvegarde manque. |
| V2-F03 | Supervision : uptime externe, suivi des erreurs (Sentry ou équivalent), alertes Telegram en Production | P1 | Benginio #191 | — | V2-F01 | Une panne provoquée déclenche une alerte en moins de 5 minutes. |
| V2-F04 | Protection du site : Cloudflare ou équivalent, limites de fréquence globales, protection des formulaires contre les robots | P2 | Benginio #192 | Rubly (protection des formulaires) #193 | V2-F01 | Test de limite de fréquence. Inscription protégée contre les robots. |
| V2-F05 | Tableau de bord métier : inscriptions, dépôts, mises, marge (GGR), joueurs actifs | P2 | Benginio #194 | Rubly (écran admin) #195 | V2-B04 | Chiffres du jour et du mois dans l'admin, cohérents avec le ledger. |
| V2-F06 | Support client : canal WhatsApp ou tickets, procédure de réclamation et de litige | P2 | — | Rubly #196 | — | Procédure écrite. Lien visible sur le site. Délai de réponse fixé. |
| V2-F07 | Lancement V2 : bêta fermée en argent réel avec plafonds bas, puis ouverture | P1 | Benginio #197 | Rubly (vérification des parcours) #198 | toutes les P1 | Checklist signée. Bêta avec quelques joueurs invités. Bilan avant ouverture. |

## Répartition

| Membre | Rôle en V2 | Tâches | dont seul |
|---|---|---|---|
| **Benginio Jean-Baptiste** (@benginio) | Backend : API, paiements, KYC, données sportives, risque, Production | 34 | 12 |
| **Rubly PLAISIR** (@plaisirrubly29-byte) | Frontend : site joueur et écrans admin de la V2, en plus des mises à jour frontend de la V1 | 28 | 6 |

**Benginio seul (12) :** V2-A01, V2-A02, V2-A03, V2-A04, V2-B01, V2-B02, V2-B04, V2-C05, V2-D01, V2-F01, V2-F02,
V2-F03.

**Rubly seul (6) :** V2-A05, V2-B05, V2-C08 (relecture du code de Benginio), V2-E03, V2-E05, V2-F06.

**En binôme (22) :** Benginio fait l'API, Rubly fait les écrans. Rubly commence ses écrans dès que le contrat de l'API
est dans `docs/api/openapi.yaml`, sans attendre que le backend soit fini.

## Ordre conseillé

1. **Tout de suite, en parallèle :**
   - Benginio : V2-A01, V2-A02, V2-A03 (fournisseurs et hébergement), puis V2-C06 et V2-C07 (sécurité), V2-D04 et
     V2-D05 (résultats et risque).
   - Rubly : finir les mises à jour frontend de la V1, puis V2-E03 (bugs constatés) et V2-E01 (pages d'information).
2. **Dès que les prestataires sont choisis :** phase B et KYC (V2-C01, V2-C03, V2-C04). Benginio fait l'API, Rubly les
   écrans.
3. **Avant l'ouverture :** phase F, revue de sécurité V2 (V2-C08), puis bêta fermée (V2-F07).
4. **Après le lancement :** les tâches P3 (live, cash-out, bonus).

**Total :** 40 tâches, dont 23 en P1, 14 en P2 et 3 en P3.
