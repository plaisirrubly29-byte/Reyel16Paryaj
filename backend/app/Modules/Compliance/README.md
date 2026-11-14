# Module Compliance & Responsible Play

**Namespace :** `App\Modules\Compliance`
**Livraison :** Phase 7
**Responsable :** Benginio Jean-Baptiste (P7-02)

## Responsabilités

- Contrôles d’âge/éligibilité si requis.
- Jeu responsable (P7-02) : limites de mise, pause, auto-exclusion ; contrôle avant chaque pari.
- Limites de dépôt (V2-B06) : même règle que les limites de mise ; contrôle avant chaque dépôt.
- Journal des événements de jeu responsable.
- AML (V2-C04) : règles d'alerte sur les dépôts et retraits, file de revue de la Conformité.

## Dépendances

- IdentityAccess (`AccountStatusService::selfExclude`)
- Betting fournit les mises du joueur via le contrat `Contracts\StakeHistory` (lié dans `AppServiceProvider`) :
  Betting appelle Compliance, jamais l'inverse.
- Wallet fournit les dépôts du joueur via le contrat `Contracts\DepositHistory` (même principe) ; le dépôt enregistré
  par l'admin (module Admin) appelle `assertCanDeposit()`, comme le feront les dépôts MonCash (V2-B02). Wallet envoie
  aussi le job `Jobs\CheckAmlRules` après chaque dépôt ou retrait validé (`LedgerService`).
- Audit (`AuditLogger`) pour la revue des alertes AML.

## Tables

- `betting_limits` : une limite par joueur et par période glissante (day, week, month), hausse ou suppression en attente
- `deposit_limits` : limites de dépôt, mêmes colonnes (modèle `DepositLimit`, qui étend `BettingLimit`)
- `responsible_gaming_restrictions` : pauses et auto-exclusions
- `responsible_play_events` : journal en ajout seul
- `aml_alerts` : une alerte par règle et par écriture du ledger, ouverte puis classée ou signalée (motif, relecteur)

## Routes

- `GET /api/v1/responsible-gaming` : limites, mises de la période, pause, options
- `PUT /api/v1/responsible-gaming/limits/{period}`, `PUT /api/v1/responsible-gaming/deposit-limits/{period}`,
  `POST /api/v1/responsible-gaming/pause`,
  `POST /api/v1/responsible-gaming/self-exclusion` (idempotentes)
- Conformité (module Admin) : `GET /api/v1/admin/users/{id}/responsible-gaming` (responsible_gaming.view, consultation
  auditée) ; `POST /api/v1/admin/users/{id}/responsible-gaming/reopen` (responsible_gaming.manage, motif, après l'échéance)
- Alertes AML (module Admin) : `GET /api/v1/admin/aml/alerts`, `GET /api/v1/admin/aml/alerts/{id}` (aml.view : Conformité,
  Super Admin, Finance) ; `POST /api/v1/admin/aml/alerts/{id}/review` (aml.review : Conformité, motif)

## Règles à respecter

- Les exigences dépendent du territoire autorisé (décision D-01 / D-09, question J-8). En attendant l'avis juridique,
  valeurs les plus protectrices dans `config/compliance.php` :
  - baisse de limite immédiate ; hausse ou suppression après 7 jours ;
  - pause de 24 h, 7 j ou 30 j, irrévocable (prolongeable) ; connexion possible, aucun pari ;
  - auto-exclusion de 6 mois, 1 an ou 5 ans, irrévocable, mot de passe requis ; compte « excluded » (aucune connexion),
    réouverture uniquement sur demande après l'échéance.
- `ResponsibleGamingService::assertCanBet()` s'exécute dans la transaction du pari, avec un verrou sur le joueur :
  ses paris simultanés ne peuvent pas dépasser ensemble une limite.
- `ResponsibleGamingService::assertCanDeposit()` fait de même dans la transaction du dépôt (422
  `deposit_limit_exceeded`, refus journalisé avec l'admin qui enregistrait le dépôt). Les retraits ne sont pas limités.
- AML : seuils PROVISOIRES dans `config/compliance.php` (`aml`), en attendant l'avis juridique (question J-6) :
  - `large_deposit` : un dépôt d'au moins 50 000 HTG ;
  - `cumulative_deposits` : ce dépôt porte les dépôts des 7 derniers jours à 150 000 HTG ou plus (une alerte au
    franchissement, pas à chaque dépôt suivant) ;
  - `withdrawal_without_play` : un retrait alors que les dépôts des 72 dernières heures ont été misés à moins de 10 %.
- Une alerte ne bloque rien : la Conformité la classe (`dismissed`) ou la signale (`reported`), une seule fois, avec un
  motif audité ; elle suspend le compte si besoin (`PATCH /admin/users/{id}`). Jamais une alerte sur son propre compte.
- Le contrôle s'exécute en file, après la validation de l'écriture : un dépôt annulé n'est pas contrôlé ; un job rejoué
  ne crée pas de seconde alerte (unique par règle et par écriture). Un job en échec définitif apparaît dans les alertes
  techniques de l'administration (`failed_jobs`).

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
