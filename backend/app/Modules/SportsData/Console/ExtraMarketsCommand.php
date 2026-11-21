<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\ProviderApiCall;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Console\Command;

/**
 * Charge les marchés que la synchro par compétition ne fournit pas (double chance, remboursé si nul, les deux équipes
 * marquent, score exact, handicap, buts par équipe, autres lignes, pair/impair) pour les matchs de football à venir
 * déjà au catalogue, un appel par match.
 * Coût chez The Odds API : 1 crédit par marché reçu (× régions) PAR MATCH. Planifiée selon SPORTS_DATA_EXTRA_CRON
 * seulement si SPORTS_DATA_EXTRA=true ; arrêt dès que SPORTS_DATA_EXTRA_DAILY_CREDITS serait dépassé (0 = sans plafond).
 * --dry-run annonce le coût maximal sans appeler. Le seuil SPORTS_DATA_MIN_REMAINING du fournisseur s'applique.
 */
class ExtraMarketsCommand extends Command
{
    /** Point d'accès journalisé des cotes d'un match (ProviderApiCall). */
    private const ENDPOINT = '/sports/{sport}/events/{event}/odds';

    protected $signature = 'sports-data:extra-markets
        {--markets= : Marchés demandés (clés The Odds API), par défaut SPORTS_DATA_EXTRA_MARKETS}
        {--hours= : Matchs qui commencent dans ce délai, par défaut SPORTS_DATA_EXTRA_HOURS}
        {--limit=0 : Nombre maximum de matchs (0 : tous)}
        {--competition=* : Clé(s) de compétition, par défaut SPORTS_DATA_EXTRA_COMPETITIONS ou tout le football actif}
        {--event=* : Identifiant(s) du match chez le fournisseur (pour relancer un échec)}
        {--dry-run : Affiche les matchs et le coût sans appeler le fournisseur}';

    protected $description = 'Charge double chance, handicap, buts par équipe, score exact… match par match (consomme des crédits)';

    public function handle(CatalogueSyncService $sync, SportsDataProvider $provider): int
    {
        $markets = implode(',', array_filter(array_map('trim', explode(',', (string) ($this->option('markets') ?: config('sports_data.extra_markets'))))));
        $regions = count(array_filter(explode(',', (string) config('sports_data.theodds.regions'))));
        $perEvent = count(explode(',', $markets)) * max(1, $regions);
        $hours = (int) ($this->option('hours') ?: config('sports_data.extra_markets_hours', 72));
        $competitions = $this->option('competition') ?: config('sports_data.extra_markets_competitions', []);
        $cap = (int) config('sports_data.extra_markets_daily_credits', 0);

        $events = Event::query()
            ->with('competition.sport')
            ->where('provider', $provider->name())
            ->where('status', EventStatus::Scheduled)
            ->whereBetween('starts_at', [now(), now()->addHours($hours)])
            ->when($this->option('event'), fn ($q, array $ids) => $q->whereIn('external_id', $ids))
            ->whereHas('competition', fn ($q) => $q->where('is_active', true)
                ->when($competitions,
                    fn ($c, array $keys) => $c->whereIn('external_key', $keys),
                    fn ($c) => $c->whereHas('sport', fn ($s) => $s->where('slug', 'soccer'))))
            ->orderBy('starts_at')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        $this->info("Fournisseur : {$provider->name()} — marchés : {$markets} — {$events->count()} match(s), "
            ."au plus {$perEvent} crédit(s) par match, coût estimé : ".($events->count() * $perEvent).' crédit(s).');

        if ($this->option('dry-run') || $events->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($events as $event) {
            $label = "{$event->competition->external_key} · {$event->homeTeam?->name} – {$event->awayTeam?->name}";

            if ($cap > 0 && $this->creditsSpentToday($provider) + $perEvent > $cap) {
                $this->warn("Plafond du jour atteint ({$this->creditsSpentToday($provider)} / {$cap} crédits) : matchs suivants reportés.");

                break;
            }

            try {
                $data = $provider->eventMarkets($event->competition->external_key, $event->external_id, $markets);
            } catch (SportsDataException $e) {
                $failed++;
                $rows[] = [$label, '—', "{$e->reason}: {$e->getMessage()}"];

                // Quota épuisé ou clé refusée : inutile de continuer.
                if (in_array($e->reason, [SportsDataException::QUOTA_EXHAUSTED, SportsDataException::UNAUTHORIZED], true)) {
                    break;
                }

                continue;
            }

            $report = $sync->syncEventMarkets($event->competition->external_key, $data);
            $failed += $report->succeeded() ? 0 : 1;
            $rows[] = [$label, implode(', ', array_unique(array_map(fn ($m) => $m->key, $data->markets))) ?: 'aucun', $report->error ?? 'OK'];
        }

        $this->table(['Match', 'Marchés reçus', 'État'], $rows);

        if ($quota = $provider->quota()) {
            $this->line("Quota restant : {$quota->remaining} requêtes.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Crédits réellement dépensés aujourd'hui en cotes match par match (journal des appels, en-tête x-requests-last). */
    private function creditsSpentToday(SportsDataProvider $provider): int
    {
        return (int) ProviderApiCall::where('provider', $provider->name())
            ->where('endpoint', self::ENDPOINT)
            ->where('called_at', '>=', now()->startOfDay())
            ->sum('cost');
    }
}
