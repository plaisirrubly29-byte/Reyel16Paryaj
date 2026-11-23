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

## 10. Mise en œuvre — option « Modèle + The Odds » (choisie par Rubly, 2026-11-22)

Code livré, **`betting.allow_live` toujours coupé par défaut** : rien ne change en Staging ni en Production tant qu'un
Super Admin ne l'active pas (conditions du §7).

| Élément | Mise en œuvre |
|---|---|
| Cotes en direct | Modèle maison (`LiveOddsModel`, Poisson) : force offensive de chaque équipe tirée du 1X2 et du plus/moins d'avant-match, puis score et temps restant. Recalculées **à chaque lecture** (rien n'est écrit chaque seconde). Football, 1X2 et plus/moins seulement. |
| Correction par The Odds API | À chaque synchro en direct (`sports-data:live`, `SPORTS_DATA_LIVE_CRON`), le modèle est recalé sur les cotes en direct reçues ; notre cote n'est **jamais mieux payée** que la dernière cote The Odds (marge comprise) prise après le dernier changement de score. |
| Marge | `OddsMargin` + `live_extra` (2 points), toujours appliquée aux cotes du modèle. |
| Score | `sports-data:live-scores` toutes les 30 s (`SPORTS_DATA_LIVE_SCORES_SECONDS` : 10 à 60 s), 1 crédit par compétition en direct, **seulement quand le direct est ouvert**, sous le même plafond de crédits du jour. |
| Suspension automatique | Score de plus de 90 s, changement de score depuis moins de 10 s (VAR, §10.2), issue acquise ou probabilité > 98 % (§10.4), suspension par un administrateur. Affiché « Suspendu » côté joueur. Fermé (« Fermé », pas « Suspendu ») : 150 min après l’heure prévue (§10.4), plus de score depuis 5 min (§10.2), direct coupé. |
| Délai d'acceptation | Option 3.2 C : ticket `awaiting` (202), mise réservée, revérifié après 8 s (5 à 15 s, réglable par le Super Admin) : score identique, aucun changement de score depuis le pari, marché ouvert, cote pas plus basse de plus de 3 %. Sinon `rejected`, mise rendue (écriture `bet:{id}:rejected`). Jamais vérifié en 60 s : refusé. |
| Cote qui bouge | La cote vue reste acceptée si celle du moment n'est pas plus basse de plus de 3 % (`BETTING_LIVE_ODDS_TOLERANCE`) ; le ticket prend la plus basse des deux. |
| Mise maximale | 10 000 HTG par ticket en direct (`BETTING_LIVE_MAX_STAKE_MINOR`, réglable par le Super Admin). Paris simples seulement (`BETTING_LIVE_ALLOW_MULTIPLE=false`). |
| Coupe-circuit | `betting.allow_live` (paramètres audités) : coupé, plus aucun pari en direct, les tickets en attente sont refusés et les scores ne sont plus relus (aucun crédit). |
| Écrans | Onglet Live et page du match : cotes, score et minute relus toutes les 3 s (`GET /live/odds`) ; coupon : cote mise à jour, « Suspendu », « Pari en cours de validation… » puis accepté ou refusé avec le motif. |

### 10.1 Score exact final en direct et scores relus (2026-11-22, retour de Rubly)

Rubly : « je devrais être capable de parier sur une équipe même si le match est en cours. Exemple : un match est à 2-1,
je peux parier que le match peut terminer 3-1. »

