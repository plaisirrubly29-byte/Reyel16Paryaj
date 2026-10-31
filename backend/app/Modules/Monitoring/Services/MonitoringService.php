<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Admin\Services\AlertService;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use Carbon\CarbonImmutable;

/**
 * Alertes à pousser à l'astreinte (P9-02). Réutilise les points d'attention de l'administration (AlertService :
 * règlement en retard, tâches en échec, rapprochement, fournisseur, sécurité) et ajoute ce qu'un écran ne voit pas :
 * erreurs de l'API, retard de synchronisation du catalogue, travailleur de file arrêté.
 *
 * Les alertes « info » de l'administration (ex. marchés suspendus) ne sont pas poussées.
 */
class MonitoringService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly ApiErrorRate $apiErrors,
        private readonly QueueHeartbeat $queue,
    ) {}

    /** @return list<array{key: string, severity: 'critical'|'warning', category: string, title: string, detail: string, link: string|null}> */
    public function alerts(): array
    {
        $admin = array_values(array_filter(
            $this->alerts->current(array_keys(config('permissions.permissions'))),
            fn (array $alert) => in_array($alert['severity'], ['critical', 'warning'], true),
        ));

        return [...array_filter([$this->apiErrors(), $this->syncDelay(), $this->queueStopped()]), ...$admin];
    }

    /** @return array<string, mixed>|null */
    private function apiErrors(): ?array
    {
        $window = (int) config('monitoring.api_errors.window_minutes');
        $stats = $this->apiErrors->lastMinutes($window);
        if ($stats['errors'] < (int) config('monitoring.api_errors.min_errors') || $stats['rate'] < (float) config('monitoring.api_errors.max_rate')) {
            return null;
        }

        $percent = round($stats['rate'] * 100, 1);

        return $this->alert('api_errors', 'critical', 'Technique', 'Erreurs de l\'API',
            "{$stats['errors']} réponse(s) en erreur serveur (5xx) sur {$stats['total']} en {$window} min ({$percent} %). Voir les logs (request_id) et le runbook « API indisponible ou erreurs 500 ».",
            '/admin/system');
    }

    /** @return array<string, mixed>|null */
    private function syncDelay(): ?array
    {
        $hours = (int) config('monitoring.sync_delay_hours');
        $upcoming = Event::query()->where('status', EventStatus::Scheduled)->where('starts_at', '>', now());
        if (! (clone $upcoming)->exists()) {
            return null;
        }

        $last = (clone $upcoming)->max('last_synced_at');
        if ($last !== null && CarbonImmutable::parse($last)->gte(now()->subHours($hours))) {
            return null;
        }

        return $this->alert('sync_delay', 'warning', 'Données sportives', 'Retard de synchronisation du catalogue',
            $last === null
                ? 'Aucun match à venir n\'a encore été synchronisé.'
                : "Dernière mise à jour des matchs à venir : {$last} (plus de {$hours} h). Cotes bientôt périmées et marchés suspendus : vérifier le planificateur et le fournisseur.",
            '/admin/system');
    }

    /** @return array<string, mixed>|null */
    private function queueStopped(): ?array
    {
        if (config('queue.default') === 'sync') {
            return null; // Tâches exécutées dans la requête (tests) : aucun travailleur à surveiller.
        }

        $last = $this->queue->last();
        if ($last !== null && $last->gte(now()->subMinutes(QueueHeartbeat::MAX_AGE_MINUTES))) {
            return null;
        }

        return $this->alert('queue_stopped', 'critical', 'Technique', 'Travailleur de file arrêté',
            ($last === null ? 'Aucun signal de queue:work enregistré.' : "Dernier signal de queue:work : {$last->toIso8601String()}.")
            .' Le règlement et les autres tâches en file ne tournent plus : relancer le conteneur queue.',
            '/admin/system');
    }

    /** @return array<string, mixed> */
    private function alert(string $key, string $severity, string $category, string $title, string $detail, ?string $link): array
    {
        return compact('key', 'severity', 'category', 'title', 'detail', 'link');
    }
}
