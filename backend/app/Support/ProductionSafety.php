<?php

namespace App\Support;

use RuntimeException;

/**
 * Configuration interdite en production (revue P8-02, checklist de déploiement, B2 et I3) :
 * l'application refuse de démarrer plutôt que de tourner exposée.
 */
final class ProductionSafety
{
    /**
     * @return list<string> problèmes trouvés (vide si la configuration est sûre)
     */
    public static function problems(): array
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG doit valoir false (sinon les erreurs 500 renvoient leur message brut, SQL compris).';
        }

        if (in_array(config('trustedproxy.proxies'), ['*', '**'], true)) {
            $problems[] = 'TRUSTED_PROXIES ne doit pas valoir "*" (n\'importe quel client pourrait falsifier X-Forwarded-For).';
        }

        if (in_array('*', (array) config('cors.allowed_origins'), true)) {
            $problems[] = 'CORS_ALLOWED_ORIGINS ne doit pas contenir "*" (seul le frontend first-party est autorisé).';
        }

        if (in_array('*', (array) config('sanctum.stateful'), true)) {
            $problems[] = 'SANCTUM_STATEFUL_DOMAINS ne doit pas contenir "*" (session ouverte à n\'importe quel domaine).';
        }

        return $problems;
    }

    /** @throws RuntimeException si la configuration de production est dangereuse */
    public static function assertSafe(): void
    {
        if (($problems = self::problems()) !== []) {
            throw new RuntimeException("Configuration de production refusée :\n- ".implode("\n- ", $problems));
        }
    }
}
