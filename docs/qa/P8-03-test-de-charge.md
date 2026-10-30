# Test de charge — P8-03

| | |
|---|---|
| **Tâche** | P8-03 — Test de charge adapté (ticket #54) : catalogue et `POST /bets` sous charge attendue |
| **Scripts** | [`load/`](../../load/README.md) (k6) |
| **Pile testée** | Images de production (`docker/production/Dockerfile`) et Compose de Staging (P1-06), projet Docker isolé |
| **Date** | 2026-10-30 |

## 1. Objectifs (fixés pour P8-03)

| Parcours | Charge | Objectif |
|---|---|---|
| Catalogue public (sports, matchs, marchés) | 1 000 visiteurs simultanés, 1 à 3 s de lecture entre les pages | p95 < 200 ms, erreurs < 0,5 % |
| `POST /bets` | 30 paris par seconde (coup d'envoi d'un match) | p95 < 500 ms, erreurs < 0,5 % |

## 2. Résultat

| Parcours | Résultat | Détail |
|---|---|---|
| `POST /bets` | ✅ **Atteint** | 5 401 paris en 3 min à 30/s : médiane 79 ms, **p95 130 ms**, p99 204 ms, 0 erreur |
| Catalogue | ❌ **Atteint jusqu'à ~480 visiteurs simultanés**, pas 1 000 | 300 visiteurs : p95 73 ms ; 500 : p95 208 ms ; 1 000 : p95 1,68 s ; 0 erreur dans tous les cas |

Aucune erreur sur l'ensemble des passages (plus de 300 000 requêtes) : sous la charge, le serveur ralentit mais reste
exact (aucun pari perdu ni en double, limites de débit respectées).

## 3. Conditions de mesure

- **Machine** : un poste Windows, Docker Desktop (12 processeurs logiques, 12 Go), serveur et générateur sur la même
  machine. k6 a consommé environ 0,5 cœur : la mesure porte bien sur le serveur.
- **Catalogue fictif** : 2 matchs, 8 marchés (faux fournisseur). Les requêtes du catalogue sont donc peu coûteuses en
  base ; un catalogue réel (dizaines de matchs) coûterait plus en sérialisation JSON, d'où l'intérêt du cache (§4.4).
- **Une IP par visiteur** : les limites de débit restent actives et comptées par visiteur (`X-Forwarded-For` du
  générateur, accepté seulement sur la pile de test, voir `load/README.md`).
- **Paris** : 200 joueurs inscrits par l'API (crédit de bienvenue fictif), sessions ouvertes avant la mesure, mise de
  10 HTG sur une sélection 1X2 tirée au hasard, clé d'idempotence par pari.

## 4. Goulots trouvés et corrections

Chaque correction a été mesurée seule avant d'être gardée.

### 4.1 Verrou exclusif sur le marché (`POST /bets`)

`BetPlacementService` verrouillait les marchés du coupon en `FOR UPDATE` : tous les paris d'un même match passaient
un par un (106 attentes sur ce verrou dans un échantillon de l'activité PostgreSQL). Le verrou sert à empêcher une
synchronisation de changer la cote entre la vérification et le ticket : un verrou **partagé** (`FOR SHARE`) suffit,
car la synchronisation et la suspension modifient la ligne du marché et attendent donc la fin des paris en cours.
Aucun plafond d'exposition par marché n'exigeait de sérialiser les paris.

### 4.2 Introspection du schéma à chaque requête (`POST /bets`)

Les 21 modèles en `$guarded = ['id']` obligent Laravel à lire les colonnes de la table (requête lourde sur le
catalogue système de PostgreSQL) la première fois qu'un modèle est rempli **dans chaque requête** : c'était 38 % de
l'activité de PostgreSQL (432 % de CPU). Remplacé par des listes `$fillable` explicites, **strictement équivalentes**
(toutes les colonnes sauf `id`). `tests/Feature/ModelFillableTest.php` garantit que chaque liste reste égale aux
colonnes de sa table : une migration qui ajoute une colonne sans l'ajouter à la liste fait échouer la CI.

### 4.3 Une connexion PostgreSQL par requête (catalogue et paris)

PostgreSQL avait reçu 65 490 connexions (une par requête HTTP), chacune avec un nouveau processus et une
authentification SCRAM-SHA-256. Option `DB_PERSISTENT` (connexion persistante par processus php-fpm), activée dans
le modèle de `.env` du Staging : 85 connexions pour un passage complet des paris, 153 pour le catalogue.

### 4.4 Catalogue recalculé à chaque visite

Réponses identiques pour tous les visiteurs anonymes : cache court dans Redis (`CATALOGUE_CACHE_SECONDS`, 10 s dans
le modèle de `.env`, désactivé par défaut en développement et en test), après la limite de débit. Le pari revérifie
toujours la cote côté serveur : une cote affichée peut avoir jusqu'à 10 s de retard, jamais le ticket.

### 4.5 Nombre de processus php-fpm

`PHP_FPM_MAX_CHILDREN` (défaut 10) : passer de 10 à 24 ne gagne que 7 % une fois la machine saturée. Recommandation :
environ 2 processus par vCPU (modèle du Staging : 4 pour 2 vCPU).

## 5. Évolution des mesures

### `POST /bets` (30 paris/s pendant 3 min)

| Étape | Paris placés (sur 5 400) | Médiane | p95 | p99 |
|---|---|---|---|---|
| Départ | 4 404 | 7,92 s | 8,78 s | 9,03 s |
| + verrou partagé | 5 271 | 564 ms | 5,57 s | 6,04 s |
| + sessions ouvertes avant la mesure (méthode) | 5 401 | 392 ms | 2,87 s | 3,10 s |
| **+ `$fillable` + connexion persistante** | **5 401** | **79 ms** | **130 ms** | **204 ms** |

### Catalogue (1 000 visiteurs simultanés, palier de 3 min)

| Étape | Débit | Médiane | p95 |
|---|---|---|---|
| Départ | 85 req/s | 9,94 s | 11,99 s |
| + connexion persistante | 182 req/s | 3,82 s | 4,56 s |
| + cache 10 s | 340 req/s | 1,26 s | 1,87 s |
| + 24 processus php-fpm | 363 req/s | 1,08 s | 1,68 s |

Capacité finale : **300 visiteurs : p95 73 ms ; 500 visiteurs : p95 208 ms** (objectif tenu jusqu'à ~480).

## 6. Limite restante et recommandations

Avec le cache, PostgreSQL ne travaille presque plus pendant le test du catalogue : la limite est le CPU de PHP
(environ 19 ms par requête, même servie depuis le cache, pour démarrer le framework à chaque requête avec php-fpm).

- **Dimensionnement** : la capacité suit le nombre de cœurs. Le serveur de Production prévu par D-10 (4 vCPU) tiendrait
  de l'ordre de 150 à 200 visiteurs simultanés sous l'objectif de 200 ms ; le Staging (2 vCPU) environ la moitié.
- **Pour 1 000 visiteurs simultanés** (tâche à créer) :
  1. **Cache HTTP devant PHP** (Caddy avec module de cache, ou CDN) pour les réponses publiques du catalogue : elles
     ne passeraient plus du tout par PHP ;
  2. ou **Laravel Octane** (FrankenPHP, application gardée en mémoire) : plusieurs fois le débit, mais attention à
     l'état partagé entre requêtes (singletons, propriétés statiques) à auditer ;
  3. ou plus de cœurs / plusieurs serveurs applicatifs derrière un répartiteur (D-10 l'envisage pour 99,9 %).
- Relancer ce test sur le serveur réel une fois le Staging en service (P1-06), avec un catalogue réel.
