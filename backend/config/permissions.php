<?php

/*
|--------------------------------------------------------------------------
| RBAC — permissions et rôles administratifs
|--------------------------------------------------------------------------
|
| Source de vérité, versionnée et relue en PR : AUCUNE route ne permet de modifier
| les permissions d'un rôle (un rôle ne peut donc pas s'accorder de permissions).
| Synchronisé en base par PermissionSeeder (idempotent).
|
| ⚠️ BROUILLON — transcription de docs/permissions-matrix.md, à confirmer par la
| décision D-07 (tâche P0-06). Toute modification ici doit aussi mettre à jour la matrice
| et les tests de permissions (P6-09).
|
| Convention : "<ressource>.<action>". "*.approve" = second validateur d'une double validation
| (il doit être différent de l'initiateur).
|
*/

return [

    'permissions' => [
        'dashboard.view' => "Vue d'ensemble",
        'events.view' => 'Consulter événements et marchés',
        'markets.suspend' => 'Suspendre / masquer un marché (motif)',
        'results.enter' => 'Saisir un résultat',
        'results.approve' => 'Valider un résultat saisi par un autre',
        'settlement.run' => 'Lancer / relancer un règlement',
        'settlement.approve' => 'Valider un règlement lancé par un autre',
        'users.view' => 'Rechercher / consulter les comptes (données minimales)',
        'users.view_personal_data' => 'Consulter les données personnelles complètes',
        'users.suspend' => 'Suspendre / réactiver un compte (motif)',
        'tickets.view' => 'Consulter les tickets',
        'ledger.view' => 'Consulter le ledger et le rapprochement',
        'finance.correct' => 'Initier une correction financière',
        'finance.approve' => 'Valider une correction financière initiée par un autre',
        'settings.view' => 'Consulter les paramètres',
        'settings.update' => 'Modifier les paramètres (versionnés)',
        'responsible_gaming.view' => 'Consulter les limites de jeu responsable',
        'responsible_gaming.manage' => 'Gérer les limites de jeu responsable',
        'audit.view' => "Consulter le journal d'audit",
        'audit.export' => "Exporter le journal d'audit",
        'roles.manage' => 'Attribuer les rôles des autres administrateurs (jamais les siens)',
    ],

    'roles' => [
        'super_admin' => [
            'dashboard.view', 'events.view', 'markets.suspend', 'results.enter', 'results.approve',
            'settlement.run', 'settlement.approve', 'users.view', 'users.view_personal_data', 'users.suspend',
            'tickets.view', 'ledger.view', 'finance.correct', 'finance.approve', 'settings.view',
            'settings.update', 'responsible_gaming.view', 'audit.view', 'audit.export', 'roles.manage',
        ],
        'operations' => [
            'dashboard.view', 'events.view', 'markets.suspend', 'results.enter', 'results.approve', 'tickets.view',
        ],
        'finance' => [
            'dashboard.view', 'events.view', 'settlement.run', 'settlement.approve', 'users.view',
            'users.view_personal_data', 'tickets.view', 'ledger.view', 'finance.correct', 'finance.approve',
            'audit.view', 'audit.export',
        ],
        'support' => [
            'dashboard.view', 'events.view', 'users.view', 'users.suspend', 'tickets.view',
        ],
        'compliance' => [
            'dashboard.view', 'events.view', 'users.view', 'users.view_personal_data', 'users.suspend',
            'tickets.view', 'ledger.view', 'settings.view', 'responsible_gaming.view',
            'responsible_gaming.manage', 'audit.view', 'audit.export',
        ],
    ],

];
