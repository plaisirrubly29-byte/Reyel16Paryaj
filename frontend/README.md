# Frontend REYEL 16 PAYAJ

React 19 + TypeScript + Vite. Site joueur et administration (`/admin`) dans la même application.
Règle d'or : **le frontend affiche, le serveur décide** (solde, cote acceptée, résultat, permissions).

## Commandes

| Commande | Rôle |
|---|---|
| `npm run dev` | Serveur de développement (http://localhost:5173) |
| `npm test` | Tests (Vitest + Testing Library) — `npm run test:watch` en continu |
| `npm run lint` | ESLint |
| `npm run build` | Vérification TypeScript + build de production |

Ouvrir le site via **`localhost`** (pas `127.0.0.1`) : les cookies de session Sanctum sont liés au nom d'hôte
et doivent être partagés entre le frontend (`localhost:5173`) et l'API (`localhost:8000`).

## Appeler l'API

Tous les appels passent par `src/shared/api/client.ts` :

```ts
// Lecture : TanStack Query (cache, chargement, erreurs)
const wallet = useQuery({
  queryKey: ['wallet'],
  queryFn: ({ signal }) => api<Envelope<Wallet>>('/wallet', { signal }),
});

// Écriture : useMutation + clé d'idempotence par intention (un clic = une clé)
const placeBet = useMutation({
  mutationFn: (input: BetInput) => api('/bets', { method: 'POST', body: input, idempotencyKey: key }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['wallet'] }),
});
```

- **CSRF** : géré automatiquement (cookie `XSRF-TOKEN` récupéré avant la première écriture, renouvelé sur 419).
- **Erreurs** : toute erreur est une `ApiError` avec `status`, `code` (stable, ex. `odds_changed`) et `details`.
  Décider du comportement sur `code`, jamais sur `message`. `error.fieldErrors` donne les erreurs par champ.
- **Clés de requête** : `[ressource, ...paramètres]`, ex. `['bets', { page }]`. Invalider après une mutation.
- Pas de nouvelle tentative automatique sur une erreur 4xx.

## Composants partagés (`src/shared/components`)

| Composant | Usage |
|---|---|
| `Button` | `variant` primary/secondary/danger/ghost ; `loading` désactive le bouton (pas de double envoi) |
| `TextField` | Libellé + erreur accessible : `<TextField label="Email" error={err?.fieldErrors.email} />` |
| `Alert` | Message info/success/warning/error |
| `ErrorAlert` | Affiche une `ApiError` ; bouton « Réessayer » seulement si l'erreur peut être temporaire |
| `DataTable` | Tableau générique ; devient une liste de cartes sur mobile |
| `Pagination` | À partir du `meta` des listes paginées de l'API |
| `MoneyText` | Affiche un `Money` (unité mineure) ; `signed` colore crédits/débits. Aucun calcul de solde |
| `Spinner` | Indicateur de chargement accessible |

## Organisation

```
src/
├── app/            router + layouts (public, admin)
├── features/<f>/   pages, components, api (hooks TanStack Query) par fonctionnalité
├── admin/          écrans d'administration
└── shared/         client API, composants, formatage
```

Responsive : points de rupture à 720 px (menu repliable, tableaux en cartes). Vérifier chaque écran
à 360 px de large avant de livrer.
