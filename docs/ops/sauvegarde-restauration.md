# Sauvegarde et restauration — P8-04

| | |
|---|---|
| **Tâche** | P8-04 — Sauvegarde/restauration testée et procédure d'incident (ticket #55) |
| **Référence** | D-10 (hébergement, sauvegardes, rétention) — proposition en attente de validation |
| **Scripts** | `scripts/db/backup.sh`, `scripts/db/restore.sh`, `scripts/db/verify.sh` ; hors site (V2-F02) : `scripts/db/offsite-backup.sh`, `scripts/db/offsite-restore.sh` |
| **Incident** | [Runbook d'incident](runbook-incident.md) |

## 1. Objectifs (D-10 §4)

| Objectif | Valeur | Comment on le tient |
|---|---|---|
| **RPO** — perte de données maximale | 15 minutes | Restauration à un instant donné (PITR) de la base managée ; dump quotidien en secours |
| **RTO** — remise en service maximale | 4 heures | Procédure ci-dessous, testée et chronométrée (§5) |

Le ledger (`ledger_entries`), les tickets, les règlements et le journal d'audit sont les données critiques : leur
perte ne se rattrape pas autrement que par une sauvegarde.

## 2. Ce qui est sauvegardé (D-10 §5)

| Quoi | Fréquence | Conservation | Où |
|---|---|---|---|
| Base PostgreSQL (PITR) | Continue | 7 jours | Fournisseur de base managée |
| Dump complet **chiffré**, avec les pièces KYC (`offsite-backup.sh`, §6) | Quotidien | 30 quotidiens + 12 mensuels | Stockage objet hors site (Backblaze B2, D-10 H-5) |
| Dump avant chaque migration de production | À chaque déploiement | 30 jours | Stockage objet |
| `.env` / secrets | À chaque changement | Version courante + précédente | Gestionnaire de secrets, **jamais dans Git** |

Un dump contient des **données personnelles** (comptes, téléphones, journal) :
- `backups/` et `*.dump` sont ignorés par Git ; le dossier est créé en accès restreint (`chmod 700`) ;
- chiffrer avant tout envoi hors du serveur, par exemple `age -r <clé publique> fichier.dump > fichier.dump.age`
  (clé privée dans le gestionnaire de secrets, hors du serveur) ;
- `APP_KEY` est indispensable : sans elle, les secrets MFA chiffrés en base sont illisibles après restauration.
  Elle se sauvegarde avec les secrets, jamais avec le dump.

## 3. Les scripts

Mode local par défaut (conteneur `postgres` de `docker-compose.yml`). Sur un serveur, définir `PGHOST`, `PGPORT`,
`PGUSER`, `PGPASSWORD` (ou `~/.pgpass`) : les scripts utilisent alors les outils PostgreSQL de la machine.

```bash
# 1. Sauvegarde — lecture seule sur la base. Écrit backups/<base>-<date UTC>.{dump,sha256,manifest}
scripts/db/backup.sh reyel16

# 2. Restauration — toujours dans une base NOUVELLE ; refuse une base existante ou protégée
#    (PROTECTED_DATABASES, défaut : reyel16 postgres template0 template1) et un fichier altéré (.sha256)
scripts/db/restore.sh backups/reyel16-20261029T061813Z.dump reyel16_restore_test

# 3. Vérification — lecture seule
scripts/db/verify.sh backups/reyel16-20261029T061813Z.manifest reyel16_restore_test
```

`verify.sh` contrôle :
1. mêmes tables et même nombre de lignes que le **manifeste** écrit au moment de la sauvegarde ;
2. présence des déclencheurs « ajout seul » : `audit_logs`, `ledger_entries`, `results`, `settlements` ;
3. cohérence du ledger : pour chaque portefeuille, `balance_after_minor` de la dernière écriture = somme des écritures.

Limite : le manifeste est calculé juste avant `pg_dump`, pas dans le même instantané. Sur une base très active, un
écart de quelques lignes sur les tables qui bougent (sessions, clés d'idempotence) peut apparaître ; il se lit
dans la sortie de `verify.sh`. Les contrôles 2 et 3 n'en dépendent pas.

## 4. Procédure de restauration (production)

Remplacer la base en service est une décision d'incident : voir le [runbook](runbook-incident.md), scénario
« Base perdue ou corrompue ». Principe : **on ne restaure jamais par-dessus la base en service, on bascule.**

1. Mettre l'application en maintenance (`php artisan down --secret=<jeton>`), arrêter worker et planificateur.
2. Choisir le point de restauration : PITR juste avant l'incident (base managée) ou dernier dump valide.
3. Restaurer dans une **nouvelle** base (`restore.sh` ou restauration PITR du fournisseur vers une nouvelle instance).
4. `verify.sh` sur la base restaurée ; noter les écarts.
5. Basculer `DB_DATABASE` (ou l'hôte) vers la base restaurée ; `php artisan migrate:status` ; redémarrer.
6. Vérifier : `/api/v1/health`, connexion d'un compte de test, consultation d'un ticket, solde d'un portefeuille.
7. Rapprocher la période perdue (entre le point de restauration et l'incident) : tickets et écritures du ledger
   absents, à rétablir par le processus de correction auditée (P6-04), jamais par écriture directe en base.
8. Lever la maintenance ; garder l'ancienne base intacte (enquête) jusqu'à la clôture de l'incident.

Depuis une sauvegarde hors site (serveur perdu) : étape 3 avec `offsite-restore.sh` (§6), qui fait aussi l'étape 4.

## 6. Sauvegarde hors site chiffrée (V2-F02)

Chaque nuit, `offsite-backup.sh` copie la base **et les pièces KYC**, chiffre le tout et l'envoie **hors du serveur**.
Outils : **age** (chiffrement) et **rclone** (envoi vers Backblaze B2, ou Cloudflare R2 en second choix ; un dossier
local pour les essais). Validés par Benginio le 2026-11-19.

### 6.1 Les clés

- Chaque personne habilitée (Benginio, Rubly) crée **sa propre paire de clés** sur son ordinateur :
  `age-keygen -o reyel16-sauvegarde-<prénom>.key`. La commande affiche la **clé publique** (`age1…`).
- **Clé privée** (`AGE-SECRET-KEY-…`) : chez la personne seulement, dans son gestionnaire de mots de passe, avec une
  copie hors ligne. **Jamais sur le serveur, jamais dans Git.** La perdre, c'est ne plus pouvoir ouvrir ses
  sauvegardes : chacun en garde une, l'autre peut toujours ouvrir.
- **Clés publiques** : une par ligne dans le fichier `AGE_RECIPIENTS_FILE` du serveur. Le script refuse un fichier
  qui contient une clé privée.
- `APP_KEY` (`.env`) n'est **pas** dans la sauvegarde : sans elle, les secrets MFA et les pièces KYC sont illisibles
  après restauration. Elle se garde avec les secrets (§2).

### 6.2 Sauvegarde quotidienne

```sh
OFFSITE_REMOTE=b2:reyel16-sauvegardes/production \
AGE_RECIPIENTS_FILE=/etc/reyel16/sauvegarde.pub \
HEALTHCHECK_URL=https://hc-ping.com/<uuid> \
scripts/db/offsite-backup.sh reyel16
```

1. `backup.sh` : dump, empreinte, manifeste ;
2. archive des pièces KYC (volume `kyc_documents`, lu dans le conteneur `backend` ; `KYC_DIR` pour un dossier) ;
3. une seule archive chiffrée pour toutes les clés publiques (`<base>-<date>.tar.age`) ;
4. envoi dans `daily/`, et dans `monthly/` pour la première sauvegarde du mois ; taille vérifiée à la destination ;
5. conservation à la destination : **30 jours** dans `daily/`, **365 jours** dans `monthly/` ;
6. signal à **healthchecks.io** (D-16) : `/start`, puis succès ou `/fail`.

Le dump en clair n'existe que pendant l'opération (dossier d'accès restreint, supprimé à la fin, même en cas
d'erreur). Rien n'est jamais supprimé de la base ni du volume KYC.

Planification sur le serveur (cron de l'utilisateur de déploiement), à 3 h 15 heure d'Haïti, soit 8 h 15 UTC si le
serveur est à l'heure UTC (vérifier avec `date`) :

```cron
15 8 * * * cd /srv/reyel16 && COMPOSE_FILE=docker/production/docker-compose.yml:docker/production/docker-compose.local-db.yml \
  OFFSITE_REMOTE=… AGE_RECIPIENTS_FILE=… HEALTHCHECK_URL=… scripts/db/offsite-backup.sh reyel16 >> /var/log/reyel16-sauvegarde.log 2>&1
```

**Alerte si une sauvegarde manque** : contrôle healthchecks.io « Sauvegarde quotidienne », période **1 jour**,
grâce **2 h** ; alerte Telegram et e-mail (D-16) en cas d'échec (`/fail`) ou d'absence de signal.

### 6.3 Restauration depuis le hors site

```sh
AGE_IDENTITY=~/reyel16-sauvegarde-<prénom>.key \
scripts/db/offsite-restore.sh b2:reyel16-sauvegardes/production/daily/reyel16-<date>.tar.age reyel16_restauree
```

Téléchargement, déchiffrement (échoue si l'archive a été modifiée ou si la clé n'est pas la bonne), restauration
dans une **base nouvelle** (`restore.sh`, empreinte vérifiée), vérification (`verify.sh`), et pièces KYC extraites
**à part** (`KYC_RESTORE_DIR`), jamais dans le volume en service. La durée de chaque étape est affichée (RTO).

Liste des sauvegardes : `rclone lsl b2:reyel16-sauvegardes/production/daily/`.

### 6.4 Essai du 19 novembre 2026 — local — ✅ réussi

Deux clés d'essai (« Benginio », « Rubly »), destination : un dossier local.

| Contrôle | Résultat |
|---|---|
| Sauvegarde : dump, pièces KYC, chiffrement, envoi dans `daily/` et `monthly/` | ✅ 212 Ko en 4 à 8 s ; dossier de travail vide à la fin |
| Deuxième sauvegarde le même mois | ✅ pas de seconde copie dans `monthly/` |
| Restauration avec la clé « Rubly » dans une base nouvelle | ✅ 12 s : déchiffrement < 1 s, restauration 7 s, vérification 5 s (49 tables, 393 lignes, 4 déclencheurs, 5 portefeuilles cohérents) |
| Ouverture avec la clé « Benginio » | ✅ |
| Ouverture avec une autre clé | ✅ refusée |
| Archive modifiée d'un octet | ✅ refusée avant toute création de base |
| Clé privée dans le fichier des clés publiques | ✅ sauvegarde refusée |
| Restauration par-dessus `reyel16` | ✅ refusée (base protégée) |
| Copie de plus de 30 jours dans `daily/` | ✅ supprimée à la sauvegarde suivante |

Base d'essai, clés et dossiers supprimés à la fin. Corrigé pendant l'essai : sous Windows, `tar` lisait un chemin
`C:/…` comme une adresse réseau ; les archives sont désormais lues par l'entrée standard.

### 6.5 Reste à faire (phase 2, après V2-F01)

- Compte Backblaze B2 (clé d'application limitée au compartiment, sans droit de suppression du compte), cron et
  contrôle healthchecks.io sur le serveur de Production.
- **Archivage continu (WAL)** pour le RPO de 15 min et la restauration à un instant donné (D-10) : outil à décider
  (WAL-G proposé).
- Test de restauration sur le Staging, avec un volume réaliste (§5).

## 5. Test de restauration

### 29 octobre 2026, 06:18 UTC — environnement local Docker — ✅ réussi

| Étape | Résultat |
|---|---|
| Sauvegarde de `reyel16` (10 Mo, 34 tables, 189 lignes) | 112 Ko en **4 s** |
| Restauration dans la nouvelle base `reyel16_restore_test` (empreinte vérifiée) | **6 s** |
| Vérification | 34 tables et 189 lignes identiques au manifeste ; 4 déclencheurs présents ; ledger cohérent |
| Garde-fou : manifeste falsifié (`users` 999 au lieu de 6) | écart détecté, vérification en échec ✅ |
| Garde-fou : dump altéré (1 octet) | restauration refusée avant toute création de base ✅ |

**Durée totale sauvegarde + restauration + vérification : environ 15 s.**

Ce test valide les scripts et la procédure, pas le RTO de production : la base locale contient des données de
démonstration. **À refaire sur le staging** (P1-06) avec un volume réaliste (au moins un mois d'activité simulée) et
avec la restauration PITR du fournisseur retenu, puis **chaque trimestre** et avant le lancement (D-10 §5).

| Date | Environnement | Volume | Sauvegarde | Restauration | Vérification | Par |
|---|---|---|---|---|---|---|
| 2026-10-29 | Local (Docker, PostgreSQL 16) | 10 Mo | 4 s | 6 s | ✅ | Benginio |
| 2026-11-19 | Local, **hors site chiffré** (§6 ; dossier local à la place de B2) | 49 tables, 393 lignes ; archive 212 Ko | 4 à 8 s (chiffrement et envoi compris) | 12 s (déchiffrement compris) | ✅ | Benginio |
| _à venir_ | Staging | | | | | |
