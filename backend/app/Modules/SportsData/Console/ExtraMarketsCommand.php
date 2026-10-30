<?php

namespace App\Modules\SportsData\Console;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Services\CatalogueSyncService;
use Illuminate\Console\Command;

/**
 * Charge les marchés que la synchro par compétition ne fournit pas (double chance, les deux équipes marquent,
 * score exact) pour les matchs de football à venir déjà au catalogue, un appel par match.
 * Coût chez The Odds API : marchés × régions crédits PAR MATCH — jamais planifiée, lancée à la main ;
 * --dry-run annonce le coût sans appeler. Le seuil SPORTS_DATA_MIN_REMAINING du fournisseur s'applique.
 */
class ExtraMarketsCommand extends Command
{
    protected $signature = 'sports-data:extra-markets
        {--markets=double_chance,btts,correct_score : Marchés demandés (clés The Odds API)}
        {--hours=72 : Matchs qui commencent dans ce délai}
        {--limit=0 : Nombre maximum de matchs (0 : tous)}
        {--competition=* : Clé(s) de compétition, par défaut toutes les compétitions de football actives}
        {--event=* : Identifiant(s) du match chez le fournisseur (relance d'un échec)}
        {--dry-run : Affiche les matchs et le coût sans appeler le fournisseur}';

    protected $description = 'Charge double chance, les deux marquent et score exact match par match (consomme des crédits)';

    public function handle(CatalogueSyncService $sync, SportsDataProvider $provider): int
    {
        $markets = implode(',', array_filter(array_map('trim', explode(',', (string) $this->option('markets')))));
        $regions = count(array_filter(explode(',', (string) config('sports_data.theodds.regions'))));
        $perEvent = count(explode(',', $markets)) * max(1, $regions);

        $events = Event::query()
            ->with('competition.sport')
            ->where('provider', $provider->name())
            ->where('status', EventStatus::Scheduled)
            ->whereBetween('starts_at', [now(), now()->addHours((int) $this->option('hours'))])
            ->when($this->option('event'), fn ($q, array $ids) => $q->whereIn('external_id', $ids))
            ->whereHas('competition', fn ($q) => $q->where('is_active', true)
                ->when($this->option('competition'),
                    fn ($c, array $keys) => $c->whereIn('external_key', $keys),
                    fn ($c) => $c->whereHas('sport', fn ($s) => $s->where('slug', 'soccer'))))
            ->orderBy('starts_at')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        $this->info("Fournisseur : {$provider->name()} — marchés : {$markets} — {$events->count()} match(s), "
            ."{$perEvent} crédit(s) par match, coût estimé : ".($events->count() * $perEvent).' crédit(s).');

        if ($this->option('dry-run') || $events->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($events as $event) {
            $label = "{$event->competition->external_key} · {$event->homeTeam?->name} – {$event->awayTeam?->name}";

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
}
