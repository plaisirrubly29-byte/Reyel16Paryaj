<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Exceptions\InvalidWebhookException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentInitiation;
use App\Modules\Payments\Support\PaymentNotification;
use Illuminate\Http\Request;

/**
 * Interface commune des prestataires de paiement (V2-B01) : chaque prestataire (MonCash, NatCash…) traduit ses appels
 * et ses webhooks dans ce format. PaymentService applique les règles communes (idempotence, montant, ledger).
 */
interface PaymentProvider
{
    /** Identifiant court, celui de config/payments.php et de l'URL du webhook. */
    public function name(): string;

    /**
     * Ouvre le paiement chez le prestataire (dépôt : le joueur confirme sur son téléphone ou une page du prestataire).
     * Ne crédite ni ne débite rien : seul un webhook vérifié a un effet financier.
     */
    public function initiate(PaymentTransaction $transaction): PaymentInitiation;

    /** Signature du webhook vérifiée sur le corps brut, avec une limite d'ancienneté contre le rejeu. */
    public function verifyWebhook(Request $request): bool;

    /**
     * Traduit un webhook déjà vérifié.
     *
     * @throws InvalidWebhookException contenu illisible ou incomplet
     */
    public function parseWebhook(Request $request): PaymentNotification;
}
