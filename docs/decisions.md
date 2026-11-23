# Registre des décisions

Décisions à valider avant le développement complet (§13 du document d'architecture).
Une décision non validée = la fonctionnalité concernée reste **désactivée**.

**Exception (2026-10-30)** : les décisions marquées ⏸ **Reportée** ne bloquent aucune fonctionnalité de la V1 (fonds fictifs).
Leur proposition, ou la valeur par défaut la plus protectrice, s'applique jusqu'à leur validation. Elles restent
**obligatoires avant tout argent réel**.

| # | Sujet | Tâche | Statut | Décision | Date | Validé par |
|---|---|---|---|---|---|---|
| D-01 | Territoire, cadre juridique, licences | P0-01 | ⏸ Reportée — proposition appliquée en V1 ([détail](decisions/D-01-D-09-cadre-juridique.md)) | Haïti seul ; V1 en fonds fictifs ; argent réel après licence écrite | | |
| D-02 | Fournisseur de données sportives et cotes (droits, limites, sandbox) | P0-02 | ✅ Validé ([détail](decisions/D-02-fournisseur-donnees.md)) | **The Odds API** ; offre gratuite en dev/Staging, offre payante ≥ 20 000 crédits/mois en production ; usage « paris » à confirmer par écrit avant l'argent réel | 2026-10-29 | Rubly PLAISIR |
| D-03 | Sports, compétitions et marchés V1 | P0-03 | ✅ Validé ([détail](decisions/D-03-D-04-catalogue-types-paris.md)) | 9 compétitions de football + NBA ; marchés 1X2 et plus/moins ; pas de paris en direct | 2026-10-29 | Rubly PLAISIR |
| D-04 | Types de paris V1 : simple seulement ou combiné | P0-03 | ✅ Validé ([détail](decisions/D-03-D-04-catalogue-types-paris.md)) | Simple et combiné (20 sélections max, une par match) | 2026-10-29 | Rubly PLAISIR |
| D-05 | Politique changement de cote / annulation / report / abandon / correction | P0-04 | ✅ Validé ([détail](decisions/D-05-cotes-annulations-corrections.md)) | Cote changée : refus + reconfirmation ; annulé/abandonné : cote 1 ; reporté > 48 h : cote 1 ; correction après règlement à double contrôle, 72 h max | 2026-10-29 | Rubly PLAISIR |
| D-06 | Devise, arrondi, mises min/max, plafonds de gain | P0-05 | ✅ Validé ([détail](decisions/D-06-devise-mises-plafonds.md)) | HTG fictifs ; mise 10 à 50 000 HTG ; gain max 1 000 000 HTG ; gain arrondi au centime inférieur | 2026-10-29 | Rubly PLAISIR |
| D-07 | Rôles admin, double validation, limites d'action | P0-06 | ✅ Validé ([détail](decisions/D-07-roles-admin.md)) | 5 rôles de la matrice ; double validation des corrections de résultat et financières ; MFA obligatoire ; 2 Super Admins au lancement | 2026-10-29 | Rubly PLAISIR |
| D-08 | Prestataires de paiement, frais, webhooks | P7-01 | ⏸ Reportée | Aucun prestataire en V1 (fonds fictifs) : dépôt et retrait affichés « Bientôt » | | |
| D-09 | Âge, vérification d'identité, jeu responsable, protection des données | P0-01 | ⏸ Reportée — proposition appliquée en V1 ([détail](decisions/D-01-D-09-cadre-juridique.md)) | 18 ans ; KYC avant tout dépôt réel ; jeu responsable dès la V1 | | |
| D-10 | Hébergement, disponibilité, sauvegardes, rétention | P0-07, V2-A03 | ✅ Validé ([détail](decisions/D-10-hebergement.md)) | Staging et Production chez **Hostinger** (VPS Docker, PostgreSQL en conteneur) ; 99,5 %, RPO 15 min par archivage WAL vers Backblaze B2, RTO 4 h ; budget ≤ 110 $/mois ; à revoir avant l'argent réel | 2026-11-16 | Rubly PLAISIR |
| D-11 | Jeux virtuels : football et courses | V2-D08 | 🟡 À décider — périmètre fixé ([détail](decisions/D-11-jeux-virtuels.md)) | Football virtuel et courses virtuelles ; prestataire tiers certifié ; avis juridique, prestataire et règles à obtenir ; jeux absents du site d'ici là | | |
| D-12 | Borlette : cadre juridique, règles des jeux, barème des gains, limites par boule | V2-D09 | 🟡 En attente d'un avis juridique ([détail](decisions/D-12-borlette.md)) | Tirages et résultats affichés (étape 1) ; vente fermée jusqu'à l'avis juridique (BL-1 à BL-6) ; code préparable derrière un interrupteur désactivé (décision du 2026-11-16) | 2026-11-16 | |
| D-13 | Agents : rôle, portefeuille, documents, limites et commissions | V2-G01, V2-G02 | ✅ Validé — option A ([détail](decisions/D-13-agents.md)) | Parrain (apporteur) seul au lancement, sans maniement d'argent ; personne ou commerce, compte à part avec MFA ; commission sur le revenu net des joueurs rattachés pendant 12 mois, retirée sur validation de la Finance ; taux et seuil à fixer ; encaissement en espèces et comptoir reportés | 2026-11-16 | Rubly PLAISIR |
| D-15 | Prestataires SMS et e-mail : codes de vérification, e-mails, notifications | V2-C01, V2-C02 | ✅ Validé ([détail](decisions/D-15-sms-email.md)) | Twilio Verify (WhatsApp d'abord, SMS en secours), expéditeur « REYEL16 » (CONATEL) ; e-mails Postmark (Resend en Staging) ; code 10 min, 5 essais ; budget 100 $/mois ; prix et CONATEL à confirmer | 2026-11-16 | Rubly PLAISIR |
| D-16 | Supervision : suivi des erreurs, surveillance externe et canaux d'alerte | V2-F03 | ✅ Validé ([détail](decisions/D-16-supervision.md)) | Sentry Team ; UptimeRobot toutes les minutes ; healthchecks.io (2 min) ; Telegram et e-mail, SMS pour les pannes graves ; astreinte Benginio et Rubly ; budget 60 $/mois | 2026-11-16 | Rubly PLAISIR |
| D-17 | Protection du site : bouclier réseau, robots, limites de fréquence | V2-F04 | ✅ Validé ([détail](decisions/D-17-protection-site.md)) | Cloudflare gratuit devant le Staging puis la Production (DNS chez Cloudflare) ; Turnstile sur l'inscription, le mot de passe oublié et la connexion après 3 échecs ; 300 requêtes/min par IP pour les visiteurs ; serveur joignable seulement via Cloudflare | 2026-11-16 | Rubly PLAISIR |
| D-18 | Politique de cotes : marge, arrondi, modification manuelle | V2-A04, V2-D03 | ✅ Validé ([détail](decisions/D-18-politique-cotes.md)) | Cote juste + marge de l'opérateur (6 % sur le 1X2 de football, 5 % marchés principaux, 8 % secondaires), avec l'argent réel seulement ; 2 décimales vers le bas, min. 1,01 ; modification par Opérations ou Super Admin avec motif, double validation au-delà de ±20 % | 2026-11-16 | Rubly PLAISIR |
| D-19 | Fidélité, parrainage et bonus : règles et anti-abus | V2-E07 | 🟡 À valider — options de gain et de récompense retenues ([détail](decisions/D-19-fidelite-parrainage.md)) | Proposition : 1 point / 100 HTG misés, paliers Bronze à Diamant, 100 points = 10 à 15 HTG de bonus ; 250 HTG de bonus au parrain par filleul qualifié (KYC, dépôt et mises ≥ 500 HTG), 20 par mois ; bonus à miser 3 fois (cote ≥ 1,50, 30 jours) ; désactivé jusqu'à V2-C05 | | |
| D-20 | Paris en direct : cotes fraîches, délai d'acceptation, suspension sur événement | V2-D06 | ✅ Validé — option « Modèle + The Odds » ([détail](decisions/D-20-paris-en-direct.md) §10) | Cotes du modèle maison (Poisson) recalées sur The Odds API, jamais mieux payées qu'elle, marge + 2 points ; football 1X2, plus/moins, score exact final et marchés maison R16 ; ticket « en attente d'acceptation » 8 s (5 à 15 s) puis accepté ou refusé (mise rendue), tolérance de cote 3 % ; suspension 10 s après un changement de score ; ouvert jusqu'au coup de sifflet final (sécurité à 150 min) ; simples seulement, mise max 10 000 HTG (1 000 HTG sur les marchés spéciaux) ; coupe-circuit `betting.allow_live` | 2026-11-22 | Rubly PLAISIR |

## Décisions techniques déjà prises

| # | Décision | Raison |
|---|---|---|
| T-01 | Monolithe modulaire Laravel, API versionnée `/api/v1` | Coût opérationnel faible, transactions simples, extraction possible plus tard |
| T-02 | Monorepo `backend/` + `frontend/` | Une PR peut couvrir API + écran ; CI unique |
| T-03 | Frontend unique (site joueur + `/admin`) | Code partagé ; l'API reste la seule barrière de sécurité |
| T-04 | Montants en entier (unité mineure) via `App\Support\Money` | Pas d'erreur d'arrondi float |
| T-05 | Authentification Sanctum en **mode SPA** (cookie de session HttpOnly + CSRF), confirmé en P2-02 ; aucun jeton API en V1 | Pas de jeton exposé au JavaScript (XSS), standard Laravel pour un frontend first-party |
