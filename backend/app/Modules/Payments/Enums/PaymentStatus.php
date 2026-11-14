<?php

namespace App\Modules\Payments\Enums;

/** État d'une transaction chez un prestataire (V2-B01, retraits V2-B03). */
enum PaymentStatus: string
{
    /** Dépôt : en attente de confirmation. Retrait : demandé, montant réservé, en file de versement. */
    case Pending = 'pending';
    /** Retrait envoyé au prestataire, résultat pas encore connu (vérifié ensuite par sa référence). */
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Jamais confirmée dans le délai (payments:expire). Un succès reçu ensuite est encore appliqué. */
    case Expired = 'expired';
    /** Écart avec le prestataire (montant, devise) : aucun effet financier, revue par l'équipe. */
    case Review = 'review';
    /** Retrait annulé par le joueur avant son versement : montant rendu. */
    case Cancelled = 'cancelled';

    /** Plus aucun webhook ni versement ne la modifie. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Review, self::Cancelled], true);
    }
}
