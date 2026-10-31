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

## 7. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |
