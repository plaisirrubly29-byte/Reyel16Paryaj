<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Providers\FakeSportsDataProvider;
use Illuminate\Console\Command;
use LogicException;

/**
 * Matchs de scénario du faux fournisseur, pour les tests de bout en bout (P8-01) :
 *
 *   php artisan sports-data:fake-match create              → {"id":"e2e-…","home":"E2E Lions …",…} (JSON)
 *   php artisan sports-data:fake-match finish e2e-… 2-1    → match commencé il y a deux heures, score final publié
 *
 * Après create : sports-data:sync (le match apparaît, pariable). Après finish : settlement:import-results
 * --competition=soccer_epl puis settlement:run (chemin normal de l'import et du règlement).
 * Refusé en production et avec un vrai fournisseur (SPORTS_DATA_PROVIDER doit valoir « fake »).
 */
class FakeMatchCommand extends Command
{
    protected $signature = 'sports-data:fake-match
        {action : create ou finish}
        {id? : Identifiant du match de scénario (finish)}
        {score? : Score final « domicile-extérieur », ex. 2-1 (finish)}';

    protected $description = 'Crée ou termine un match du faux fournisseur (tests de bout en bout, hors production)';

    public function handle(SportsDataProvider $provider): int
    {
        if (app()->isProduction()) {
            $this->error('Refusé en production.');

            return self::FAILURE;
        }
        if (! $provider instanceof FakeSportsDataProvider) {
            $this->error('Réservé au faux fournisseur (SPORTS_DATA_PROVIDER=fake).');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'create' => $this->create($provider),
            'finish' => $this->finish($provider),
            default => $this->invalid('Action inconnue : create ou finish.'),
        };
    }

    private function create(FakeSportsDataProvider $provider): int
    {
        $this->line(json_encode($provider->addScenarioMatch(), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function finish(FakeSportsDataProvider $provider): int
    {
        $id = (string) $this->argument('id');
        if (preg_match('/^(\d{1,3})-(\d{1,3})$/', (string) $this->argument('score'), $score) !== 1) {
            return $this->invalid('Score attendu : « domicile-extérieur », ex. 2-1.');
        }

        try {
            $kickOff = $provider->finishScenarioMatch($id, $score[1], $score[2]);
        } catch (LogicException $e) {
            return $this->invalid($e->getMessage());
        }

        // Le temps qui passe : la synchronisation ne modifie plus un match commencé, le coup d'envoi déjà synchronisé
        // est donc avancé ici (module SportsData, propriétaire des matchs). Import et règlement restent les vrais.
        Event::where('provider', $provider->name())->where('external_id', $id)
            ->whereIn('status', [EventStatus::Scheduled, EventStatus::Live])
            ->update(['starts_at' => $kickOff]);

        $this->info("Match {$id} terminé sur le score {$score[1]}-{$score[2]} (à importer : settlement:import-results --competition=soccer_epl).");

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
