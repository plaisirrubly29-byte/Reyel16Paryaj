# REYEL 16 PAYAJ

Plateforme de paris sportifs multi-sports : site web responsive, espace joueur et panneau d'administration,
alimentés par une API de données sportives (matchs, cotes, résultats).

> ⚠️ **V1 en développement — données et fonds fictifs uniquement.** Les paiements, retraits et paris réels
> restent désactivés jusqu'à validation juridique, contractuelle et fournisseur.

## Structure du dépôt

```
.
├── backend/                 API Laravel 13 (PHP 8.3) — monolithe modulaire
│   └── app/Modules/         10 modules métier (un README par module)
├── frontend/                React 19 + TypeScript (Vite) — site joueur + /admin
├── docs/
│   ├── architecture.md      résumé de l'architecture + règles non négociables
│   ├── architecture/        document de référence (.docx)
│   ├── TASKS.md             plan de tâches par phase et par rôle (généré)
│   ├── decisions.md         décisions à valider (Phase 0)
│   ├── permissions-matrix.md
│   └── api/openapi.yaml     contrat de l'API v1
├── scripts/github/          tasks.json + script de création des issues GitHub
├── .github/                 CI, modèles d'issues et de PR, CODEOWNERS
└── docker-compose.yml       PostgreSQL, Redis, backend, queue, frontend
```

## Démarrage rapide

### Option A — Docker (recommandé)

Prérequis : Docker Desktop.

```bash
cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec backend composer install
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
```

- API : http://localhost:8000/api/v1/health
- Frontend : http://localhost:5173 — admin : http://localhost:5173/admin

### Option B — Installation locale

Prérequis : **PHP 8.3+** (avec `pdo_pgsql`), Composer 2, Node 22, PostgreSQL 16, Redis 7.

> XAMPP livre souvent PHP 8.0/8.1 : trop ancien pour Laravel 13. Utiliser Docker, Laragon ou `php.new`.

```bash
# Backend
cd backend
cp .env.example .env        # ajuster DB_* et REDIS_*
composer install
php artisan key:generate
php artisan migrate
php artisan serve           # http://localhost:8000

# Queue (autre terminal)
php artisan queue:work

# Frontend (autre terminal)
cd frontend
cp .env.example .env
npm install
npm run dev                 # http://localhost:5173
```

## Commandes utiles

| Où | Commande | Rôle |
|---|---|---|
| backend | `php artisan test` | Tests |
| backend | `vendor/bin/pint` | Formatage du code PHP |
| frontend | `npm run lint` | ESLint |
| frontend | `npm run build` | Vérification TypeScript + build |
| racine | `node scripts/github/bootstrap.mjs --markdown` | Régénérer `docs/TASKS.md` |
| racine | `node scripts/github/bootstrap.mjs --github` | Créer labels, milestones et issues |

## Organisation de l'équipe

1. Lire [`docs/architecture.md`](docs/architecture.md) puis le README du module sur lequel vous travaillez.
2. Prendre une issue assignée (voir [`docs/TASKS.md`](docs/TASKS.md) et le tableau GitHub).
3. Suivre [`CONTRIBUTING.md`](CONTRIBUTING.md) : branche, commits, PR, définition de « terminé ».

## Stack

Laravel 13 · PHP 8.3 · Sanctum · PostgreSQL 16 · Redis 7 · React 19 · TypeScript · Vite · React Router · GitHub Actions

---

Azed Group — document et code destinés à l'équipe de programmation.
