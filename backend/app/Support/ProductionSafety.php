<?php

namespace App\Support;

use RuntimeException;

/**
 * Configuration interdite en production (revue P8-02, checklist de déploiement, B2 et I3) :
 * l'application refuse de démarrer plutôt que de tourner exposée.
 */
final class ProductionSafety
{
    /** Commandes toujours permises : sans elles, une configuration dangereuse mise en cache ne se corrige plus. */
    public const REPAIR_COMMANDS = ['config:clear', 'config:cache', 'optimize:clear', 'down', 'up'];

    /**
     * @return list<string> problèmes trouvés (vide si la configuration est sûre)
     */
    public static function problems(): array
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG doit valoir false (sinon les erreurs 500 renvoient leur message brut, SQL compris).';
        }

        // TrustProxies remplace REMOTE_ADDR par l'IP de l'appelant : comme « * », il fait confiance à tout le monde.
        if (array_intersect(self::entries(config('trustedproxy.proxies')), ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0']) !== []) {
            $problems[] = 'TRUSTED_PROXIES ne doit faire confiance qu\'aux IP du proxy, jamais à « * », REMOTE_ADDR, 0.0.0.0/0 ou ::/0 (n\'importe quel client pourrait falsifier X-Forwarded-For).';
        }

        if (in_array('*', self::entries(config('cors.allowed_origins')), true)) {
            $problems[] = 'CORS_ALLOWED_ORIGINS ne doit pas contenir "*" (seul le frontend first-party est autorisé).';
        }

        if (in_array('*', self::entries(config('sanctum.stateful')), true)) {
            $problems[] = 'SANCTUM_STATEFUL_DOMAINS ne doit pas contenir "*" (session ouverte à n\'importe quel domaine).';
        }

        return $problems;
    }

    /**
     * Valeurs d'une liste de configuration, sans espaces : Laravel et Sanctum les retirent avant de comparer,
     * donc « site.ht, * » contient bien « * ».
     *
     * @return list<string>
     */
    private static function entries(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn (string $item) => $item !== ''));
    }

    /** @throws RuntimeException si la configuration de production est dangereuse */
    public static function assertSafe(): void
    {
        if (($problems = self::problems()) !== []) {
            throw new RuntimeException("Configuration de production refusée :\n- ".implode("\n- ", $problems));
        }
    }
}
