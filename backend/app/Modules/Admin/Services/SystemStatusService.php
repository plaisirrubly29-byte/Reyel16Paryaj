<?php

namespace App\Modules\Admin\Services;

use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\ProviderApiCall;
use App\Modules\SportsData\Services\CreditBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Supervision technique en lecture seule. N'expose jamais de secret (clé API, variables d'environnement)
 * ni de trace d'exception : uniquement des états, des dates et des compteurs.
 */
class SystemStatusService
{
    /** Clé écrite chaque minute par le planificateur (routes/console.php). */
    public const HEARTBEAT_KEY = 'scheduler.heartbeat';

    /** Au-delà, le planificateur est considéré comme arrêté. */
    public const HEARTBEAT_MAX_AGE_MINUTES = 5;

    public function __construct(private readonly CreditBudget $credits) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'scheduler' => $this->scheduler(),
            'sports_provider' => $this->sportsProvider(),
            'queue' => $this->queue(),
            // Aucun prestataire intégré : P7-01 bloqué jusqu'à la validation juridique.
            'payments' => ['integrated' => false, 'providers' => []],
            // Les sauvegardes (P8-04) sont faites hors application : aucun résultat vérifié n'est remonté ici.
            'backups' => ['tracked' => false],
        ];
    }

    /** @return array{running: bool, last_heartbeat: string|null} */
    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $at = is_string($beat) ? CarbonImmutable::parse($beat) : null;

        return [
            'running' => $at !== null && $at->gte(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES)),
            'last_heartbeat' => $at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function sportsProvider(): array
    {
        $provider = $this->credits->provider();
        $competitions = collect((array) config('sports_data.sports'))
            ->map(function (string $key) {
                $label = config("sports_data.competition_labels.{$key}");

                return [
                    'key' => $key,
                    'name' => $label['name'] ?? $key,
                    'last_synced_at' => $this->credits->lastSyncedAt($key)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'provider' => $provider,
            'budget_enabled' => $this->credits->enabled(),
            'credits' => [
                'remaining' => $this->credits->remaining(),
                'used' => $this->credits->used(),
                'monthly' => $this->credits->monthlyCredits(),
                'reserve' => $this->credits->reserve(),
                'source' => $this->credits->source(),
                'next_reset' => $this->credits->nextReset()->toDateString(),
            ],
            'sync_cron' => $this->credits->cron(),
            'last_call_at' => $this->credits->lastCallAt()?->toIso8601String(),
            'last_event_sync_at' => ($at = Event::query()->max('last_synced_at')) ? CarbonImmutable::parse($at)->toIso8601String() : null,
            'competitions' => $competitions,
            'recent_failures' => ProviderApiCall::query()
                ->where('provider', $provider)
                ->where('status', '>=', 400)
                ->orderByDesc('called_at')
                ->limit(10)
                ->get(['endpoint', 'competition', 'status', 'called_at'])
                ->map(fn (ProviderApiCall $call) => [
                    'endpoint' => $call->endpoint,
                    'competition' => $call->competition,
                    'status' => $call->status,
                    'called_at' => $call->called_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        return [
            'connection' => (string) config('queue.default'),
            'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            // Nom de la tâche et date uniquement : le message d'erreur peut contenir des données sensibles.
            'recent_failures' => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->get(['uuid', 'queue', 'payload', 'failed_at'])
                ->map(fn ($job) => [
                    'id' => (string) $job->uuid,
                    'queue' => (string) $job->queue,
                    'job' => (string) (json_decode((string) $job->payload, true)['displayName'] ?? 'Tâche inconnue'),
                    'failed_at' => CarbonImmutable::parse($job->failed_at)->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
