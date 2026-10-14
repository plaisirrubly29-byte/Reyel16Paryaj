# Module Betting Catalogue

**Namespace :** `App\Modules\Catalogue`
**Livraison :** Phase 3
**Responsable :** _à assigner_

## Responsabilités

- Exposer uniquement les événements et marchés ouverts et suffisamment récents.
- Fournir horodatage et état de disponibilité de chaque cote.

## Dépendances

- Sports Data Adapter

## Tables (modèle de données initial)

- (lecture des tables de SportsData)

## Routes prévues

- `GET /api/v1/sports`
- `GET /api/v1/competitions`
- `GET /api/v1/events`
- `GET /api/v1/events/{id}/markets`

## Règles à respecter

- Filtrer les événements fermés et les cotes périmées.
- Lecture seule, cache court (Redis) autorisé.

## Organisation

```
Catalogue/
├── Http/Controllers   # contrôleurs minces : validation -> service -> resource
├── Http/Requests      # FormRequest (validation des entrées)
├── Http/Resources     # format JSON de sortie
├── Models             # modèles Eloquent du module
├── Services           # logique métier (testée en unitaire)
└── routes.php         # routes du module (/api/v1)
```

Migrations : `database/migrations`.
Tests : `tests/Feature/Modules/Catalogue` et `tests/Unit/Modules/Catalogue`.
