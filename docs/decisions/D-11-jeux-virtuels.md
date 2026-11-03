# D-11 — Jeux virtuels : football et courses, par un prestataire tiers

| | |
|---|---|
| **Tâche** | V2-D08 (débloque les issues #208 backend et #209 écrans) |
| **Statut** | 🟡 À décider — périmètre fixé le 2026-11-02, prestataire et avis juridique à obtenir |
| **Décideur** | Rubly PLAISIR (chef de projet), sur avis écrit d'un conseil juridique du territoire visé |
| **Préparé le** | 2026-11-02 |
| **Dépend de** | D-01/D-09 (cadre juridique), D-06 (devise, mises, plafonds) |

> Ce document n'est pas un avis juridique. Il structure la décision et liste les questions à poser au conseil
> juridique et aux prestataires. Aucune règle de jeu (fréquence, marchés, cotes, taux de retour) n'est fixée ici :
> elles viennent du prestataire retenu et sont reportées au §5 une fois connues.

## 1. Besoin

Proposer aux joueurs des paris sur des épreuves simulées, disponibles à toute heure, en plus des paris sportifs.
Aujourd'hui, `docs/architecture.md` exclut par défaut « casino/loto/borlette/courses/streaming/paris en direct ». Rien
n'existe dans le code ni dans le contrat de l'API.

## 2. Déjà décidé (Rubly PLAISIR, 2026-11-02)

| Point | Choix |
|---|---|
| Jeux | **Football virtuel** et **courses virtuelles** (chevaux ou lévriers, selon l'offre du prestataire) |
| Fournisseur | **Prestataire tiers** : il produit les tirages et l'animation. Pas de générateur aléatoire maison. |
| Place dans le plan | Phase D, priorité **P3** (après le lancement de la V2) |

## 3. Questions au conseil juridique

| # | Question | Réponse (avis écrit) |
|---|---|---|
| JV-1 | Les jeux virtuels sont-ils couverts par l'autorisation de paris sportifs (J-1), ou relèvent-ils d'une autre catégorie (jeux de hasard, casino) avec une licence à part ? | |
| JV-2 | Le prestataire doit-il être agréé ou déclaré sur le territoire ? Faut-il une certification du générateur aléatoire par un laboratoire accrédité ? | |
| JV-3 | Obligations propres : âge, limites de mise, messages de jeu responsable, fréquence maximale des épreuves ? | |
| JV-4 | Fiscalité des mises et des gains sur les jeux virtuels, si elle diffère des paris sportifs (J-10). | |
| JV-5 | Peut-on lever l'exclusion de `docs/architecture.md` pour ces deux jeux seulement ? | |

## 4. Critères de choix du prestataire

À remplir pour chaque prestataire consulté. Aucun n'est retenu à ce jour.

| Critère | Ce qu'on vérifie |
|---|---|
| Licence et certification | Licences détenues, certificat du générateur aléatoire, laboratoire et date |
| Jeux proposés | Football virtuel, courses ; marchés de chaque jeu ; durée et fréquence des épreuves |
| Intégration | API, widget ou flux vidéo ; résultats signés ; reprise après coupure |
| Portefeuille | Débit et crédit appelés sur **notre** ledger (portefeuille partagé) ou transfert de fonds vers celui du prestataire |
| Mobile en Haïti | Poids du flux vidéo, mode dégradé sans vidéo, comportement en 3G |
| Langues | Français, créole |
| Coût | Frais fixes, partage de revenus, minimum mensuel, durée d'engagement |
| Contrat | Usage autorisé sur le territoire, responsabilités en cas de résultat erroné, accès sandbox |

## 5. Règles des jeux

Reprises du prestataire retenu et validées par écrit. Rien n'est fixé avant.

| Jeu | Durée d'une épreuve | Fréquence | Marchés | Taux de retour joueur | Mise min / max |
|---|---|---|---|---|---|
| Football virtuel | | | | | |
| Courses virtuelles | | | | | |

## 6. Conséquences techniques (proposition, à confirmer avec le prestataire)

- **Backend (Benginio)** : un module à part (`backend/app/Modules/VirtualGames`) qui parle au prestataire. Chaque mise
  et chaque gain passe par le ledger avec une clé d'idempotence, comme les paris sportifs. Les limites de jeu
  responsable (P7-02, V2-B06, V2-E06) et les plafonds (D-06, V2-D05) s'appliquent aussi aux jeux virtuels. Le contrat
  est écrit d'abord dans `docs/api/openapi.yaml`.
- **Frontend (Rubly)** : un accueil des jeux virtuels, l'écran d'une épreuve (widget ou flux du prestataire, coupon,
  compte à rebours fourni par le serveur), les résultats, et les paris virtuels dans « Mes paris ». Les montants
  et les cotes sont affichés tels que le serveur les renvoie.
- **Tant que JV-1 et JV-5 n'ont pas de réponse**, le menu « Jeux virtuels » reste absent du site, même en Staging.

## 7. Conditions pour passer à « Validée »

1. Avis juridique écrit sur JV-1 à JV-5.
2. Prestataire retenu, contrat signé, accès sandbox obtenu.
3. Règles du §5 remplies d'après la documentation du prestataire.
4. `docs/architecture.md` mis à jour pour retirer les deux jeux de la liste des exclusions.

## 8. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |
