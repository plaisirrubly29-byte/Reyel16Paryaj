# D-13 — Agents : rôle, portefeuille, documents et limites

| | |
|---|---|
| **Tâche** | V2-G01 (débloque #226 backend, #227 écrans) et V2-G02 (#228 commissions, #229 écrans) |
| **Statut** | 🟡 À décider — options et recommandation proposées |
| **Décideur** | Rubly PLAISIR (chef de projet) ; avis écrit d'un conseil juridique pour les options qui font manier de l'argent à l'agent |
| **Préparé le** | 2026-11-11 |
| **Dépend de** | D-01/D-09 (cadre juridique, KYC), D-06 (devise, plafonds), D-07 (rôles admin), D-08 (paiements), V2-G03 (rôle « Gestion des agents », #230) |

> Ce document n'est pas un avis juridique. Il pose les options, recommande un ordre et liste ce que Rubly doit
> trancher. **Aucun mouvement d'argent par un agent tant que D-13 n'est pas validée** (#226).

## 1. Besoin

La feuille de route (§6) prévoit des **agents** : des personnes ou commerces qui développent l'activité sur le terrain,
avec un tableau de bord (solde, activité, transactions, commissions, réclamations) et des commissions calculées
automatiquement (V2-G02, seuil commercial indicatif de 3 000 USD, configurable). Le rôle exact d'un agent n'est pas
encore défini : c'est l'objet de cette décision.

## 2. Déjà en place

- **Ledger en ajout seul** (`Wallet`) : chaque mouvement est une écriture typée (`simulated_credit`, `deposit`,
  `withdrawal`, `stake`, `payout`, `refund`, `reversal`), avec clé d'idempotence. Aucun solde n'est modifié
  directement.
- **Dépôt ou retrait reçu hors plateforme** : un Super Admin peut créditer ou débiter un joueur avec motif
  (`wallet.adjust`). C'est aujourd'hui le seul chemin d'argent « au comptoir ».
- **V1 en fonds fictifs** (D-01) : aucun argent réel avant la licence écrite.
- **KYC** (V2-C03, #216), **AML** (V2-C04, #221) et **réclamations** (V2-G04, #232) en cours : un agent pourra s'y
  rattacher.
- **Rôles admin** : 5 rôles (D-07). Le rôle « Gestion des agents » arrive avec V2-G03 (#230).

## 3. Options pour le rôle de l'agent

Les trois options ne s'excluent pas : on peut commencer par l'une et ajouter les autres plus tard.

| | **A. Parrain (apporteur)** | **B. Agent d'encaissement** | **C. Point de vente (comptoir)** |
|---|---|---|---|
| Ce qu'il fait | Fait inscrire des joueurs avec son code ; touche une commission sur leur activité | Reçoit l'argent liquide d'un joueur et crédite son compte ; paie ses retraits en liquide | Vend des tickets au comptoir à des clients, avec ou sans compte ; paie les gains |
| Argent manié par l'agent | **Aucun** | Oui (dépôts et retraits des joueurs) | Oui (mises et gains des clients) |
| Portefeuille de l'agent | Portefeuille de **commissions** seulement | Portefeuille **prépayé** (« float ») débité à chaque dépôt joueur, plus commissions | Portefeuille prépayé débité à chaque ticket vendu, plus commissions |
| Lien avec les joueurs | Joueur rattaché à l'agent à l'inscription | Opération par opération (et rattachement éventuel) | Ticket rattaché à l'agent ; client souvent anonyme |
| Exigences juridiques | Faibles (contrat d'apporteur, fiscalité des commissions) | Fortes : agrément de l'agent, KYC des joueurs, AML, rapprochement des espèces | Les plus fortes : point de vente agréé, ticket anonyme, paiement des gains |
| Risques | Faux comptes pour gonfler les commissions | Détournement d'espèces, dépôts fictifs, blanchiment | Les mêmes que B, plus les tickets contestés au comptoir |
| Possible en fonds fictifs (V1) | **Oui** | Non (argent réel) | Non (argent réel) |
| Effort technique | Faible : code agent, rattachement, commissions | Moyen : float, opérations agent, rapprochement | Élevé : vente pour un tiers, ticket imprimé, paiement au comptoir |

## 4. Recommandation

1. **Maintenant : option A (parrain) seule.** Elle ne fait manier aucun argent à l'agent, fonctionne en fonds
   fictifs et suffit pour livrer #226 (profils, documents, tableau de bord) et #228 (commissions).
2. **Après la licence et D-08 : option B**, si le conseil juridique l'autorise. Elle remplace l'ajustement manuel
   `wallet.adjust` par un circuit tracé : float prépayé, plafonds, rapprochement quotidien.
3. **Option C reportée** : elle demande un point de vente agréé et la vente de tickets anonymes, que rien ne prévoit
   aujourd'hui.

## 5. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| AG-1 | Rôle de l'agent au lancement | Option A (parrain) seule | |
| AG-2 | Un agent est-il une personne, un commerce, ou les deux ? | Les deux : type `individual` ou `business` | |
| AG-3 | Comment l'agent se connecte-t-il ? | Compte à part, ni joueur ni admin, avec MFA obligatoire ; un agent ne peut pas parier depuis ce compte | |
| AG-4 | Rattachement d'un joueur | Par le code de l'agent saisi à l'inscription, ou par un admin « Gestion des agents » avec motif ; un seul agent par joueur ; changement uniquement par un admin avec motif audité | |
| AG-5 | Durée du rattachement | Commissions versées pendant 12 mois après l'inscription du joueur (configurable) | |
| AG-6 | Portefeuille de l'agent | Portefeuille de commissions seulement (option A), alimenté par des écritures du ledger ; pas de float | |
| AG-7 | Comment l'agent touche ses commissions | Demande de retrait de commissions, validée par la Finance (comme un retrait joueur, B03) ; en V1, montants fictifs | |
| AG-8 | Documents exigés | Personne : pièce d'identité, NIF, justificatif d'adresse, contrat d'agent signé. Commerce : en plus patente ou enregistrement, NIF de l'entreprise, pièce du représentant | |
| AG-9 | Statuts | `pending` (documents en revue) → `active` → `suspended` (motif) → `active` ou `terminated` ; seul un agent `active` touche des commissions | |
| AG-10 | Qui crée et valide un agent | Création par « Gestion des agents » ; validation des documents par la Conformité (deux personnes différentes) | |
| AG-11 | Base des commissions (V2-G02) | Pourcentage du **revenu net** des joueurs rattachés (mises − gains − bonus) par mois ; jamais négatif d'un mois sur l'autre. Autres bases possibles : mises, dépôts | |
| AG-12 | Taux | Un taux par produit (paris sportifs, borlette, jeux virtuels), versionné ; hausse en double validation (#228) | |
| AG-13 | Seuil commercial de 3 000 USD | Seuil d'activité mensuelle (en HTG, converti au taux fixé dans les paramètres) en dessous duquel aucune commission n'est versée, ou au-dessus duquel le taux augmente ? À préciser | |
| AG-14 | Ce que voit l'agent | Ses joueurs rattachés sous forme anonymisée (identifiant court, date d'inscription, activité agrégée), jamais leurs coordonnées ni le détail de leurs paris | |
| AG-15 | Réclamations | Un joueur peut lier une réclamation à son agent (V2-G04) ; l'agent ne voit pas ces réclamations | |

## 6. Questions au conseil juridique

| # | Question | Réponse (avis écrit) |
|---|---|---|
| AGJ-1 | Un apporteur rémunéré à la commission (option A) a-t-il besoin d'un agrément ou d'un enregistrement ? Quelle fiscalité sur ses commissions (retenue à la source) ? | |
| AGJ-2 | Option B : un agent peut-il encaisser des dépôts et payer des retraits en espèces pour l'opérateur ? Agrément, plafonds et obligations AML ? | |
| AGJ-3 | Option C : la vente de tickets au comptoir à des clients sans compte est-elle permise ? Ticket anonyme et paiement des gains en espèces ? | |
| AGJ-4 | Publicité faite par les agents : règles à leur imposer (âge, jeu responsable) et responsabilité de l'opérateur. | |

## 7. Conséquences techniques (option A)

- **Backend (Benginio)** : module `backend/app/Modules/Agents` ; tables des agents, de leurs documents (chiffrés,
  comme le KYC), du rattachement des joueurs (historique en ajout seul) et des commissions. Commissions créditées par
  une écriture du ledger (nouveau type `commission`), calculées par une commande idempotente par période. Contrat
  écrit d'abord dans `docs/api/openapi.yaml`. Permissions placées dans la matrice avec V2-G03.
- **Frontend (Rubly)** : écrans admin des agents et des commissions (#227, #229) ; espace agent (tableau de bord,
  joueurs rattachés anonymisés, commissions) ; champ « code agent » à l'inscription.
- **Options B et C** : nouvelles écritures (float, opérations d'agent) et rapprochement ; à concevoir seulement après
  leur validation.

## 8. Conditions pour passer à « Validée »

1. AG-1 à AG-15 tranchés par écrit.
2. Avis juridique sur AGJ-1 (et AGJ-2 / AGJ-3 si B ou C sont retenues).
3. Modèle de contrat d'agent disponible (hors du dépôt).
4. Rôle « Gestion des agents » placé dans la matrice des permissions (V2-G03).

## 9. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |
