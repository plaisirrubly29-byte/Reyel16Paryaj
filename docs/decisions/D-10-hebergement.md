# D-10 — Hébergement, disponibilité, sauvegardes et rétention

| | |
|---|---|
| **Tâche** | P0-07 (débloque P1-06 Staging, P8-04 sauvegardes, P9-02 monitoring) |
| **Statut** | ⏸ Reportée (2026-10-30) — la proposition s'applique au Staging et à la démo ; à valider avant la production |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-15 |
| **Dépend de** | D-01/D-09 question J-9 (les données doivent-elles rester dans le pays ?) |

## 1. Besoin

Stack à héberger (voir `docker-compose.yml`) : API Laravel (PHP 8.3), worker de queue, PostgreSQL 16, Redis 7,
frontend React statique. Trafic V1 modeste (fonds fictifs), mais exigences fortes sur l'intégrité des données
(ledger, tickets, audit) et la capacité de restaurer.

## 2. Options

| | A. VPS + Docker Compose | B. VPS applicatif + PostgreSQL managé | C. PaaS managé (Laravel Cloud, Render, Railway…) |
|---|---|---|---|
| Principe | 1 VPS par environnement, tout en conteneurs | App sur VPS ; base chez un fournisseur managé (sauvegardes/PITR inclus) | Plateforme gère app, base, queue, TLS |
| Coût mensuel estimé* | Staging 5–15 $ + Prod 15–50 $ | Prod 15–50 $ + base managée 15–60 $ | 50–200 $ selon usage |
| Sauvegardes base | À mettre en place (pgBackRest/WAL vers stockage objet) | Incluses, restauration à un instant donné | Incluses |
| Effort d'exploitation | Élevé (mises à jour OS, sécurité, sauvegardes) | Moyen | Faible |
| Maîtrise / portabilité | Totale (même `docker-compose` qu'en local) | Bonne | Dépend de la plateforme |
| Localisation des données | Choix du datacenter | Choix de la région | Régions limitées |

\* Ordres de grandeur pour des VPS 2–4 vCPU / 4–8 Go chez les hébergeurs courants (Hetzner, OVHcloud,
DigitalOcean, Hostinger…). **À vérifier sur les grilles tarifaires du jour avant décision.**

## 3. Proposition

**Option B pour la Production, option A pour le Staging.**

- **Staging** : 1 VPS (2 vCPU / 4 Go), Docker Compose identique au local, HTTPS (Caddy ou Traefik + Let's Encrypt),
  déploiement automatique depuis `develop` (P1-06). Données fictives uniquement.
- **Production** : 1 VPS applicatif (4 vCPU / 8 Go) pour API + worker + Redis, **PostgreSQL managé** avec
  restauration à un instant donné. Le ledger étant la donnée la plus critique, on délègue sa sauvegarde à un
  service spécialisé plutôt que de la bricoler.
- **Frontend** : fichiers statiques derrière le même reverse proxy (ou CDN).
- **Stockage objet** (compatible S3) chez un **autre** fournisseur pour les copies de sauvegarde hors site.
- Région : la plus proche des joueurs **autorisée par J-9** (par défaut Amérique du Nord, ex. Montréal/New York,
  faible latence vers Haïti).
- Si l'équipe a déjà un compte chez un hébergeur (ex. Hostinger VPS), le réutiliser pour le Staging est acceptable.

**Coût cible estimé : 50 à 120 $/mois** pour Staging + Production + stockage de sauvegardes (à confirmer).

## 4. Objectifs de service proposés

| Indicateur | Cible V1 | Commentaire |
|---|---|---|
| Disponibilité Production | 99,5 % / mois (≈ 3 h 40 d'arrêt max) | Réaliste avec un seul serveur applicatif ; 99,9 % exigerait 2 serveurs + répartiteur |
| RPO (perte de données max) | 15 minutes | PITR de la base managée |
| RTO (remise en service max) | 4 heures | Procédure testée (P8-04) |
| Fenêtre de maintenance | Annoncée, hors heures de matchs majeurs | |

## 5. Politique de sauvegarde proposée

| Quoi | Fréquence | Conservation | Où |
|---|---|---|---|
| Base PostgreSQL (PITR) | Continue | 7 jours | Fournisseur de base managée |
| Dump complet chiffré | Quotidien | 30 jours quotidiens + 12 mensuels | Stockage objet hors site |
| Avant chaque migration de Production | À chaque déploiement | 30 jours | Stockage objet |
| Fichiers `.env` / secrets | À chaque changement | Version courante + précédente | Gestionnaire de secrets (jamais dans Git) |

Test de restauration complet **au moins une fois par trimestre** et avant le lancement (P8-04).

## 6. Durées de conservation proposées

| Donnée | Durée | Justification |
|---|---|---|
| Ledger, tickets, règlements | 5 ans après clôture du compte | Hypothèse LCB-FT, **à confirmer (J-6)** |
| Journal d'audit (`audit_logs`) | 5 ans | Traçabilité des actions sensibles |
| Données de profil joueur | Durée du compte + 5 ans, puis anonymisation | À confirmer (J-9) |
| Logs applicatifs | 90 jours | Diagnostic et sécurité |
| Clés d'idempotence | 24 heures | Purge automatique déjà en place (P4-02) |
| Sessions | Durée de session (120 min) | `SESSION_LIFETIME` |

## 7. Fournisseur et budget de la Production (V2-A03, #139)

Proposition préparée le 2026-11-11. **Prix relevés en novembre 2026, surtout sur des comparateurs : à confirmer sur
la page officielle du fournisseur le jour de la commande.**

Le Staging tourne aujourd'hui sur un **VPS Hostinger** (option A) : il y reste.

### 7.1 Comparaison

| | DigitalOcean | Vultr | OVHcloud (Beauharnois, Canada) | Hetzner (Ashburn, États-Unis) | Hostinger (fournisseur du Staging) |
|---|---|---|---|---|---|
| Serveur applicatif 4 vCPU | ≈ 48 $/mois (8 Go) | ≈ 40–48 $/mois (8 Go) | ≈ 30–60 $/mois | ≈ 15–30 $/mois | ≈ 13 $ la 1re période, ≈ 29 $ au renouvellement (KVM 4, 16 Go) |
| PostgreSQL managé, plus petite offre | ≈ 15 $ (1 Go) ; ≈ 30 $ (2 Go) | ≈ 15 $ | ≈ 64 $ (Discovery, sans SLA) à 82 $ (Production, 2 nœuds) | **Aucun** : base à héberger soi-même ou chez un autre fournisseur | **Aucun** : PostgreSQL installé soi-même sur le VPS |
| Restauration à un instant donné (PITR) | **Incluse, 7 jours** | Incluse ou payante selon les sources | Prévue ; un coût supplémentaire est signalé par une source | — | Non : sauvegarde hebdomadaire du VPS ; PITR à monter soi-même (archivage WAL vers stockage objet) |
| Serveur et base dans le même réseau privé | Oui (VPC, même région) | Oui | Oui | Non (base externe, latence et trafic public) | Oui (même serveur) |
| Région proche d'Haïti | New York, Toronto | New York, Miami, Toronto | Beauharnois (Canada) | Ashburn (Virginie) | États-Unis à vérifier à la commande |
| Simplicité pour l'équipe | Élevée | Élevée | Moyenne | Faible pour la base | Élevée pour le serveur (déjà connu), faible pour la base |

### 7.2 Recommandation

**DigitalOcean, région New York (ou Toronto si J-9 impose le Canada), même région pour le serveur et la base :**

- serveur applicatif 4 vCPU / 8 Go (API, worker, scheduler, Redis, Caddy), même déploiement Docker que le
  Staging, avec sauvegarde hebdomadaire du serveur ;
- **PostgreSQL 16 managé, 2 Go**, PITR 7 jours inclus, accès par le réseau privé seulement ;
- copies de sauvegarde hors site (§5) chez **un autre fournisseur** de stockage objet compatible S3 (Backblaze B2 ou
  Cloudflare R2) ;
- le Staging reste où il est (option A).

Raisons : PITR inclus (RPO 15 min du §4 tenu sans outil à installer), base et serveur dans le même réseau privé,
une seule facture, et offre équivalente chez Vultr si DigitalOcean ne convient pas. OVHcloud est plus cher pour la base ;
Hetzner n'a pas de base managée, ce qui contredit l'option B.

**Alternative moins chère : tout chez Hostinger** (option A pour la Production). Un VPS KVM 4 (≈ 13 à 29 $/mois)
avec PostgreSQL en conteneur, comme au Staging. Économie d'environ 60 $/mois, mais l'équipe porte seule la
restauration à un instant donné (archivage WAL vers le stockage objet, à installer, surveiller et tester) et les
mises à jour de la base. Acceptable tant que la Production reste en fonds fictifs ; à revoir avant l'argent réel,
où le ledger justifie une base managée.

### 7.3 Budget mensuel estimé (Production)

| Poste | Estimation |
|---|---|
| Serveur 4 vCPU / 8 Go | ≈ 48 $ |
| Sauvegarde du serveur (≈ 20 % du prix du serveur) | ≈ 10 $ |
| PostgreSQL managé 2 Go, PITR inclus | ≈ 30 $ |
| Stockage objet hors site (moins de 100 Go) | ≈ 1–5 $ |
| Nom de domaine, certificats (Let's Encrypt) | ≈ 1–2 $ |
| **Total Production** | **≈ 90–95 $/mois** |
| Avec le Staging actuel (Hostinger) | ≈ 100–110 $/mois, dans la fourchette du §3 (50 à 120 $) |
| Alternative tout Hostinger (Production) | ≈ 15–35 $/mois, sans base managée |

Évolution prévue : nœud de secours de la base (≈ +30 $) quand l'argent réel arrive, si la disponibilité de 99,5 %
ne suffit plus.

### 7.4 À trancher par Rubly

| # | Question | Proposition | Décision |
|---|---|---|---|
| H-1 | Fournisseur | DigitalOcean (Vultr en second choix) ; Hostinger seul si le budget prime, tant que les fonds sont fictifs | |
| H-2 | Région | New York ; Toronto si J-9 l'exige | |
| H-3 | Budget mensuel | Jusqu'à 110 $ pour Staging + Production | |
| H-4 | Titulaire du compte et moyen de paiement | Compte au nom du projet, accès MFA pour 2 personnes | |
| H-5 | Stockage hors site | Backblaze B2 ou Cloudflare R2 | |

Une fois H-1 à H-5 tranchés, le workflow « Deploy Production » (V2-F01, #189) est préparé : déploiement depuis `main`
avec validation manuelle, secrets séparés du Staging, retour arrière testé.

## 8. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |
