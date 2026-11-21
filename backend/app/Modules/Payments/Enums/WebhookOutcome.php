<?php

namespace App\Modules\Payments\Enums;

/** Ce qu'a produit un webhook reçu (journal webhook_events). */
enum WebhookOutcome: string
{
    /** Statut appliqué (dépôt crédité, échec enregistré…). */
    case Applied = 'applied';
    /** Succès reçu après l'expiration : appliqué quand même et signalé. */
    case AppliedLate = 'applied_late';
    /** Montant ou devise différents : transaction mise en revue, aucun effet financier. */
    case Review = 'review';
    /** Transaction déjà dans un état final, ou statut intermédiaire : rien à faire. */
    case Ignored = 'ignored';
    /** Aucune transaction ne porte cette référence chez ce prestataire. */
    case UnknownTransaction = 'unknown_transaction';
    case InvalidSignature = 'invalid_signature';
    case InvalidPayload = 'invalid_payload';
}
