<?php

namespace App\Modules\IdentityAccess\Enums;

/** Statut KYC d'un compte (V2-C03), tenu par KycService : jamais modifié directement. */
enum KycStatus: string
{
    case None = 'none'; // aucun dossier envoyé

    case Pending = 'pending'; // dossier en attente de revue

    case Approved = 'approved';

    case Rejected = 'rejected'; // le joueur peut envoyer un nouveau dossier
}
