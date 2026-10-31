# Module Compliance & Responsible Play

**Namespace :** `App\Modules\Compliance`
**Livraison :** Phase 7
**Responsable :** Benginio Jean-Baptiste (P7-02)

## Responsabilités

- Contrôles d’âge/éligibilité si requis.
- Jeu responsable (P7-02) : limites de mise, pause, auto-exclusion ; contrôle avant chaque pari.
- Journal des événements de jeu responsable.

## Dépendances

- IdentityAccess (`AccountStatusService::selfExclude`)
- Betting fournit les mises du joueur via le contrat `Contracts\StakeHistory` (lié dans `AppServiceProvider`) :
  Betting appelle Compliance, jamais l'inverse.

## Tables

- `betting_limits` : une limite par joueur et par période glissante (day, week, month), hausse ou suppression en attente
- `responsible_gaming_restrictions` : pauses et auto-exclusions
- `responsible_play_events` : journal en ajout seul

## Routes

- `GET /api/v1/responsible-gaming` : limites, mises de la période, pause, options
- `PUT /api/v1/responsible-gaming/limits/{period}`, `POST /api/v1/responsible-gaming/pause`,
  `POST /api/v1/responsible-gaming/self-exclusion` (idempotentes)
- Conformité (module Admin) : `GET /api/v1/admin/users/{id}/responsible-gaming` (responsible_gaming.view, consultation
  auditée) ; `POST /api/v1/admin/users/{id}/responsible-gaming/reopen` (responsible_gaming.manage, motif, après l'échéance)

## Règles à respecter

- Les exigences dépendent du territoire autorisé (décision D-01 / D-09, question J-8). En attendant l'avis juridique,
  valeurs les plus protectrices dans `config/compliance.php` :
  - baisse de limite immédiate ; hausse ou suppression après 7 jours ;
  - pause de 24 h, 7 j ou 30 j, irrévocable (prolongeable) ; connexion possible, aucun pari ;
  - auto-exclusion de 6 mois, 1 an ou 5 ans, irrévocable, mot de passe requis ; compte « excluded » (aucune connexion),
    réouverture uniquement sur demande après l'échéance.
- `ResponsibleGamingService::assertCanBet()` s'exécute dans la transaction du pari, avec un verrou sur le joueur :
  ses paris simultanés ne peuvent pas dépasser ensemble une limite.

## Organisation

```
Compliance/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations` (ex. `php artisan make:migration create_limits_table`).
Tests : `tests/Feature/Modules/Compliance` et `tests/Unit/Modules/Compliance`.
