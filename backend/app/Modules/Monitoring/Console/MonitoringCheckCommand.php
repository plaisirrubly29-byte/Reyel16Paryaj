<?php

namespace App\Modules\Monitoring\Console;

use App\Modules\Monitoring\Services\AlertDispatcher;
use App\Modules\Monitoring\Services\AlertNotifier;
use App\Modules\Monitoring\Services\MonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contrôle de production (P9-02), planifié toutes les 5 minutes : évalue les alertes, prévient l'astreinte
 * (Telegram, e-mail) selon AlertDispatcher, puis appelle le signal de vie externe (monitoring.heartbeat_url).
 *
 *   php artisan monitoring:check          contrôle normal
 *   php artisan monitoring:check --test   message de test sur chaque canal (mise en service, après un changement)
 */
class MonitoringCheckCommand extends Command
{
    protected $signature = 'monitoring:check {--test : Envoie un message de test sur chaque canal d\'alerte}';

    protected $description = 'Évalue les alertes de production et prévient l\'astreinte (Telegram, e-mail)';

    public function handle(MonitoringService $monitoring, AlertDispatcher $dispatcher, AlertNotifier $notifier): int
    {
        if ($this->option('test')) {
            return $this->sendTest($notifier);
        }

        $alerts = $monitoring->alerts();
        $result = $dispatcher->dispatch($alerts);

        $this->table(['Gravité', 'Alerte', 'Domaine'], array_map(fn (array $a) => [$a['severity'], $a['title'], $a['category']], $alerts));
        $this->info(sprintf('%d alerte(s) active(s), %d envoyée(s), %d résolue(s).', $result['active'], count($result['sent']), count($result['resolved'])));

        $this->pingHeartbeat();

        return self::SUCCESS;
    }

    private function sendTest(AlertNotifier $notifier): int
    {
        $results = $notifier->send(
            '[REYEL 16 · '.config('monitoring.label').'] 🧪 Test des alertes',
            'Message de test envoyé par monitoring:check --test le '.now()->toIso8601String().".\nSi vous le recevez, ce canal d'alerte fonctionne.",
        );

        $failed = false;
        foreach ($results as $channel => $ok) {
            $this->line(sprintf('%-9s %s', $channel, match ($ok) {
                true => 'envoyé',
                false => 'ÉCHEC (voir les logs)',
                null => 'non configuré',
            }));
            $failed = $failed || $ok === false;
        }

        if (! in_array(true, $results, true)) {
            $this->error('Aucun canal n\'a reçu le message : configurer MONITORING_TELEGRAM_* et/ou MONITORING_MAIL_TO.');

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function pingHeartbeat(): void
    {
        $url = config('monitoring.heartbeat_url');
        if (blank($url)) {
            return;
        }

        try {
            Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            Log::warning('monitoring.heartbeat_failed', ['error' => class_basename($e)]);
        }
    }
}
