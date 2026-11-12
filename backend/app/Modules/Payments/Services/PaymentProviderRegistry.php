<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentProvider;

/** Prestataires listés ET activés dans config/payments.php ; tout autre nom est inconnu. */
class PaymentProviderRegistry
{
    public function find(string $name): ?PaymentProvider
    {
        $config = config("payments.providers.{$name}");
        if (! is_array($config) || ! ($config['enabled'] ?? false)) {
            return null;
        }

        $provider = app($config['class']);

        return $provider instanceof PaymentProvider ? $provider : null;
    }
}
