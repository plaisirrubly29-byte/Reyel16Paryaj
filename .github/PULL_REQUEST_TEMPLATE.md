## Tâche

Closes #<!-- numéro de l'issue, ex. #12 --> — `<!-- ID de tâche, ex. P4-03 -->`

## Ce qui change

<!-- 2-5 lignes : quoi et pourquoi -->

## Comment tester

<!-- commandes, routes, comptes de test, captures si UI -->

## Définition de « terminé » (règle de clôture §14)

- [ ] Les règles métier appliquées sont celles approuvées (sinon : lien vers la décision)
- [ ] Les données persistées sont correctes (migrations, contraintes, montants en entier)
- [ ] Les permissions sont vérifiées **côté serveur** (pas seulement masquées dans l'UI)
- [ ] Les cas limites sont testés (tests ajoutés/mis à jour)
- [ ] Les opérations sensibles sont auditées
- [ ] Aucun secret, clé ou donnée réelle dans le code
- [ ] OpenAPI (`docs/api/openapi.yaml`) mis à jour si une route change
- [ ] Toute dépendance légale/fournisseur non confirmée reste désactivée
