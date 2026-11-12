<?php

use App\Modules\Payments\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Module : Payments Adapter (V2-B01)
| Routes chargées par routes/api.php sous le préfixe /api/v1.
| Webhooks des prestataires : sans compte, signature vérifiée par le prestataire concerné. Les routes joueur
| (déposer, retirer) arrivent avec V2-B02 et V2-B03 ; la consultation admin est dans le module Admin.
*/

Route::post('/payments/webhooks/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z0-9_]+')
    ->middleware('throttle:payments-webhook');
