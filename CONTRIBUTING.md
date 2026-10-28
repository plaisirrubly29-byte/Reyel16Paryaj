# Guide de contribution

## Branches

| Branche | Usage |
|---|---|
| `main` | Production. Protégée : PR obligatoire, 1 revue, CI verte. |
| `develop` | Intégration → déployée sur Staging. Protégée. |
| `feature/<ID>-<court-nom>` | Une tâche, ex. `feature/P4-03-place-bet` |
| `fix/<ID ou n° issue>-<court-nom>` | Correction de bug |

Partir de `develop`, ouvrir la PR vers `develop`. `develop` → `main` uniquement pour une release.

Protection de `main` et `develop` (PR obligatoire, 1 revue du propriétaire de code, CI verte, pas de push direct,
même pour les admins) : `bash scripts/github/protect-branches.sh`. Les relecteurs sont demandés automatiquement
selon [`.github/CODEOWNERS`](.github/CODEOWNERS) : chaque PR est relue par l'autre développeur.

```bash
git checkout develop && git pull
git checkout -b feature/P2-02-auth-api
```

## Commits (Conventional Commits)

```
<type>(<module>): <description courte>

feat(betting): revalider la cote côté serveur avant création du ticket
fix(wallet): empêcher le double débit sur relance
test(identity): couvrir l'accès direct par identifiant
docs(api): documenter POST /bets
```

Types : `feat`, `fix`, `test`, `refactor`, `docs`, `chore`, `ci`.

## Pull requests

- Une PR = une tâche (ou une partie clairement délimitée). Mentionner `Closes #<issue>`.
- Remplir la checklist du modèle de PR.
- Au moins **1 relecteur** ; **2** pour `Wallet`, `Settlement`, `Payments` et tout ce qui touche à l'argent.
- Pas de merge si la CI est rouge.

## Conventions de code

**Backend**
- Code dans `app/Modules/<Module>/` ; contrôleurs minces, logique dans `Services/`.
- Validation par `FormRequest`, sortie par `JsonResource`.
- Un module n'écrit jamais dans les tables d'un autre module : il appelle son service.
- Montants : `App\Support\Money` + colonne `bigInteger` + devise. Jamais de `float`.
- Toute route à effet : transaction SQL + `Idempotency-Key`.
- Formatage : `vendor/bin/pint` avant de pousser.

**Frontend**
- Code par fonctionnalité : `src/features/<feature>/{pages,components,api}` ; admin dans `src/admin/`.
- Tous les appels HTTP passent par `src/shared/api/client.ts`.
- Le frontend **affiche** ; il ne calcule ni solde, ni gain définitif, ni résultat.

**API**
- Mettre à jour `docs/api/openapi.yaml` dans la même PR que la route.

## Définition de « terminé » (§14)

Une fonctionnalité n'est pas terminée parce que son interface s'affiche. Elle est terminée lorsque :

1. les règles métier sont approuvées ;
2. les données persistées sont correctes ;
3. les permissions sont appliquées côté serveur ;
4. les cas limites sont testés ;
5. les opérations sensibles sont auditées ;
6. la fonctionnalité est validée en Staging.

Toute dépendance légale ou fournisseur non confirmée reste **explicitement désactivée**.

## Sécurité

- Jamais de secret, clé API, mot de passe ou donnée personnelle réelle dans le dépôt.
- `.env` n'est jamais commité ; ajouter les nouvelles variables (sans valeur) dans `.env.example`.
- Signaler une faille directement au responsable technique, pas dans une issue publique.
