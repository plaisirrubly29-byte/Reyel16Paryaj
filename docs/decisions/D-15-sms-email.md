# D-15 — Prestataires SMS et e-mail : codes de vérification, e-mails et notifications

| | |
|---|---|
| **Tâche** | V2-C01 (#150 code SMS), V2-C02 (#152 vrais e-mails) ; débloque V2-E04 (#182 notifications) |
| **Statut** | 🟡 À décider — comparatif et recommandation proposés |
| **Décideur** | Rubly PLAISIR (chef de projet) |
| **Préparé le** | 2026-11-13 |
| **Dépend de** | D-01/D-09 (données personnelles, J-9), D-10 (hébergeur : choix de l'e-mail selon le fournisseur retenu) |

> Ce document compare des prestataires à partir de leurs pages publiques et de comparatifs (novembre 2026). Les prix
> varient d'une source à l'autre : **ils sont à confirmer sur la page officielle de chaque prestataire, ou par un
> devis, avant la décision.** Aucun compte n'est ouvert et aucun code n'est écrit tant que D-15 n'est pas validée.
> D-14 est réservée à la roulette et au casino (#236).

## 1. Besoin

| Usage | Tâche | Canal | Volume au lancement (hypothèse à ajuster) |
|---|---|---|---|
| Code de vérification du téléphone à l'inscription et au changement de numéro | V2-C01 | SMS (ou WhatsApp) | ~2 000 inscriptions par mois, ~1,3 envoi par inscription (renvois) : ~2 600 messages |
| Vérification de l'adresse e-mail, réinitialisation du mot de passe | V2-C02 | E-mail | ~3 000 e-mails par mois |
| Notifications : ticket gagnant, dépôt, retrait, KYC | V2-E04 | E-mail ou SMS, au choix du joueur | ~10 000 e-mails par mois ; SMS seulement si le joueur le choisit |
| Alertes d'exploitation (équipe) | P9-02 | E-mail et Telegram | Faible ; déjà en place, à brancher sur le même prestataire e-mail |

## 2. Déjà en place

- **Aucun e-mail ne part** : `MAIL_MAILER=log` en développement ; en Staging et en Production, `smtp` sans prestataire
  choisi.
- La réinitialisation du mot de passe existe déjà (`ResetPasswordNotification`, mise en file) : elle partira dès qu'un
  prestataire sera configuré.
- **Aucun SMS** : le téléphone (8 chiffres, Haïti seulement) sert d'identifiant mais n'est pas vérifié.
- Les numéros acceptés sont uniquement haïtiens (+509) : cela limite déjà la fraude aux SMS vers l'étranger (§6).

## 3. SMS vers Haïti

Deux opérateurs : **Digicel** et **Natcom**. Aucun des deux ne publie d'API pour les entreprises : on passe par un
prestataire international qui a des accords avec eux.

| Prestataire | Prix par SMS vers Haïti (trouvé) | Points forts | Points faibles |
|---|---|---|---|
| **Twilio** | 0,17 $ (grille officielle) à 0,29 $ (page Haïti) — à confirmer | Documentation de référence ; produit « Verify » qui gère les codes, les renvois et la fraude ; WhatsApp chez le même prestataire | Le plus cher si le prix de la page Haïti est le bon |
| **Infobip** | ~0,29 $ | Présent dans la Caraïbe ; SMS et WhatsApp | Prix élevé ; contrat souvent sur devis |
| **Vonage** | non publié pour Haïti | Canal officiel Laravel (`laravel/vonage-notification-channel`) | Prix à demander |
| **Plivo** | ~0,18 $ (source non vérifiée) | Moins cher | Moins d'information sur la livraison en Haïti |
| **Agrégateurs** (BulkGate, Releans, Sempico…) | 0,16 à 0,32 $ | Prix bas | Fiabilité et support variables ; à éviter pour des codes de connexion |

**Coût estimé** : ~2 600 SMS par mois × 0,17 à 0,29 $ = **~450 à 750 $ par mois**, pour les seules vérifications.

**Réglementation (CONATEL)** : un nom d'expéditeur (« REYEL16 », 11 caractères au plus) doit être **enregistré
avant usage** : lettre d'autorisation, nom conforme à celui de l'entreprise, 2 à 4 semaines selon les sources.
Natcom remplacerait parfois l'expéditeur par un nom générique. **Aucun texte officiel du CONATEL n'a été trouvé** :
à confirmer avec le CONATEL ou le prestataire retenu (SEJ-1).

## 4. WhatsApp pour les codes

WhatsApp est très utilisé en Haïti. Un message d'**authentification** (modèle approuvé par Meta, contenant le code)
coûte **~0,011 à 0,015 $**, soit 10 à 25 fois moins qu'un SMS. Coût estimé : ~2 600 × 0,013 $ = **~35 $ par mois**.

Limites : le joueur doit avoir WhatsApp sur ce numéro ; Meta revoit ses prix chaque trimestre ; un compte WhatsApp
Business vérifié est nécessaire. **Le SMS reste indispensable en secours.**

## 5. E-mail transactionnel

| Prestataire | Coût indicatif | Points forts | Points faibles | Intégration Laravel |
|---|---|---|---|---|
| **Amazon SES** | ~0,10 $ pour 1 000 e-mails | Le moins cher ; logique si l'hébergeur est AWS | Réputation et rebonds à gérer soi-même ; console AWS | Pilote natif (paquet AWS à ajouter) |
| **Postmark** | ~15 $ pour 10 000 par mois | Meilleure délivrabilité ; séparation transactionnel / marketing | Plus cher à gros volume | Pilote natif (paquet Symfony à ajouter) |
| **Resend** | Gratuit jusqu'à 3 000 par mois, puis ~20 $ | Simple ; offre gratuite pour démarrer | Plus jeune | Pilote natif (paquet à ajouter) |
| **Mailgun** | ~0,70 à 1,30 $ pour 1 000 | Complet (réception, validation) | Prix variables | Paquet Symfony à ajouter |
| **Brevo** | Gratuit jusqu'à 300 par jour | Fait aussi le marketing | Moins orienté transactionnel | SMTP |

**Coût estimé** : ~13 000 e-mails par mois → **~1 à 2 $ (SES)**, **~15 à 25 $ (Postmark)**, **gratuit à ~20 $ (Resend)**.

Dans tous les cas : domaine d'envoi authentifié (SPF, DKIM, DMARC) ; adresse d'expédition du type
`no-reply@<domaine>` ; e-mails rédigés en français, sans contenu promotionnel dans les e-mails transactionnels.

## 6. Risques communs

- **Fraude aux SMS** (« SMS pumping ») : des robots déclenchent des milliers de codes pour faire payer l'opérateur.
  Parades : numéros +509 seulement (déjà le cas), limite d'envois par numéro, par IP et par jour, délai entre deux
  renvois, et la protection anti-fraude du prestataire (Twilio Verify en a une).
- **Données personnelles** (J-9) : numéro de téléphone et e-mail transmis au prestataire, souvent hébergé hors
  d'Haïti ; contenu des messages limité au strict nécessaire (jamais de solde ni de détail de pari par SMS).
- **Dépendance** : un seul prestataire pour les codes = une panne bloque les inscriptions ; d'où le secours SMS si
  WhatsApp échoue, et une interface interne qui permet de changer de prestataire.

## 7. Recommandation

1. **Codes de vérification** : un seul prestataire qui fait **WhatsApp et SMS** (Twilio ou Infobip), **WhatsApp
   d'abord, SMS en secours**. Coût : ~35 $ par mois si la plupart des joueurs reçoivent le code par WhatsApp, ~750 $
   au pire en SMS seul. Twilio de préférence, pour « Verify » (renvois et anti-fraude intégrés), si son prix pour
   Haïti est confirmé.
2. **E-mail** : **Amazon SES** si l'hébergeur retenu en D-10 est AWS, ou si le coût prime ; sinon **Postmark** pour
   la délivrabilité. Resend convient pour démarrer en Staging (offre gratuite).
3. **Démarches à lancer tout de suite après la décision** : enregistrement du nom d'expéditeur auprès du CONATEL
   (délai de plusieurs semaines), vérification du compte WhatsApp Business, authentification du domaine d'envoi.
4. **Notifications (V2-E04)** : e-mail et messagerie dans le site par défaut ; SMS seulement si le joueur le choisit,
   et seulement pour les événements importants (retrait payé, KYC refusé).

## 8. Points à trancher

| # | Question | Proposition | Décision |
|---|---|---|---|
| SE-1 | Prestataire des codes de vérification | Twilio (Verify : WhatsApp puis SMS) ; Infobip en alternative | |
| SE-2 | WhatsApp pour les codes | Oui, en premier, avec SMS en secours | |
| SE-3 | Nom d'expéditeur SMS | « REYEL16 », enregistré auprès du CONATEL | |
| SE-4 | Prestataire e-mail | Amazon SES si hébergeur AWS, sinon Postmark ; Resend en Staging | |
| SE-5 | Domaine et adresse d'envoi | `no-reply@<domaine de Production>`, SPF, DKIM et DMARC | |
| SE-6 | Limites d'envoi des codes | 3 envois par numéro par heure, 10 par jour ; 60 s entre deux renvois ; 20 par IP par heure | |
| SE-7 | Durée de validité d'un code | 10 minutes, 5 essais au plus | |
| SE-8 | Notifications par SMS (V2-E04) | Au choix du joueur, seulement retrait payé et KYC refusé | |
| SE-9 | Budget mensuel maximal SMS et WhatsApp | À fixer ; alerte d'exploitation à 80 % | |

## 9. Questions à confirmer auprès des prestataires et du CONATEL

| # | Question | Réponse |
|---|---|---|
| SEJ-1 | Règles du CONATEL pour un nom d'expéditeur alphanumérique : documents, délai, coût ; différences Digicel / Natcom | |
| SEJ-2 | Prix réel par SMS vers Digicel et Natcom, et taux de livraison, chez Twilio et Infobip (devis) | |
| SEJ-3 | Prix actuel de Meta pour un message d'authentification WhatsApp vers Haïti | |
| SEJ-4 | Où sont stockés les numéros et les e-mails chez le prestataire retenu, et pendant combien de temps (J-9) | |

## 10. Conséquences techniques

- **Backend (Benginio)** : interface interne « expéditeur de codes » (comme `PaymentProvider`), avec un prestataire
  simulé pour le développement et les tests ; table des codes de vérification (code haché, expiration, essais) ;
  limites d'envoi (SE-6) ; e-mails transactionnels mis en file. Contrat d'API écrit d'abord dans
  `docs/api/openapi.yaml`.
- **Nouvelles dépendances**, à valider le moment venu : le paquet du pilote e-mail retenu (AWS, Symfony Postmark ou
  Resend) ; pour les SMS, appel HTTP direct à l'API du prestataire (sans paquet) de préférence.
- **Frontend (Rubly)** : saisie du code à l'inscription et au changement de numéro (#151), choix des canaux de
  notification (#183).
- **Exploitation** : clés d'API dans le `.env` du serveur seulement ; prestataire simulé interdit en Production
  (comme `PAYMENTS_FAKE_ENABLED`).

## 11. Conditions pour passer à « Validée »

1. SE-1 à SE-9 tranchés par écrit.
2. Prix confirmés par les prestataires (SEJ-2, SEJ-3).
3. Règles du CONATEL connues (SEJ-1) et demande d'enregistrement du nom d'expéditeur déposée.

## 12. Validation

| Rôle | Nom | Date | Décision |
|---|---|---|---|
| Chef de projet | Rubly PLAISIR | | |

## Sources (consultées le 13 novembre 2026)

- SMS : [Twilio — Haïti](https://www.twilio.com/en-us/sms/pricing/ht),
  [grille tarifaire Twilio (CSV)](https://www.twilio.com/content/dam/twilio-com/pricing-data/en/csv/PMded94a0dae30eaaec0f115f22859bd38_SMSPricing.csv),
  [Infobip — SMS](https://www.infobip.com/sms/pricing),
  [Sent.dm — Haïti](https://www.sent.dm/resources/sms-pricing/haiti-sms-pricing),
  [BulkGate — Haïti](https://www.bulkgate.com/en/pricing/sms/ht/haiti/),
  [Releans — Haïti](https://releans.com/sms/pricing/haiti),
  [Sempico — Haïti](https://sempico.solutions/pricing/haiti/),
  [D7 Networks — Haïti](https://d7networks.com/sms/haiti/),
  [Sinch — meilleures API SMS 2026](https://sinch.com/blog/the-best-sms-api/)
- Nom d'expéditeur et CONATEL : [Miatel — Haïti](https://docs.miatel.com/docs/country-specific-features-and-restrictions/americas/haiti),
  [Swipe Media — réglementation](https://swipemedia.net/sms-regulations),
  [SMS.to — règles par pays](https://support.sms.to/support/solutions/articles/43000562659-international-sms-sender-id-regulations-and-requirements-list-of-countries-)
- WhatsApp : [Infobip — WhatsApp](https://www.infobip.com/whatsapp-business/pricing),
  [Automate AI — prix par pays](https://theautomate.ai/whatsapp-api-pricing/),
  [WHAutomate — prix par pays](https://whautomate.com/whatsapp-business-api-pricing)
- E-mail : [Makerkit — e-mail transactionnel 2026](https://makerkit.dev/blog/saas/best-transactional-email-provider-saas),
  [Dupple — e-mail transactionnel 2026](https://dupple.com/learn/best-transactional-email-services),
  [Mailtrap — comparatif](https://mailtrap.io/blog/transactional-email-services/)