| Constat en Production | Correction |
|---|---|
| Tous les matchs de football en cours affichaient « Suspendu : score en cours de vérification » (`score_stale`) : le score n'était plus relu depuis plus de 90 s. `sports-data:live-scores` relisait toutes les compétitions qui ont un match commencé depuis moins de 3 h, **tous sports confondus** (NFL, NBA, tennis, matchs déjà finis) : 1 crédit par compétition toutes les 30 s, le plafond du jour (`SPORTS_DATA_LIVE_DAILY_CREDITS`) partait en quelques dizaines de minutes. | Les scores toutes les 30 s ne concernent plus que les compétitions de **football** dont un match peut encore être parié (commencé depuis moins de 150 min, §10.4). La fin des matchs reste suivie par `sports-data:live` (15 min). |
| Pas de score exact en direct (D-20 §4.1). | Marché **score exact final** (`correct_score`) coté par le modèle à partir du score du moment : seuls les scores encore possibles, jusqu'à 3 buts de plus par équipe (`SPORTS_DATA_LIVE_CORRECT_SCORE_EXTRA_GOALS`), cote au plus 100 (`SPORTS_DATA_LIVE_CORRECT_SCORE_MAX_ODDS`). Sélections créées à chaque relecture du score (`LiveCorrectScoreMarket`) ; un score dépassé n'est plus affiché ni pariable (`selection_unavailable`). Marge « secondaire » + 2 points ; mise maximale des paris spéciaux (1 000 HTG) : aucun bookmaker ne borne cette cote. Même délai d'acceptation, mêmes suspensions, même règlement sur le score final. |

**Risque restant** : nous ne connaissons un but qu'au rythme des scores de The Odds API (30 s + leur propre retard). Un
pari pris juste après un but que nous n'avons pas encore vu n'est refusé que si le nouveau score arrive avant la fin du
délai d'acceptation. Réduire ce risque : scores plus fréquents (15 s), délai plus long (10 à 15 s), mise maximale plus
basse, ou fermeture de sécurité plus tôt (`SPORTS_DATA_LIVE_CLOSE_AFTER_MINUTES`, §10.4).

### 10.2 Suspensions levées en 10 s, matchs arrêtés fermés (2026-11-22, retour de Rubly)

Rubly : une suspension après une action ne doit pas durer plus d'une dizaine de secondes ; certains matchs restaient
suspendus pour toujours.

| Constat | Correction |
|---|---|
| Après un but, marchés suspendus 30 s (`SPORTS_DATA_LIVE_SCORE_COOLDOWN=30`). | Suspension après une action levée d'elle-même au bout de **10 s** (`SPORTS_DATA_LIVE_ACTION_SUSPENSION_SECONDS`), calculée à chaque lecture : aucun nouveau score n'est nécessaire pour rouvrir. L'ancienne variable n'est plus lue. |
| Match dont The Odds API ne donne jamais le score en direct (`scores: null`, compétition pas suivie) : « Suspendu : score en cours de vérification » (`score_stale`) pendant tout le match. | Sans score depuis **5 min** (`SPORTS_DATA_LIVE_SCORE_UNAVAILABLE_CLOSE_SECONDS`), depuis le coup d'envoi ou le dernier score reçu (match arrêté) : direct du match **fermé**, motif `score_unavailable`, « Paris fermés : le score de ce match n'est plus suivi en direct ». Rouvert de lui-même si le score revient avant la fin. |
| Fin de match : le score n'est plus relu toutes les 30 s après la 85e minute + mi-temps + 5 min ; le motif « score trop ancien » passait avant « fin de match » et le marché restait « suspendu » jusqu'au résultat final. | La fin de match est vérifiée en premier : passé la 85e minute, motif `closing`, « Paris fermés : fin de match ». |
| Écrans : tout motif affiché « Suspendu », « le pari reprend dès sa réouverture ». | Motifs définitifs pour le match (`closing`, `score_unavailable`, `live_closed`, `not_available`) affichés « Fermé », avec « retirez-la du coupon ». |

Limite : The Odds API ne donne que le score, pas les cartons ni les penalties ; seule une action qui change le score
(but, but annulé) suspend les marchés.

### 10.3 Les scores passent avant les cotes (2026-11-22, retour de Rubly)

Rubly : « les matchs en direct doivent être disponibles pour parier même s'ils sont en cours ; aucun match ne doit être
bloqué ».

| Constat en Production | Correction |
|---|---|
| Le plafond du direct (`SPORTS_DATA_LIVE_DAILY_CREDITS=4000`) était vide vers 19 h UTC : environ 1 000 crédits par heure pour les cotes de tous les sports (`sports-data:live` toutes les 2 min) et 1 300 pour les scores du football. Plus de score relu : tous les matchs en cours fermés. | Même soir, sur le serveur : plafond à 12 000, cotes du direct toutes les 10 min (`SPORTS_DATA_LIVE_CRON="*/10 * * * *"`). Sauvegarde `.env.avant-direct-debloque`. |
| Les cotes et les scores puisent dans le même plafond, dans n'importe quel ordre. | La synchro des cotes s'arrête à la moitié du plafond (`SPORTS_DATA_LIVE_ODDS_MAX_SHARE=0.5`) : l'autre moitié reste aux scores du football. |
| La synchro des cotes relisait aussi le score des compétitions déjà relues toutes les 30 s (1 crédit de plus à chaque fois). | Plus de double lecture tant que le direct est ouvert. |

Offre The Odds API : 100 000 crédits par mois. Un soir chargé coûte plusieurs milliers de crédits ; Rubly prendra une
offre plus grande si le mois ne suffit pas, et le plafond du jour sera relevé en conséquence.

### 10.4 Paris ouverts jusqu'au coup de sifflet final (2026-11-23, retour de Rubly)

Rubly : « on devrait pouvoir parier à tout moment, même dans les minutes additionnelles ; les cotes doivent varier en
fonction de la tendance du match. Sur le site, les paris se ferment à un certain moment et ne se rouvrent jamais. »

| Constat en Production | Correction |
|---|---|
| Tous les marchés fermaient à la **85e minute estimée** (`SPORTS_DATA_LIVE_CUTOFF_MINUTE=85`), motif `closing`, sans jamais rouvrir. La minute est estimée depuis l'heure de début **prévue** (The Odds API ne donne pas la minute de jeu) : avec un coup d'envoi retardé ou une mi-temps longue, la fermeture tombait bien avant la vraie 85e minute. | Plus de minute limite : les marchés restent ouverts, **temps additionnel compris**, jusqu'au résultat final (le match passe en « terminé »). Seul filet de sécurité : fermé **150 min** après l'heure prévue (`SPORTS_DATA_LIVE_CLOSE_AFTER_MINUTES`). L'ancienne variable n'est plus lue. |
| Passé la fin estimée, le modèle comptait 0 minute restante : cotes figées. | Le modèle compte toujours au moins **2 minutes** restantes (`SPORTS_DATA_LIVE_MIN_REMAINING_MINUTES`) : les cotes continuent de suivre le score (un but dans le temps additionnel suspend 10 s puis rouvre avec les nouvelles cotes). |
| Le score cessait d'être relu toutes les 30 s après 85 + 15 + 5 min : marchés ensuite fermés faute de score (`score_unavailable`). | Score relu toutes les 30 s jusqu'à la fermeture de sécurité (150 min). Coût : environ 45 min de plus de lecture par compétition et par soirée. |
| Marché suspendu dès qu'une issue dépassait 95 % (cote vers 1,05) : 1X2 fermé en fin de match dès qu'une équipe mène. | Seuil relevé à **98 %** (`SPORTS_DATA_LIVE_MAX_PROBABILITY`, cote 1,01) ; le marché rouvre dès que le match bascule. |

**Risque accepté** : en fin de match, un pari pris juste après un but que The Odds API n'a pas encore signalé n'est
refusé que si le score arrive avant la fin du délai d'acceptation (8 s). Le fournisseur peut aussi annoncer la fin du
match avec quelques minutes de retard ; les cotes sont alors déjà très proches de 1,01 pour l'issue acquise.
