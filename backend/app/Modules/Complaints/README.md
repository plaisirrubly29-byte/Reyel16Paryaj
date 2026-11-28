# Module Complaints (réclamations)

**Namespace :** `App\Modules\Complaints`
**Tâche :** V2-G04 (#232) — écrans : #233
**Contrat :** `docs/api/openapi.yaml`, tag `Complaints` (#248)

## Périmètre

Un joueur ouvre une réclamation numérotée, liée ou non à un de ses tickets ou à une écriture de son portefeuille, avec
une description et des pièces jointes. L'équipe la suit par statut jusqu'à sa résolution ; tout l'historique est
conservé.

| Étape | Contenu | État |
|---|---|---|
| 1 | Tables, API joueur (ouverture, liste, détail, messages, réouverture, pièces jointes) | Fait |
| 2 | API admin (file, fiche, réponse et note interne, statut, attribution), fermeture automatique, permissions, audit | À faire |
| — | Lien vers un agent | Avec V2-G01 |
| — | Notification au joueur à chaque réponse | Avec V2-E04 |

## Tables

- `complaints` : numéro `REC-AAAA-NNNNNN` (unique), joueur, catégorie (`bet`, `transaction`, `account`, `other`),
  objet, description, statut, lien (`link_type`, `link_id`, et `link_label` : référence lisible figée à l'ouverture),
  administrateur attribué, motif de résolution, `awaiting_staff_since` (le joueur attend une réponse depuis),
  `last_activity_at`, `resolved_at`, `closed_at`.
- `complaint_counters` : dernier numéro attribué par année. La ligne est verrouillée à chaque ouverture : aucun
  doublon quand deux joueurs ouvrent une réclamation au même moment.
- `complaint_messages` : message du joueur, réponse de l'équipe (`visibility = player`) ou note interne
  (`visibility = internal`, jamais montrée au joueur).
- `complaint_attachments` : pièce de l'ouverture (`complaint_message_id` null) ou d'un message ; chemin sur le disque
  privé, nom d'origine nettoyé, type lu dans le contenu, taille, empreinte SHA-256 du fichier d'origine.
- `complaint_events` : historique **en ajout seul** (`created`, `status_changed`, `reopened`, `assigned`, `replied`,
  `note_added`) ; le modèle refuse toute modification ou suppression.

## Statuts

| Depuis | Vers (équipe) |
|---|---|
| `new` | `in_progress`, `investigation`, `resolved` |
| `in_progress` | `investigation`, `resolved` |
| `investigation` | `in_progress`, `resolved` |
| `resolved` | `closed`, `in_progress` |
| `closed` | `in_progress` |

Transitions de l'équipe : `ComplaintStatus::allowedTargets()` (contrat : `POST /admin/complaints/{id}/status`).
Réouverture par le joueur : resolved ou closed → in_progress, avec motif, jusqu'à `complaints.reopen_window_days`
(30 jours) après le passage en resolved. Fermeture automatique : resolved → closed après `complaints.auto_close_days`
(7 jours, étape 2).

## Règles

- Réservé aux joueurs : un administrateur reçoit `422 not_a_player`.
- Lien vers un objet d'un autre joueur ou inconnu : `422 complaint_link_invalid`, sans révéler s'il existe.
- Réclamation d'un autre joueur : `404`, comme une réclamation inexistante.
- Pièces jointes : 5 au plus par envoi, 5 Mo chacune, jpg, jpeg, png, webp ou pdf. Le type est lu dans le contenu du
  fichier (une page HTML renommée en `.png` est refusée). Chiffrées par l'application (`Crypt`, clé `APP_KEY`) avant
  écriture sur le disque `complaints` ; servies déchiffrées avec `Cache-Control: no-store`. Les pièces des notes
  internes n'existent pas pour le joueur (404).
- Vue du joueur : ni notes internes, ni événements `assigned` et `note_added`, ni identité des administrateurs
  (l'équipe signe « Service client ») ; motif d'historique seulement pour une résolution et une réouverture.
- Limites par joueur et par heure : 5 réclamations, 20 messages (429). Seuls les envois acceptés comptent.
- Un message sur une réclamation `closed` est refusé (`409 complaint_closed`) ; sur une réclamation `resolved`, il ne
  la rouvre pas.

## Configuration

`config/complaints.php` : disque, limites des pièces, limites par heure, délais de réouverture et de fermeture.

## Exploitation

Le disque `complaints` (`storage/app/complaints`) doit être, en Staging et en Production, un **volume Docker
conservé entre deux déploiements et sauvegardé avec la base**, comme `kyc_documents`. Tant que ce n'est pas fait,
les pièces jointes seraient perdues au redéploiement suivant.

La taille des envois est aussi bornée par PHP (`upload_max_filesize`, `post_max_size`) : voir #350.
