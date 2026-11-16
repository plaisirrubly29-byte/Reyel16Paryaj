# D-19 — Fidélité, parrainage et bonus : règles et anti-abus

| | |
|---|---|
| **Tâche** | V2-E07 (#187 backend, #188 écrans) |
| **Statut** | 🟡 À valider — options de gain et de récompense retenues par Rubly PLAISIR le 2026-11-15 |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-15 |
| **Dépend de** | V2-C03 (vérification d'identité), V2-C05 (détection des multi-comptes, #158), V2-B04 (argent réel), D-06 (devise, mises), D-13 (agents : le parrain-apporteur rémunéré reste un sujet séparé) |

> Les deux programmes sont **désactivés par défaut** (`LOYALTY_ENABLED`, `REFERRAL_ENABLED`) tant que D-19 n'est pas
> validée et que V2-C05 n'est pas en place : sans détection des comptes liés, le parrainage serait trop facile à
> détourner. Tant qu'ils sont désactivés, l'API répond 404 et les rubriques restent « Bientôt » côté joueur.

## 1. Besoin

Critères de #187 et #188 : **« Conditions de mise affichées »** et **« Abus détectés »**.

Récompenser les joueurs réguliers (points de fidélité) et ceux qui font venir de nouveaux joueurs (parrainage), sans
créer d'argent retirable gratuitement : toute récompense est versée en **bonus**, soumis à des conditions de mise.

## 2. Points de fidélité (option retenue : 1 point par 100 HTG misés)

| Règle | Proposition |
|---|---|
| Gain | **1 point par tranche entière de 100 HTG misés**, crédité au **règlement** du pari (gagné ou perdu). Combiné : sa mise totale. |
| Exclus | Paris annulés ou remboursés, mises payées avec le solde bonus, paris d'un compte suspendu ou auto-exclu. |
| Paliers | Selon les points gagnés sur **12 mois glissants** : **Bronze** 0, **Argent** 1 000, **Or** 5 000, **Diamant** 20 000. |
| Échange | **100 points = 10 HTG** de bonus (Bronze). Taux amélioré par palier : Argent 11 HTG, Or 12 HTG, Diamant 15 HTG. Minimum **500 points**, par multiples de 100. |
| Expiration | Chaque point expire **12 mois** après son gain (les plus anciens sont utilisés en premier). |
| Historique | Chaque mouvement (gain, échange, expiration, retrait par la Conformité) est enregistré, avec le pari d'origine. |

> Exemple : un joueur mise 2 350 HTG dans le mois → 23 points (les 50 HTG restants ne comptent pas ; chaque pari est
> compté séparément). À 1 000 points sur 12 mois, il passe Argent : 500 points valent alors 55 HTG de bonus.

## 3. Parrainage (option retenue : 250 HTG au parrain)

| Règle | Proposition |
|---|---|
| Code | Un **code personnel** par joueur (8 caractères sans ambiguïté, ex. `RY7K2M9P`) et un lien `…/register?parrain=CODE`. |
| Saisie | **À l'inscription seulement**. Un compte n'a qu'un parrain ; le code ne peut pas être ajouté ni changé après. |
| Filleul qualifié | Dans les **30 jours** suivant son inscription : identité **vérifiée** (KYC), **dépôt réel ≥ 500 HTG** et **paris réglés ≥ 500 HTG**. |
| Récompense | **250 HTG de bonus au parrain**, versés dès la qualification. Rien pour le filleul. |
| Conditions du parrain | Identité vérifiée ; compte actif (ni suspendu ni auto-exclu). |
| Plafond | **20 filleuls récompensés par mois** et par parrain ; au-delà, la qualification est notée sans versement. |

## 4. Bonus et conditions de mise (affichées au joueur)

- Les récompenses vont sur un **solde bonus** séparé : on peut miser avec, **pas le retirer**.
- Pour qu'un bonus devienne de l'argent retirable, il faut **miser 3 fois son montant** sur des paris réglés de cote
  **≥ 1,50** (cote totale pour un combiné), dans les **30 jours**. Sinon, le bonus restant expire.
- Le joueur voit en permanence : solde bonus, montant restant à miser, cote minimale et date limite.
- Une mise utilise d'abord le **solde réel**, puis le bonus (le joueur garde la main sur son argent).

## 5. Anti-abus (« abus détectés »)

| Risque | Règle |
|---|---|
| Faux filleuls (multi-comptes) | Comptes liés au parrain (même téléphone, appareil, pièce d'identité, adresse IP répétée — V2-C05) : récompense **bloquée** et signalée à la Conformité ; jamais de blocage automatique du compte sans revue. |
| Auto-parrainage | Son propre code refusé ; deux comptes avec la même pièce d'identité ne peuvent pas être parrain et filleul. |
| Points « achetés » | Pas de points sur les paris annulés ou remboursés ; paris opposés sur un même match (couverture) signalés. |
| Bonus détourné | Pas de retrait du bonus ; conditions de mise ci-dessus ; un bonus ne génère pas de points. |
| Correction | La Conformité peut **annuler une récompense ou retirer des points** avec un motif ; action auditée (D-07). |

## 6. API (contrat fixé dans `docs/api/openapi.yaml`)

- `GET /me/loyalty` : points, palier, palier suivant, taux d'échange, solde bonus et conditions de mise en cours.
- `GET /me/loyalty/history` : mouvements de points (paginé).
- `POST /me/loyalty/redeem` : échange de points contre un bonus (clé d'idempotence).
- `GET /me/referral` : code, lien, récompense, règles, compteur du mois et filleuls (nom masqué, étapes).
- `POST /auth/register` : champ optionnel `referral_code`.

## 7. À valider

| # | Question | Proposition | Décision |
|---|---|---|---|
| FP-1 | Gain de points | 1 point / 100 HTG misés sur paris réglés | Retenue (2026-11-15) |
| FP-2 | Récompense de parrainage | 250 HTG de bonus au parrain seul | Retenue (2026-11-15) |
| FP-3 | Conditions de mise du bonus | 3 fois le montant, cote ≥ 1,50, 30 jours | |
| FP-4 | Paliers et taux d'échange | Bronze / Argent / Or / Diamant, 10 à 15 HTG pour 100 points | |
| FP-5 | Plafond de filleuls | 20 récompensés par mois | |
| FP-6 | Activation | Après V2-C05 et l'argent réel (V2-B04) | |
