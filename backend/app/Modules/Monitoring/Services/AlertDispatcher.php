<?php

namespace App\Modules\Monitoring\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Décide quoi envoyer à l'astreinte à partir des alertes du moment et de l'état précédent (cache) :
 * - nouvelle alerte, ou gravité qui monte : envoyée ;
 * - alerte critique toujours active : rappel toutes les monitoring.critical_reminder_minutes ;
 * - alerte disparue : « résolue » envoyée une fois.
 * Sans changement, rien n'est envoyé : pas de message toutes les 5 minutes pour le même problème.
 */
class AlertDispatcher
{
    public const STATE_KEY = 'monitoring:alerts:state';

    public function __construct(private readonly AlertNotifier $notifier) {}

    /**
     * @param  list<array{key: string, severity: string, category: string, title: string, detail: string, link: string|null}>  $alerts
     * @return array{sent: list<string>, resolved: list<string>, active: int}
     */
    public function dispatch(array $alerts): array
    {
        /** @var array<string, array{severity: string, title: string, since: string, notified_at: string}> $state */
        $state = Cache::get(self::STATE_KEY, []);
        $now = now();
        $reminder = (int) config('monitoring.critical_reminder_minutes');
        $sent = [];
        $next = [];

        foreach ($alerts as $alert) {
            $previous = $state[$alert['key']] ?? null;
            $escalated = $previous !== null && $previous['severity'] === 'warning' && $alert['severity'] === 'critical';
            $remind = $previous !== null && $alert['severity'] === 'critical'
                && $now->diffInMinutes($previous['notified_at'], true) >= $reminder;

            $notifiedAt = $previous['notified_at'] ?? null;
            if ($previous === null || $escalated || $remind) {
                $prefix = $previous === null || $escalated ? '' : 'Toujours active — ';
                $this->notifier->send($this->subject($alert['severity'], $prefix.$alert['title']), $this->body($alert));
                Log::warning('monitoring.alert', ['key' => $alert['key'], 'severity' => $alert['severity'], 'title' => $alert['title']]);
                $notifiedAt = $now->toIso8601String();
                $sent[] = $alert['key'];
            }

            $next[$alert['key']] = [
                'severity' => $alert['severity'],
                'title' => $alert['title'],
                'since' => $previous['since'] ?? $now->toIso8601String(),
                'notified_at' => $notifiedAt,
            ];
        }

        $resolved = array_values(array_diff(array_keys($state), array_keys($next)));
        foreach ($resolved as $key) {
            $this->notifier->send($this->subject('resolved', $state[$key]['title']),
                "Alerte résolue (active depuis le {$state[$key]['since']}).\nAucune action requise, sauf post-mortem si l'incident a eu un impact.");
            Log::info('monitoring.alert_resolved', ['key' => $key]);
        }

        Cache::forever(self::STATE_KEY, $next);

        return ['sent' => $sent, 'resolved' => $resolved, 'active' => count($next)];
    }

    private function subject(string $severity, string $title): string
    {
        $label = ['critical' => '🔴 CRITIQUE', 'warning' => '🟠 Avertissement', 'resolved' => '✅ Résolu'][$severity] ?? $severity;

        return '[REYEL 16 · '.config('monitoring.label')."] {$label} : {$title}";
    }

    /** @param array{category: string, detail: string, link: string|null} $alert */
    private function body(array $alert): string
    {
        $link = $alert['link'] ? rtrim((string) config('app.frontend_url'), '/').$alert['link'] : null;

        return implode("\n", array_filter([
            "Domaine : {$alert['category']}",
            $alert['detail'],
            $link ? "Écran : {$link}" : null,
            'Procédure : docs/ops/runbook-incident.md — le premier disponible prend l\'incident et le signale dans le groupe.',
        ]));
    }
}
