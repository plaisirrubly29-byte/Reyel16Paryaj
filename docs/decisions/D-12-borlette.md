# D-12 — Borlette : cadre juridique, règles des jeux, barème des gains et limites

| | |
|---|---|
| **Tâche** | V2-D09 (débloque les issues #234 backend et #235 écrans) |
| **Statut** | 🟡 À décider — brouillon préparé le 2026-11-08 ; avis juridique, règles et barème à obtenir |
| **Décideur** | Rubly PLAISIR (chef de projet), sur avis écrit d'un conseil juridique du territoire visé |
| **Préparé le** | 2026-11-08 |
| **Dépend de** | D-01/D-09 (cadre juridique), D-06 (devise, mises, plafonds), D-05 (corrections), V2-D05 (plafonds de risque) |

> Ce document n'est pas un avis juridique. Il structure la décision et liste les questions à trancher. Aucune règle de
> jeu, aucun barème de gain et aucune limite n'est fixé ici : les cases vides sont à remplir par écrit avant la
> moindre vente de ticket.

## 1. Besoin

Vendre des tickets de borlette (Bolèt, Maryaj, Loto 3, Loto 4, Loto 5) sur les tirages déjà affichés par le site,
limiter l'engagement par boule et par tirage, et payer automatiquement les gains à la publication des résultats.

## 2. Déjà en place (étape 1)

- Tirages et résultats publiés, lus sur l'API Borlette UnitySoftConnect (#215, #222) ; page et fiche de jeu (#224).
  Détail : `backend/app/Modules/Lottery/README.md`.
- Chaque résultat donne **trois lots de deux chiffres** (`lot1`, `lot2`, `lot3`), et, si le fournisseur les donne, un
  **pick 3** et un **pick 4** bruts. Un résultat importé n'est jamais écrasé ; un conflit est journalisé.
- Tout est coupé tant que `LOTTERY_ENABLED=false` ; la vente reste désactivée côté site (`VITE_LOTTERY_SALES`).
- La borlette figure dans les exclusions par défaut de `docs/architecture.md`.

## 3. Questions au conseil juridique

| # | Question | Réponse (avis écrit) |
|---|---|---|
| BL-1 | La vente de borlette en ligne est-elle couverte par l'autorisation de paris sportifs (J-1), ou relève-t-elle d'une autre catégorie (loterie, jeux de hasard) avec une licence ou un agrément à part ? Quelle autorité la délivre ? | |
| BL-2 | Peut-on prendre des paris sur les résultats de tirages étrangers (New York, Floride) ? Faut-il un accord avec l'organisateur de ces tirages ou avec le fournisseur des résultats ? | |
| BL-3 | Obligations propres à la borlette : âge, mise minimale ou maximale imposée, plafond de gain, messages de jeu responsable, heure limite de vente avant un tirage ? | |
| BL-4 | Fiscalité des mises et des gains de borlette, si elle diffère des paris sportifs (J-10) ; retenue à la source sur les gros gains ? | |
| BL-5 | Obligations de lutte contre le blanchiment (J-6) propres à la borlette : seuil de vérification d'identité avant de payer un gain ? | |
| BL-6 | Peut-on lever l'exclusion de `docs/architecture.md` pour la borlette seulement ? | |

## 4. Règles des jeux et barème des gains

À écrire par Rubly PLAISIR et à valider par écrit. La colonne « Combinaison gagnante » dit sur quels numéros du
résultat (§2) le ticket est jugé ; la colonne « Gain » donne le multiplicateur de la mise.

| Jeu | Ce que joue le joueur | Combinaison gagnante | Gain (× la mise) | Mise min / max |
|---|---|---|---|---|
| Bolèt | une boule de 2 chiffres | lot 1, lot 2 ou lot 3 | lot 1 : … ; lot 2 : … ; lot 3 : … | |
| Maryaj | deux boules de 2 chiffres | | | |
| Loto 3 | 3 chiffres | | | |
| Loto 4 | 4 chiffres (options éventuelles) | | | |
| Loto 5 | 5 chiffres | | | |

Questions à trancher avec le barème :

| # | Question | Décision |
|---|---|---|
| R-1 | Maryaj : quelles paires de lots gagnent (1-2, 1-3, 2-3), et l'ordre des boules compte-t-il ? | |
| R-2 | Loto 4 et Loto 5 : de quels numéros du résultat sont-ils tirés ? Options (ex. « boîte », positions) proposées ? | |
| R-3 | Une même boule sur plusieurs lots (ex. lot 1 = lot 3) : un gain par lot ou un seul ? | |
| R-4 | Un ticket peut-il contenir plusieurs jeux et plusieurs tirages ? Mise minimale par ligne ou par ticket ? | |
| R-5 | Plafond de gain par ticket (D-06 fixe 1 000 000 HTG pour les paris sportifs) : le même ? | |

## 5. Ventes, limites et incidents

| # | Point | Décision |
|---|---|---|
| L-1 | Fin des ventes : à l'heure `close_time` du fournisseur, ou quelques minutes avant ? | |
| L-2 | Engagement maximal par boule et par tirage (somme des gains possibles), au-delà duquel un ticket est refusé | |
| L-3 | Boules « bloquées » : qui les fixe (rôle admin), pour combien de temps, avec quel motif ? | |
| L-4 | Le joueur peut-il annuler un ticket avant la fin des ventes ? Dans quel délai ? | |
| L-5 | Tirage annulé ou résultat jamais publié : remboursement des mises après quel délai ? | |
| L-6 | Résultat en conflit (le fournisseur publie d'autres numéros) ou erroné : règlement suspendu, puis correction à double validation, comme D-05 (72 h maximum après règlement) ? | |
| L-7 | Limites de jeu responsable (mise, dépôt, pertes, pause, auto-exclusion) : appliquées à la borlette comme aux paris sportifs ? | |

## 6. Conséquences techniques (proposition, à confirmer après les §4 et §5)

- **Backend (Benginio)** : dans le module `Lottery`, des tickets de borlette dont la mise est réservée par le ledger
  avec une clé d'idempotence ; `POST /lottery/tickets` refusé après la fin des ventes, hors mises min/max ou au-delà
  de l'engagement d'une boule ; règlement automatique et idempotent à l'import du résultat, gains crédités par le
  ledger ; correction de résultat auditée à double validation ; limites de jeu responsable et contrôle AML appliqués.
  Le contrat est écrit d'abord dans `docs/api/openapi.yaml` (tag Lottery). Barème et limites dans la configuration
  versionnée, jamais en dur.
- **Frontend (Rubly)** : vente et coupon de borlette, tickets dans « Mes paris », écrans admin des boules bloquées et
  des engagements. Montants, gains possibles et heure de fin des ventes affichés tels que le serveur les renvoie.
- **Tant que BL-1 et BL-6 n'ont pas de réponse**, la vente reste désactivée, même en Staging.

## 7. Conditions pour passer à « Validée »

1. Avis juridique écrit sur BL-1 à BL-6.
2. Règles et barème du §4 remplis et validés par écrit.
3. Points L-1 à L-7 du §5 décidés.
4. `docs/architecture.md` mis à jour pour retirer la borlette de la liste des exclusions.

## 8. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |
