# D-20 — Paris en direct : cotes fraîches, délai d'acceptation et suspension sur événement

| | |
|---|---|
| **Tâche** | V2-D06 (#173 backend, #174 écrans) ; prépare V2-D07 (cash-out, #175 et #176) |
| **Statut** | 🟡 À valider — proposition |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-18 par Benginio |
| **Dépend de** | D-02 et V2-A01 (#137 : plan payant de The Odds API), D-03 et D-04 (« pas de paris en direct » en V1), D-05 (changement de cote), D-06 (plafonds), D-18 (marge en direct + 2 points), V2-D05 (plafonds de risque) |
| **Lève** | Point élevé **E1** de la revue de sécurité P8-02 |

> Aucun code de V2-D06 n'est écrit tant que D-20 n'est pas validée. Même une fois le code livré, `betting.allow_live`
> reste **désactivé** jusqu'aux conditions d'activation du §7.

## 1. Besoin

Critère de #173 et #174 : « `allow_live` activé seulement après test de charge et budget de crédits validés ».
Tâche : cotes en direct, délai d'acceptation, suspension sur événement.

**Le risque à couvrir** : nos cotes ont toujours du retard sur le terrain (bookmaker → The Odds API → notre synchro).
Un joueur qui voit un but à la télévision peut parier sur la cote d'avant le but : la maison perd à coup sûr. C'est le
point **E1** de la revue P8-02 : « ne l'activer qu'après une décision écrite, avec des cotes de quelques secondes et un
délai d'acceptation ».

## 2. Déjà en place

| Élément | Valeur actuelle |
|---|---|
| Interrupteur `betting.allow_live` (paramètres de l'admin, audité) | **désactivé** (P8-02 E1, #108) |
| Synchro des matchs en cours, score et cotes (`sports-data:live`, Rubly) | toutes les **15 min** (`SPORTS_DATA_LIVE_CRON`) |
| Durée de suivi après le coup d'envoi | 180 min (`SPORTS_DATA_LIVE_WINDOW`) |
| Plafond de crédits du direct, par jour | 30 (`SPORTS_DATA_LIVE_DAILY_CREDITS`) |
| Fraîcheur maximale d'une cote en direct | **20 min** (`SPORTS_DATA_MAX_LIVE_ODDS_AGE`) |
| Marge en direct (D-18) | + 2 points, argent réel seulement |
| Changement de cote (D-05) | refus et reconfirmation (`409 odds_changed`) |
| Plafonds par match, par marché, par joueur (V2-D05) | appliqués à tous les paris |

**Limite du fournisseur** : The Odds API donne le **score**, pas les faits de jeu (buts, cartons, penalties, temps
de jeu). Notre seul signal d'« événement » est donc **un changement de score**, vu au rythme de la synchro.

## 3. Options

### 3.1 Fréquence des cotes en direct

| Option | Synchro pendant un match | Fraîcheur maximale | Coût estimé* | Avis |
|---|---|---|---|---|
| A | 15 min (aujourd'hui) | 20 min | ~12 crédits/h | ❌ exploitable (E1) |
| **B** | **60 s** | **2 min** | **~180 crédits/h** | ✅ **proposée** au lancement |
| C | 30 s | 1 min | ~360 crédits/h | plus sûr, coût doublé |

\* Par compétition qui a un match en cours : 1 crédit (scores) + 2 crédits (cotes `h2h,totals`, région `eu`) par
passage. Exemple B : un samedi avec 6 compétitions en direct pendant 2 h ≈ 2 200 crédits. L'offre gratuite
(500 par mois) ne suffit pas : **le plan payant (V2-A01, #137) est obligatoire**.

### 3.2 Délai d'acceptation

| Option | Fonctionnement | Avis |
|---|---|---|
| A | Pas de délai | ❌ refusé par E1 |
| B | La requête du joueur attend N secondes, puis répond | ❌ chaque pari bloque un processus du serveur ; résultat perdu si la connexion coupe |
| **C** | **Ticket « en attente d'acceptation », mise réservée ; une tâche en file revérifie après N secondes, puis accepte ou refuse (mise rendue)** | ✅ **proposée** : le serveur ne bloque pas, le ticket reste visible dans « Mes paris », tout est horodaté |

## 4. Proposition (options 3.1 B et 3.2 C)

### 4.1 Périmètre au lancement

- **Football seulement**, marchés **1X2 et plus/moins**. Pas de marchés maison, de score exact ni de marchés
  statistiques (corners, cartons) en direct.
- Paris **simples et combinés**. Un combiné qui contient **au moins une sélection en direct** suit le circuit du
  direct pour tout le ticket.
- Pas de cash-out (V2-D07, décision séparée).

### 4.2 Délai d'acceptation

1. Le joueur parie sur un match en cours. Les contrôles habituels s'appliquent tout de suite (cote, plafonds, jeu
   responsable, solde).
2. Le ticket est créé avec le statut **« en attente d'acceptation »**. La mise est **réservée** dans le ledger, comme
   pour tout pari ; elle compte déjà dans les plafonds et l'exposition.
3. Après **8 secondes** (réglable de 5 à 15 s par le Super Admin), une tâche revérifie :
   - même **version de cote** que celle acceptée par le joueur ;
   - marché **ouvert** et cote assez récente ;
   - **score inchangé** depuis la prise du pari.
4. Tout est bon : le ticket passe **« en attente »** (circuit normal du règlement).
   Sinon : le ticket passe **« refusé »**, la mise est **rendue** par une écriture compensatoire (jamais d'effacement),
   avec le motif (« la cote a changé », « marché suspendu », « le score a changé »).
5. Si la tâche n'a pas tourné au bout de 60 s (file en panne) : le ticket est **refusé** et la mise rendue.
   **Jamais d'acceptation par défaut.**
6. Les paris sur des matchs **pas encore commencés ne changent pas** : acceptés tout de suite, comme aujourd'hui.

### 4.3 Suspension sur événement

- À chaque synchro du direct, si le **score d'un match a changé**, tous ses marchés sont **suspendus
  automatiquement** (motif « auto : changement de score »).
- Ils sont **rouverts automatiquement** quand arrivent des cotes **postérieures** au changement de score.
- Les tickets « en attente d'acceptation » sur ce match sont **refusés** (mise rendue).
- Un administrateur peut toujours suspendre ou rouvrir un marché à la main (`markets.suspend`, motif, audit).

### 4.4 Paramètres

| Paramètre | Valeur proposée | Réglé par |
|---|---|---|
| `betting.allow_live` | désactivé jusqu'au §7 | Super Admin (paramètres audités) |
| Délai d'acceptation | 8 s (de 5 à 15 s) | Super Admin (paramètres audités) |
| Synchro du direct | 60 s | variable d'environnement (`SPORTS_DATA_LIVE_CRON`) |
| Fraîcheur maximale en direct | 2 min | variable d'environnement (`SPORTS_DATA_MAX_LIVE_ODDS_AGE`) |
| Plafond de crédits du direct, par jour | à fixer selon le plan payant (#137) | variable d'environnement |
| Mise maximale en direct | 10 000 HTG au lancement (au lieu de 50 000) | Super Admin |
| Marge en direct | + 2 points (D-18) | inchangé |

Quand le plafond de crédits du jour est atteint, les cotes en direct vieillissent et ne sont plus pariables :
**le direct se ferme de lui-même**, sans risque.

## 5. Ce que voit le joueur (écrans de Rubly, #174)

- Après « Parier » : « **Pari en cours de validation…** », puis « ✅ Pari accepté » ou
  « ❌ Pari refusé : la cote a changé. Votre mise vous a été rendue. »
- Le ticket apparaît dans « Mes paris » dès sa création, avec son statut.
- Un marché suspendu reste affiché, grisé (« Suspendu »), comme aujourd'hui.

## 6. Contrat d'API (à fixer d'abord dans `openapi.yaml`)

- `POST /bets` sur un match en direct : **202** avec le ticket au statut `awaiting_acceptance` et la durée du délai.
- `GET /bets/{id}` : statut à jour (`pending` = accepté, `rejected` avec `rejection_reason`).
- Nouveaux statuts de ticket : `awaiting_acceptance`, `rejected`.
- Admin : les tickets refusés et leur motif visibles dans la fiche ticket.

## 7. Conditions d'activation de `allow_live`

1. D-20 validée, code livré et testé avec le faux fournisseur.
2. Plan payant de The Odds API souscrit, avec l'accord d'usage commercial (V2-A01, #137), et plafond de crédits fixé.
3. **Test de charge** sur le Staging : synchro toutes les 60 s et paris simultanés, sans dépassement du délai.
4. Argent réel (V2-B04) : marge en direct active.
5. Ligne « `betting.allow_live` » de `docs/ops/lancement.md` mise à jour, et point E1 de P8-02 noté comme levé.
6. Activation par un Super Admin dans les paramètres (auditée), d'abord sur une **période d'essai** surveillée.

## 8. Questions à trancher

1. Fréquence : **60 s (B)** ou **30 s (C)**, selon le budget du plan payant ?
2. Délai d'acceptation : **8 s** te convient-il ?
3. Périmètre au lancement : **football 1X2 et plus/moins seulement** ?
4. Combinés : autoriser une sélection en direct dans un combiné, ou **simples seulement** en direct au lancement ?
5. Mise maximale en direct : **10 000 HTG** au lancement ?
6. Faut-il mettre à jour D-03 et D-04 (« pas de paris en direct ») en renvoyant à D-20 ?

## 9. Hors périmètre

Cash-out (V2-D07), autres sports en direct, flux de faits de jeu d'un autre fournisseur (buts, cartons en temps
réel), diffusion vidéo.
