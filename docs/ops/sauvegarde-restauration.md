# Sauvegarde et restauration — P8-04

| | |
|---|---|
| **Tâche** | P8-04 — Sauvegarde/restauration testée et procédure d'incident (ticket #55) |
| **Référence** | D-10 (hébergement, sauvegardes, rétention) — proposition en attente de validation |
| **Scripts** | `scripts/db/backup.sh`, `scripts/db/restore.sh`, `scripts/db/verify.sh` |
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
| Dump complet **chiffré** (`backup.sh`) | Quotidien | 30 quotidiens + 12 mensuels | Stockage objet hors site (autre fournisseur) |
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
| _à venir_ | Staging | | | | | |
