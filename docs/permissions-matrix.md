# Matrice des permissions (rôle × action × ressource)

**Proposition à valider — décision [D-07](decisions/D-07-roles-admin.md) (tâche P0-06).** Chaque ligne validée doit avoir un test API (tâche P6-09).

> Implémentation : [`backend/config/permissions.php`](../backend/config/permissions.php) (P2-04) — synchronisé en base par
> `PermissionSeeder`. Toute modification de cette matrice se fait **dans la même PR** que la config.
> Vérification côté API : middleware `permission:<slug>` ou `Gate::allows('<slug>')`.
> Les permissions d'un rôle ne sont modifiables par aucune route : seule l'attribution des rôles l'est
> (`PUT /admin/users/{id}/roles`, Super Admin uniquement, jamais sur soi-même, motif obligatoire).

Légende : ✅ autorisé · 👁 lecture seule · 2️⃣ double validation · ❌ interdit

| Ressource / action | Super Admin | Opérations | Finance | Support | Conformité |
|---|---|---|---|---|---|
| Vue d'ensemble | 👁 | 👁 | 👁 | 👁 | 👁 |
| Événements & marchés — consulter | ✅ | ✅ | 👁 | 👁 | 👁 |
| Marché — suspendre / masquer (motif) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Résultats — saisir / valider | 2️⃣ | 2️⃣ | ❌ | ❌ | ❌ |
| Règlement — lancer / relancer | — | — | — | — | — |
| Utilisateurs — rechercher / consulter | ✅ | ❌ | 👁 | 👁 (email et téléphone masqués) | 👁 |
| Utilisateur — suspendre (motif) | ✅ | ❌ | ❌ | ✅ | ✅ |
| Tickets — consulter | ✅ | 👁 | 👁 | 👁 | 👁 |
| Ledger / rapprochement — consulter | 👁 | ❌ | ✅ | ❌ | 👁 |
| Correction financière | 2️⃣ | ❌ | 2️⃣ | ❌ | ❌ |
| Paramètres (limites, règles, fournisseur) | ✅ (versionné) | ❌ | ❌ | ❌ | 👁 |
| Limites jeu responsable | 👁 | ❌ | ❌ | ❌ | ✅ |
| Audit & exports | 👁 | ❌ | 👁 | ❌ | 👁 |
| Gérer les rôles des autres | ✅ (jamais les siens) | ❌ | ❌ | ❌ | ❌ |

Règles : aucun rôle ne peut s'accorder lui-même des permissions ; MFA obligatoire pour tous ces rôles ; 2️⃣ = l'initiateur et le validateur sont deux comptes différents (limites d'action : voir D-07 §3) ;
toute action ✅/2️⃣ sur une donnée sensible est journalisée avec motif.
Règlement : automatique (jobs), relancé seulement par la commande serveur `settlement:run` ; aucune route
d'administration en V1 (`settlement.run` / `settlement.approve` réservées).
