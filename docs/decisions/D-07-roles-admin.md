# D-07 — Rôles administratifs, double validation et limites d'action

| | |
|---|---|
| **Tâche** | P0-06 (débloque P2-04 RBAC, P6-09 tests de la matrice) |
| **Statut** | 🟡 Proposition à valider |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-10-29 |
| **Tranche aussi** | Points M3 et I2 de la revue de sécurité [P8-02](../security/P8-02-revue-securite.md) |

## 1. Rôles

Cinq rôles, tels que définis dans [`config/permissions.php`](../../backend/config/permissions.php) et la
[matrice des permissions](../permissions-matrix.md) : **Super Admin, Opérations, Finance, Support, Conformité**.
Un administrateur peut cumuler plusieurs rôles. **MFA (TOTP) obligatoire** pour tout compte ayant un rôle.

Au lancement : **deux Super Admins** (Rubly PLAISIR et Benginio Jean-Baptiste), nommés par
`php artisan identity:grant-role`, pour que toute double validation ait toujours un second validateur.

## 2. Actions à double validation

L'initiateur et le validateur sont **deux comptes différents** ; le serveur refuse qu'une même personne valide
sa propre demande.

| Action | Initie | Valide |
|---|---|---|
| Correction d'un résultat déjà réglé (D-05 §5) | `results.enter` (Super Admin, Opérations) | `results.approve` (Super Admin, Opérations) |
| Correction financière (écriture compensatoire sur un portefeuille) | `finance.correct` (Super Admin, Finance) | `finance.approve` (Super Admin, Finance) |

Le **règlement** des tickets est automatique (jobs) ; il n'est relancé que par la commande serveur
`settlement:run`. Aucune route d'administration ne lance de règlement en V1 : les permissions
`settlement.run` / `settlement.approve` sont réservées pour plus tard.

## 3. Limites d'action

| Règle | État |
|---|---|
| Motif obligatoire pour toute action sensible (suspension, masquage de marché, correction, attribution de rôle) ; journalisé dans l'audit | ✅ Dans le code |
| Personne ne modifie ses propres rôles ; les permissions d'un rôle ne changent que dans le code, par PR | ✅ Dans le code |
| Seul un Super Admin suspend un autre administrateur | ✅ Dans le code |
| **M3** — On ne peut ni retirer son rôle ni suspendre le **dernier** Super Admin | ⚠️ À coder |
| **M3** — Un rôle reçu depuis **moins de 24 h** ne permet pas de valider (`*.approve`) : un Super Admin ne peut pas créer un second compte et s'en servir aussitôt pour valider ses propres demandes | ⚠️ À coder |
| **M3** — Chaque attribution ou retrait de rôle crée une alerte visible par tous les Super Admins (centre d'alertes) | ⚠️ À coder |

## 4. Données visibles par le Support (I2)

Le Support identifie un joueur qui le contacte, sans accès à ses données personnelles complètes :

| Donnée | Support (`users.view`) | Finance, Conformité, Super Admin (`users.view_personal_data`) |
|---|---|---|
| Nom affiché, statut du compte, dates d'inscription et de dernière connexion | ✅ | ✅ |
| Email | Masqué : `ja•••@gmail.com` | ✅ Complet |
| Téléphone | Masqué : 4 derniers chiffres `•••• 1234` | ✅ Complet |
| Prénom, nom, date de naissance, pays, rôles, MFA | ❌ | ✅ |

La recherche par email ou téléphone complet reste possible pour le Support (le joueur donne ses coordonnées),
mais la réponse est masquée. ⚠️ **À coder** : aujourd'hui `AdminUserResource` renvoie l'email et le
téléphone complets au Support.

## 5. Critères d'acceptation P0-06

- [x] Matrice validée — voir [permissions-matrix.md](../permissions-matrix.md) (à confirmer par Rubly)
- [x] Actions nécessitant une double validation identifiées (§2)
