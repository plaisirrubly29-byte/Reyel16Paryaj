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

    /**
     * Moyens de dépôt que le joueur choisit chez ce prestataire (ex. Plop Plop : moncash, natcash), code => libellé.
     * Vide : le prestataire n'a qu'un moyen, sans choix.
     *
     * @return array<string, string>
     */
    public function methods(string $name): array
    {
        return array_map('strval', (array) config("payments.providers.{$name}.methods", []));
    }

    /** Libellé vu par le joueur (formulaire, historique, écriture du ledger) : celui du moyen choisi s'il y en a un. */
    public function label(string $name, ?string $method = null): string
    {
        return $this->methods($name)[$method ?? ''] ?? (string) config("payments.providers.{$name}.label", $name);
    }
}
