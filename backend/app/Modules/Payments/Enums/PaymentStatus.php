<?php

namespace App\Modules\Payments\Enums;

/** État d'une transaction chez un prestataire (V2-B01). */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Jamais confirmée dans le délai (payments:expire). Un succès reçu ensuite est encore appliqué. */
    case Expired = 'expired';
    /** Écart avec le prestataire (montant, devise) : aucun effet financier, revue par l'équipe. */
    case Review = 'review';

    /** Plus aucun webhook ne la modifie. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Review], true);
    }
}
