# D-01 / D-09 — Territoire, cadre juridique, licences et obligations joueur

| | |
|---|---|
| **Tâche** | P0-01 |
| **Statut** | 🟡 Proposition à valider — **aucune activité en argent réel tant que ce document n'est pas validé par écrit** |
| **Décideur** | Rubly PLAISIR (chef de projet), sur avis écrit d'un conseil juridique du territoire visé |
| **Préparé le** | 2026-10-15 |

> Ce document n'est pas un avis juridique. Il structure la décision, liste les questions à poser au
> conseil juridique et propose les choix techniques qui en découlent. Les cases « Réponse » sont à
> remplir à partir de l'avis écrit obtenu.

## 1. Proposition

1. **Territoire de lancement unique** pour la V1 — hypothèse de travail : **Haïti** (devise `HTG` déjà retenue
   dans `.env.example`). Aucun autre pays n'est ouvert sans nouvelle décision.
2. **V1 = paris simulés uniquement** (fonds fictifs), comme prévu par l'architecture : pas de dépôt, retrait
   ni pari en argent réel. Cela permet de développer et recetter toute la plateforme pendant l'instruction
   juridique.
3. **Passage à l'argent réel** (P7-01) conditionné à : licence/autorisation écrite obtenue, contrat
   fournisseur de données autorisant l'usage « paris », prestataire de paiement contractualisé, et
   validation de ce document.
4. **Obligations joueur retenues par défaut** (les plus protectrices, à assouplir seulement si l'avis
   juridique le permet) : voir §3.

## 2. Questions à poser au conseil juridique

| # | Question | Réponse (avis écrit) |
|---|---|---|
| J-1 | Quelle autorité délivre les autorisations de paris sportifs en ligne sur le territoire ? Quel type de licence (opérateur, en ligne, paris sportifs) ? | |
| J-2 | Une plateforme en **fonds fictifs** (sans mise réelle) nécessite-t-elle une autorisation ? Peut-on l'ouvrir au public avant la licence ? | |
| J-3 | Délais, coûts, garanties financières et capital exigés pour la licence. | |
| J-4 | Âge légal minimum pour parier (hypothèse : 18 ans). | |
| J-5 | Vérification d'identité (KYC) : à quel moment (inscription, premier dépôt, premier retrait, seuil) ? Pièces acceptées ? | |
| J-6 | Obligations de lutte contre le blanchiment (LCB-FT) : seuils de déclaration, durée de conservation des pièces et transactions. | |
| J-7 | Restrictions territoriales : faut-il bloquer les joueurs hors territoire (géolocalisation IP, pays de résidence) ? | |
| J-8 | Jeu responsable : limites de dépôt/mise, pause, auto-exclusion, messages obligatoires, registre national d'exclus ? | |
| J-9 | Protection des données personnelles : loi applicable, déclaration à une autorité, droits des joueurs (accès, rectification, suppression), hébergement des données hors du pays autorisé ? | |
| J-10 | Fiscalité : taxe sur les mises ou le produit brut des jeux, retenue sur les gains ? | |
| J-11 | Publicité et promotions (bonus) : restrictions ? | |
| J-12 | Conditions générales et règlement des paris : mentions obligatoires, langue (français / créole) ? | |

## 3. Obligations proposées (valeurs par défaut)

| Obligation | Choix par défaut | Où c'est implémenté | État |
|---|---|---|---|
| Âge minimum | 18 ans, date de naissance obligatoire à l'inscription | `IDENTITY_MINIMUM_AGE`, `RegisterRequest` (P2-02) | ✅ en place, valeur à confirmer (J-4) |
| Vérification d'identité (KYC) | Non requise en fonds fictifs ; **obligatoire avant tout dépôt réel** | Module Compliance (P7) | ⏳ à concevoir selon J-5 |
| Territoire | Pays de résidence collecté (`user_profiles.country`) ; blocage géographique à décider | P2-01 / P7 | ⏳ selon J-7 |
| Jeu responsable | Limites de mise, pause et auto-exclusion disponibles dès la V1 (même en fictif) | P7-02 ; statut `excluded` déjà prévu | ⏳ P7-02 |
| Données personnelles | Minimisation (profil séparé du compte), accès par rôle, audit des consultations sensibles | P2-01, P2-04, P6-01 | ✅ socle en place |
| Conservation | Ledger, tickets et journal d'audit conservés au moins 5 ans (hypothèse LCB-FT, à confirmer J-6) | D-10 | ⏳ selon J-6 |
| Argent réel | Désactivé (aucun module Payments actif) | P7-01 bloqué | ✅ |

## 4. Conséquences si la proposition est validée

- Le développement continue sans changement (V1 en fonds fictifs).
- Les réponses J-4 à J-9 alimentent P7-02 (jeu responsable) et la conception KYC.
- J-9 conditionne le choix de l'hébergeur (D-10, localisation des données).

## 5. Validation

| Rôle | Nom | Date | Signature / lien vers l'avis |
|---|---|---|---|
| Conseil juridique | | | |
| Chef de projet | Rubly PLAISIR | | |
