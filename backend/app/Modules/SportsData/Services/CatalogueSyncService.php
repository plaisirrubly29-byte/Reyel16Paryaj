<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\EventData;
use App\Modules\SportsData\Data\MarketData;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Competition;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Sport;
use App\Modules\SportsData\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronise les matchs et cotes d'une compétition depuis le fournisseur (P3-03).
 *
 * - Un seul appel au fournisseur par compétition (coût : marchés × régions crédits).
 * - Idempotent : rejouer les mêmes données ne crée rien ; la version de cote n'augmente
 *   que si une cote ou la ligne (totals) change, avec un historique (odds_snapshots).
 * - Validation avant écriture ; un événement invalide est ignoré et journalisé (un marché
 *   facultatif invalide n'est qu'ignoré, l'événement est gardé).
 * - En cas d'échec du fournisseur, rien n'est modifié : les cotes vieillissent et le catalogue
 *   les masque (règle de fraîcheur, Market::scopeBettable).
 */
class CatalogueSyncService
{
    /** Motif de suspension posé par le système (levé automatiquement quand des cotes fraîches arrivent). */
    public const AUTO_SUSPENSION_PREFIX = 'auto:';

    private const SPORT_NAMES = [
        'soccer' => 'Football',
        'basketball' => 'Basketball',
        'americanfootball' => 'Football américain',
        'baseball' => 'Baseball',
        'icehockey' => 'Hockey sur glace',
        'tennis' => 'Tennis',
        'mma' => 'Arts martiaux mixtes',
        'boxing' => 'Boxe',
        'rugbyleague' => 'Rugby à XIII',
        'rugbyunion' => 'Rugby',
        'cricket' => 'Cricket',
        'handball' => 'Handball',
        'aussierules' => 'Football australien',
    ];

    /** Pays des titres du fournisseur (« Ligue 1 - France », « La Liga - Spain »), affichés en français. */
    private const COUNTRY_NAMES = [
        'Argentina' => 'Argentine', 'Australia' => 'Australie', 'Austria' => 'Autriche', 'Belgium' => 'Belgique',
        'Brazil' => 'Brésil', 'Chile' => 'Chili', 'China' => 'Chine', 'Denmark' => 'Danemark', 'England' => 'Angleterre',
        'Finland' => 'Finlande', 'France' => 'France', 'Germany' => 'Allemagne', 'Greece' => 'Grèce', 'Ireland' => 'Irlande',
        'Italy' => 'Italie', 'Japan' => 'Japon', 'Korea' => 'Corée du Sud', 'Mexico' => 'Mexique', 'Netherlands' => 'Pays-Bas',
        'Norway' => 'Norvège', 'Poland' => 'Pologne', 'Portugal' => 'Portugal', 'Russia' => 'Russie', 'Saudi Arabia' => 'Arabie saoudite',
        'Scotland' => 'Écosse', 'Spain' => 'Espagne', 'Sweden' => 'Suède', 'Switzerland' => 'Suisse', 'Turkey' => 'Turquie',
        'USA' => 'États-Unis', 'Uruguay' => 'Uruguay',
    ];

    /**
     * Marchés synchronisés, dans l'ordre. Le 1X2 (h2h) est obligatoire : invalide, il fait rejeter l'événement.
     * Les autres sont facultatifs (invalides, ils sont ignorés) : double chance (1X, 12, X2), draw_no_bet (remboursé
     * si nul), totals (plus/moins de N buts ou points), btts (les deux équipes marquent : oui / non), correct_score
     * (score exact), spreads (handicap), odd_even (total pair ou impair) et les marchés à plusieurs lignes
     * (alternate_totals, alternate_spreads, team_totals, alternate_team_totals), dont la ligne est dans la clé de
     * chaque sélection ("over_2.5", "home_-1.5", "away_under_0.5"). Ils se règlent sur le score final.
     * Mêmes paris sur la 1re mi-temps (suffixe _h1), mi-temps / fin de match, corners et cartons (suffixes _corners,
     * _cards) : réglés avec les statistiques du match (V2-D09, MarketKeys::all() donne l'ordre).
     * Hors 1X2, plus/moins et handicap, ils ne viennent que de syncEventMarkets() chez The Odds API.
     */

    /** Clés de sélection du marché 1X2 (h2h). */
    private const DRAW_NAMES = ['draw', 'tie', 'nul', 'match nul'];

    /** Clés de sélection du marché totals. */
    private const TOTALS_NAMES = ['over' => 'over', 'under' => 'under'];

    /** Clés de sélection du marché btts. */
    private const BTTS_NAMES = ['yes' => 'yes', 'oui' => 'yes', 'no' => 'no', 'non' => 'no'];

    /** Clés de sélection du marché odd_even. */
    private const ODD_EVEN_NAMES = ['odd' => 'odd', 'impair' => 'odd', 'even' => 'even', 'pair' => 'even'];

    /** Sélections de la double chance : paire d'issues (dans l'ordre domicile, nul, extérieur) => clé. */
    private const DOUBLE_CHANCE_KEYS = ['home+draw' => 'home_draw', 'home+away' => 'home_away', 'draw+away' => 'draw_away'];

    public function __construct(private readonly SportsDataProvider $provider) {}

    /** Matchs à venir (dans l'horizon configuré). */
    public function sync(string $competitionKey): SyncReport
    {
        return $this->run($competitionKey, live: false);
    }

    /**
     * Matchs en cours (commencés depuis moins de live_window_minutes) : cotes en direct, statut "live".
     * Le fournisseur renvoie les matchs en cours dans le même appel que les matchs à venir.
     */
    public function syncLive(string $competitionKey): SyncReport
    {
        return $this->run($competitionKey, live: true);
    }

    /**
     * Marchés supplémentaires d'un match à venir déjà au catalogue (provider->eventMarkets()), enregistrés comme
     * ceux de la synchro : les marchés absents de $data ne sont pas touchés.
     */
    public function syncEventMarkets(string $competitionKey, EventData $data): SyncReport
    {
        $report = new SyncReport($competitionKey);

        if (! $data->startsAt->isFuture()) {
            $report->skipped++;

            return $report;
        }

        try {
            DB::transaction(fn () => $this->syncEvent($data, $report));
        } catch (Throwable $e) {
            $report->skipped++;
            $report->error = $e->getMessage();
            Log::warning('sports_data.event_rejected', ['competition' => $competitionKey, 'id' => $data->externalId, 'error' => $e->getMessage()]);
        }

        return $report;
    }

    private function run(string $competitionKey, bool $live): SyncReport
    {
        $report = new SyncReport($competitionKey);

        try {
            $events = $this->provider->events($competitionKey);
        } catch (SportsDataException $e) {
            $report->error = "{$e->reason}: {$e->getMessage()}";
            Log::warning('sports_data.sync_failed', $report->toArray());

            return $report;
        }

        $horizon = now()->addDays((int) config('sports_data.horizon_days', 7));
        $liveSince = now()->subMinutes((int) config('sports_data.live_window_minutes', 180));

        $selected = collect($events)
            ->filter(fn (EventData $e) => $live
                ? $e->startsAt->lte(now()) && $e->startsAt->gt($liveSince)
                : $e->startsAt->isFuture() && $e->startsAt->lte($horizon))
            ->sortBy(fn (EventData $e) => $e->startsAt->getTimestamp())
            ->take($live ? PHP_INT_MAX : (int) config('sports_data.max_events_per_competition', 10));

        $report->skipped += count($events) - $selected->count();

        foreach ($selected as $data) {
            try {
                DB::transaction(fn () => $this->syncEvent($data, $report, $live));
            } catch (Throwable $e) {
                $report->skipped++;
                Log::warning('sports_data.event_rejected', ['competition' => $competitionKey, 'id' => $data->externalId, 'error' => $e->getMessage()]);
            }
        }

        Log::info('sports_data.sync_done', $report->toArray());

        return $report;
    }

    private function syncEvent(EventData $data, SyncReport $report, bool $live = false): void
    {
        if (mb_strtolower(trim($data->homeTeam)) === mb_strtolower(trim($data->awayTeam))) {
            throw new \InvalidArgumentException('Équipes identiques.');
        }

        $competition = $this->competition($data);
        $sportId = $competition->sport_id;

        $event = Event::firstOrNew(['provider' => $this->provider->name(), 'external_id' => $data->externalId]);
        $isNew = ! $event->exists;

        // Un événement terminé, annulé ou réglé n'est plus modifié par la synchro des cotes ;
        // un match en cours ne l'est que par la synchro en direct.
        $updatable = $live ? [EventStatus::Scheduled, EventStatus::Live] : [EventStatus::Scheduled, EventStatus::Postponed];
        if (! $isNew && ! in_array($event->status, $updatable, true)) {
            $report->skipped++;

            return;
        }

        $event->fill([
            'competition_id' => $competition->id,
            'home_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->homeTeam)])->id,
            'away_team_id' => Team::firstOrCreate(['sport_id' => $sportId, 'name' => trim($data->awayTeam)])->id,
            'starts_at' => $data->startsAt,
            'status' => $live ? EventStatus::Live : EventStatus::Scheduled,
            'last_synced_at' => now(),
        ])->save();

        $isNew ? $report->eventsCreated++ : $report->eventsUpdated++;

        foreach (MarketKeys::provided() as $key) {
            // Sans fournisseur de statistiques, ces paris ne pourraient pas être réglés : marché non proposé.
            if (MarketKeys::needsStatistics($key) && (string) config('sports_data.statistics.provider') === '') {
                continue;
            }

            [$market, $selections, $line, $error] = $this->pickMarket($data, $key);
            if ($market === null) {
                if ($error !== null && $key === 'h2h') {
                    throw $error;
                }
                if ($error !== null) {
                    Log::warning('sports_data.market_rejected', ['id' => $data->externalId, 'market' => $key, 'error' => $error->getMessage()]);
                }

                continue;
            }

            // Cote du bookmaker de référence et meilleure cote suivie, gardées pour l'administration (V2-D03) : repères
            // d'une cote fixée à la main. Aucun appel de plus au fournisseur.
            $sources = $this->sourceOdds($data, $key, $line);
            $provider = array_map(fn (array $s) => $s['odds'], $selections);
            $best = $this->bestOdds($provider, $sources);

            // Argent réel : cote juste (moyenne des bookmakers) + marge de l'opérateur (V2-D03, D-18).
            if (OddsMargin::enabled()) {
                $selections = OddsMargin::apply(
                    $selections,
                    $sources,
                    OddsMargin::rate($data->sportKey, $key, $live),
                );
            }

            if ($this->syncMarket($event, $market, $selections, $line, $provider, $best)) {
                $report->oddsChanged++;
            }
        }

        if (! $live) {
            $this->syncHouseMarkets($event, $data, $report);
        }
    }

    /**
     * Marchés maison du football (V2-D10), cotés par REYEL 16 à partir de notre 1X2 publié et du plus/moins des
     * bookmakers (HouseMarkets). Avant le match seulement, avec au moins min_bookmakers sur le 1X2 ; une erreur de calcul
     * laisse les marchés maison tels quels sans toucher au reste du match.
     */
    private function syncHouseMarkets(Event $event, EventData $data, SyncReport $report): void
    {
        if (! HouseMarkets::enabled() || ! str_starts_with($data->sportKey, 'soccer')) {
            return;
        }

        try {
            [$h2h] = $this->pickMarket($data, 'h2h');
            [$totals, , $line] = $this->pickMarket($data, 'totals');
            if ($h2h === null || $totals === null || $line === null) {
                return;
            }

            $results = array_values(array_filter(
                $this->sourceOdds($data, 'h2h', null),
                fn (array $odds) => isset($odds['home'], $odds['draw'], $odds['away']) && count($odds) === 3,
            ));
            $overs = $this->sourceOdds($data, 'totals', $line);
            if (count($results) < (int) config('sports_data.house_markets.min_bookmakers', 2) || $overs === []) {
                return;
            }

            $fair = fn (array $sources, string $key) => array_sum(array_map(function (array $odds) use ($key) {
                $implied = array_map(fn (string $o) => 1 / (float) $o, $odds);

                return $implied[$key] / array_sum($implied);
            }, $sources)) / count($sources);

            // Probabilités du 1X2 tirées de NOTRE 1X2 publié (marge et plafonds compris), avec une marge au moins égale
            // à la sienne : un même pari (ex. « Match nul » de l'écart de victoire) n'est jamais mieux payé côté maison.
            $published = $event->markets()->where('key', 'h2h')->first()?->selections()->pluck('odds', 'key')
                ->map(fn ($o) => 1 / (float) $o)->all() ?? [];
            if (! isset($published['home'], $published['draw'], $published['away']) || count($published) !== 3) {
                return;
            }
            $published1x2 = array_sum($published);

            $markets = HouseMarkets::build(
                array_map(fn (float $p) => $p / $published1x2, $published),
                (float) $line,
                $fair($overs, 'over'),
                $data->homeTeam,
                $data->awayTeam,
                max((float) config('sports_data.house_markets.margin', 0.12), $published1x2 - 1),
            );

            $updatedAt = $h2h->updatedAt->min($totals->updatedAt);
            foreach ($markets as $key => $odds) {
                if ($this->syncMarket($event, new MarketData($key, 'reyel16', $updatedAt, []), $odds, null)) {
                    $report->oddsChanged++;
                }
            }
        } catch (Throwable $e) {
            Log::warning('sports_data.house_markets_failed', ['id' => $data->externalId, 'error' => $e->getMessage()]);
        }
    }

    private function competition(EventData $data): Competition
    {
        $slug = explode('_', $data->sportKey)[0];

        $sport = Sport::firstOrCreate(
            ['slug' => $slug],
            ['name' => self::SPORT_NAMES[$slug] ?? ucfirst($slug)],
        );

        // Un libellé configuré l'emporte sur le titre du fournisseur (ex. "EPL" → "Premier League").
        $label = config("sports_data.competition_labels.{$data->sportKey}") ?? $this->providerLabel($data->competition);

        $competition = Competition::firstOrCreate(
            ['provider' => $this->provider->name(), 'external_key' => $data->sportKey],
            ['sport_id' => $sport->id, 'name' => $label['name'], 'country' => $label['country'] ?? null],
        );

        if ($competition->name !== $label['name'] || $competition->country !== ($label['country'] ?? null)) {
            $competition->update(['name' => $label['name'], 'country' => $label['country'] ?? null]);
        }

        return $competition;
    }

    /**
     * Nom et pays tirés du titre du fournisseur : « Ligue 1 - France » → Ligue 1 / France,
     * « La Liga - Spain » → La Liga / Espagne ; sans pays reconnaissable, le titre entier.
     *
     * @return array{name: string, country: ?string}
     */
    private function providerLabel(string $title): array
    {
        $title = trim($title);

        if (preg_match('/^(.+?)\s+-\s+([^-]+)$/u', $title, $m) && isset(self::COUNTRY_NAMES[trim($m[2])])) {
            return ['name' => trim($m[1]), 'country' => self::COUNTRY_NAMES[trim($m[2])]];
        }

        return ['name' => $title, 'country' => null];
    }

    /**
     * Premier marché valide de cette clé (bookmaker de référence déjà filtré par le fournisseur s'il est configuré) :
     * un bookmaker aux lignes non prises en charge (handicap en quart de but…) laisse la place au suivant.
     *
     * Renvoie le marché retenu, ses sélections et sa ligne, ou [null, [], null, dernière erreur] si aucun n'est valide.
     *
     * @return array{0: ?MarketData, 1: array<string, array{name: string, odds: string}>, 2: ?string, 3: ?\InvalidArgumentException}
     */
    private function pickMarket(EventData $data, string $key): array
    {
        $error = null;

        foreach ($data->markets as $market) {
            if ($market->key !== $key || count($market->selections) < 2) {
                continue;
            }

            try {
                return [$market, ...$this->selections($data, $market), null];
            } catch (\InvalidArgumentException $e) {
                $error = $e;
            }
        }

        return [null, [], null, $error];
    }

    /**
     * Cotes de chaque bookmaker pour ce marché et cette ligne (clé de sélection => cote), pour la cote juste
     * (V2-D03). Un bookmaker dont le marché est invalide ou d'une autre ligne est ignoré.
     *
     * @return list<array<string, string>>
     */
    private function sourceOdds(EventData $data, string $key, ?string $line): array
    {
        $sources = [];
        foreach ($data->markets as $market) {
            if ($market->key !== $key || count($market->selections) < 2) {
                continue;
            }
            try {
                [$selections, $marketLine] = $this->selections($data, $market);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($marketLine === $line) {
                $sources[] = array_map(fn (array $s) => $s['odds'], $selections);
            }
        }

        return $sources;
    }

    /**
     * Meilleure cote de chaque sélection parmi les bookmakers suivis (V2-D03) : repère contre l'arbitrage quand un
     * administrateur fixe une cote à la main. Calcul en chaînes décimales.
     *
     * @param  array<string, string>  $provider  cotes du bookmaker de référence
     * @param  list<array<string, string>>  $sources
     * @return array<string, string>
     */
    private function bestOdds(array $provider, array $sources): array
    {
        $best = $provider;
        foreach ($sources as $odds) {
            foreach ($odds as $key => $value) {
                if (isset($best[$key]) && bccomp((string) $value, (string) $best[$key], 3) > 0) {
                    $best[$key] = $value;
                }
            }
        }

        return $best;
    }

    /**
     * Sélections validées d'un marché, par clé, et sa ligne (null pour le 1X2).
     *
     * @return array{0: array<string, array{name: string, odds: string}>, 1: ?string}
     *
     * @throws \InvalidArgumentException marché invalide
     */
    private function selections(EventData $data, MarketData $source): array
    {
        // Même analyse pour la 1re mi-temps, les corners ou les cartons (« totals_h1 » comme « totals »).
        return match (MarketKeys::base($source->key)) {
            'h2h' => [$this->h2hSelections($data, $source), null],
            'double_chance' => [$this->doubleChanceSelections($data, $source), null],
            'totals' => $this->totalsSelections($source),
            'btts' => [$this->bttsSelections($source), null],
            'correct_score' => [$this->correctScoreSelections($data, $source), null],
            'draw_no_bet' => [$this->drawNoBetSelections($data, $source), null],
            'spreads' => $this->spreadsSelections($data, $source),
            'alternate_spreads' => [$this->alternateSpreadsSelections($data, $source), null],
            'alternate_totals' => [$this->alternateTotalsSelections($source), null],
            'team_totals', 'alternate_team_totals' => [$this->teamTotalsSelections($data, $source), null],
            'odd_even' => [$this->oddEvenSelections($source), null],
            MarketKeys::HALFTIME_FULLTIME => [$this->halftimeFulltimeSelections($data, $source), null],
            default => throw new \InvalidArgumentException("Marché non pris en charge : {$source->key}"),
        };
    }

    /** Issue du match désignée par un nom de sélection : "home", "away" ou "draw". */
    private function outcome(EventData $data, string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        return match (true) {
            $normalized === mb_strtolower(trim($data->homeTeam)) => 'home',
            $normalized === mb_strtolower(trim($data->awayTeam)) => 'away',
            in_array($normalized, self::DRAW_NAMES, true) => 'draw',
            default => throw new \InvalidArgumentException("Sélection inconnue : {$name}"),
        };
    }

    /** @return array<string, array{name: string, odds: string}> */
    private function h2hSelections(EventData $data, MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = $this->outcome($data, $selection->name);
            $odds[$key] = ['name' => $key === 'draw' ? 'Match nul' : $selection->name, 'odds' => $selection->odds];
        }

        if (! isset($odds['home'], $odds['away'])) {
            throw new \InvalidArgumentException('Marché 1X2 incomplet (équipe à domicile ou à l\'extérieur absente).');
        }

        return $odds;
    }

    /**
     * Double chance : chaque sélection couvre deux issues, nommées « Arsenal/Draw », « Arsenal or Chelsea »…
     * Au moins deux des trois paires (1X, 12, X2), sans doublon.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function doubleChanceSelections(EventData $data, MarketData $source): array
    {
        $order = ['home' => 0, 'draw' => 1, 'away' => 2];
        $names = [
            'home_draw' => "{$data->homeTeam} ou nul",
            'home_away' => "{$data->homeTeam} ou {$data->awayTeam}",
            'draw_away' => "Nul ou {$data->awayTeam}",
        ];

        $odds = [];
        foreach ($source->selections as $selection) {
            $parts = preg_split('/\s*(?:\/|\s(?:or|ou)\s)\s*/iu', trim($selection->name)) ?: [];
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            }

            $pair = array_map(fn ($part) => $this->outcome($data, $part), $parts);
            usort($pair, fn ($a, $b) => $order[$a] <=> $order[$b]);
            $key = self::DOUBLE_CHANCE_KEYS[implode('+', $pair)]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => $names[$key], 'odds' => $selection->odds];
        }

        if (count($odds) < 2) {
            throw new \InvalidArgumentException('Marché double chance incomplet.');
        }

        return $odds;
    }

    /**
     * Les deux équipes marquent : exactement un « Oui » et un « Non ».
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function bttsSelections(MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = self::BTTS_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => $key === 'yes' ? 'Oui' : 'Non', 'odds' => $selection->odds];
        }

        if (! isset($odds['yes'], $odds['no'])) {
            throw new \InvalidArgumentException('Marché btts incomplet (oui ou non absent).');
        }

        return $odds;
    }

    /**
     * Score exact : sélections « Chelsea:2|Manchester United:1 » (buts de chaque équipe nommée), clé "2-1" dans
     * l'ordre domicile-extérieur. Au moins deux scores, sans doublon.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function correctScoreSelections(EventData $data, MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $goals = [];
            foreach (explode('|', $selection->name) as $part) {
                if (! preg_match('/^(.+):(\d{1,2})$/u', trim($part), $m)) {
                    throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
                }
                $goals[$this->outcome($data, $m[1])] = (int) $m[2];
            }

            if (! isset($goals['home'], $goals['away']) || count($goals) !== 2) {
                throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            }

            $key = "{$goals['home']}-{$goals['away']}";
            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => "{$goals['home']} - {$goals['away']}", 'odds' => $selection->odds];
        }

        if (count($odds) < 2) {
            throw new \InvalidArgumentException('Marché score exact incomplet.');
        }

        return $odds;
    }

    /**
     * Plus/moins : exactement un "Over" et un "Under" sur la même ligne.
     *
     * @return array{0: array<string, array{name: string, odds: string}>, 1: string}
     */
    private function totalsSelections(MarketData $source): array
    {
        $byKey = [];
        foreach ($source->selections as $selection) {
            $key = self::TOTALS_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");

            if (isset($byKey[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $byKey[$key] = $selection;
        }

        if (! isset($byKey['over'], $byKey['under'])) {
            throw new \InvalidArgumentException('Marché totals incomplet (plus ou moins absent).');
        }

        $line = $this->line($byKey['over']->point);
        if ($line === null || $line !== $this->line($byKey['under']->point)) {
            throw new \InvalidArgumentException('Ligne du marché totals absente, invalide ou différente entre plus et moins.');
        }

        return [[
            'over' => ['name' => "Plus de {$line}", 'odds' => $byKey['over']->odds],
            'under' => ['name' => "Moins de {$line}", 'odds' => $byKey['under']->odds],
        ], $line];
    }

    /**
     * Remboursé si nul : une cote par équipe, le nul rembourse le pari.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function drawNoBetSelections(EventData $data, MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = $this->outcome($data, $selection->name);
            if ($key === 'draw' || isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection inattendue : {$selection->name}");
            }
            $odds[$key] = ['name' => $selection->name, 'odds' => $selection->odds];
        }

        if (! isset($odds['home'], $odds['away'])) {
            throw new \InvalidArgumentException('Marché remboursé si nul incomplet.');
        }

        return ['home' => $odds['home'], 'away' => $odds['away']];
    }

    /**
     * Handicap : une cote par équipe, lignes opposées (Arsenal -0.5 / Chelsea +0.5). La ligne du marché est celle
     * de l'équipe à domicile. Lignes entières ou demi-buts seulement (un quart de but se règle à moitié).
     *
     * @return array{0: array<string, array{name: string, odds: string}>, 1: string}
     */
    private function spreadsSelections(EventData $data, MarketData $source): array
    {
        $odds = [];
        $points = [];
        foreach ($source->selections as $selection) {
            $key = $this->outcome($data, $selection->name);
            $point = $this->halfLine($selection->point, signed: true);
            if ($key === 'draw' || $point === null || isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection inattendue : {$selection->name} {$selection->point}");
            }
            $points[$key] = $point;
            $odds[$key] = ['name' => "{$selection->name} {$this->signed($point)}", 'odds' => $selection->odds];
        }

        if (! isset($odds['home'], $odds['away']) || bccomp(bcadd($points['home'], $points['away'], 2), '0', 2) !== 0) {
            throw new \InvalidArgumentException('Marché handicap incomplet ou lignes non opposées.');
        }

        return [['home' => $odds['home'], 'away' => $odds['away']], $points['home']];
    }

    /**
     * Handicap à plusieurs lignes : clé "home_-1.5", "away_+1.5" (ligne de l'équipe visée). Les lignes non prises en
     * charge (quart de but) sont écartées ; au moins deux sélections.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function alternateSpreadsSelections(EventData $data, MarketData $source): array
    {
        $rows = [];
        foreach ($source->selections as $selection) {
            $side = $this->outcome($data, $selection->name);
            if ($side === 'draw') {
                throw new \InvalidArgumentException("Sélection inattendue : {$selection->name}");
            }
            if (($point = $this->halfLine($selection->point, signed: true)) === null) {
                continue;
            }
            $rows[] = [$side === 'home' ? 0 : 1, $point, "{$side}_{$this->signed($point)}",
                ['name' => "{$selection->name} {$this->signed($point)}", 'odds' => $selection->odds]];
        }

        return $this->lineSelections($rows, 'handicap à plusieurs lignes');
    }

    /**
     * Plus/moins à plusieurs lignes : clé "over_2.5", "under_2.5".
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function alternateTotalsSelections(MarketData $source): array
    {
        $rows = [];
        foreach ($source->selections as $selection) {
            $side = self::TOTALS_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            if (($point = $this->halfLine($selection->point, signed: false)) === null) {
                continue;
            }
            $rows[] = [$point, $side === 'over' ? 0 : 1, "{$side}_{$point}",
                ['name' => ($side === 'over' ? 'Plus de ' : 'Moins de ').$point, 'odds' => $selection->odds]];
        }

        return $this->lineSelections($rows, 'plus/moins à plusieurs lignes');
    }

    /**
     * Buts (ou points) d'une équipe : clé "home_over_1.5", "away_under_0.5" ; l'équipe est dans la description.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function teamTotalsSelections(EventData $data, MarketData $source): array
    {
        $rows = [];
        foreach ($source->selections as $selection) {
            $team = (string) $selection->description;
            $side = $this->outcome($data, $team);
            $direction = self::TOTALS_NAMES[mb_strtolower(trim($selection->name))] ?? null;
            if ($side === 'draw' || $direction === null) {
                throw new \InvalidArgumentException("Sélection inconnue : {$team} {$selection->name}");
            }
            if (($point = $this->halfLine($selection->point, signed: false)) === null) {
                continue;
            }
            $rows[] = [$side === 'home' ? 0 : 1, $point, $direction === 'over' ? 0 : 1, "{$side}_{$direction}_{$point}",
                ['name' => trim($team).($direction === 'over' ? ' : plus de ' : ' : moins de ').$point, 'odds' => $selection->odds]];
        }

        return $this->lineSelections($rows, 'buts par équipe');
    }

    /**
     * Mi-temps / fin de match : sélections « Arsenal/Draw » (issue à la mi-temps / issue finale), clé "home/draw".
     * Au moins deux des neuf combinaisons, sans doublon.
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function halftimeFulltimeSelections(EventData $data, MarketData $source): array
    {
        $order = ['home' => 0, 'draw' => 1, 'away' => 2];
        $names = ['home' => $data->homeTeam, 'draw' => 'Nul', 'away' => $data->awayTeam];

        $rows = [];
        foreach ($source->selections as $selection) {
            $parts = explode('/', $selection->name);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            }
            [$half, $full] = array_map(fn (string $part) => $this->outcome($data, $part), $parts);
            $rows[] = [$order[$half], $order[$full], "{$half}/{$full}",
                ['name' => "{$names[$half]} / {$names[$full]}", 'odds' => $selection->odds]];
        }

        return $this->lineSelections($rows, 'mi-temps / fin de match');
    }

    /**
     * Total pair ou impair : exactement un « Impair » et un « Pair ».
     *
     * @return array<string, array{name: string, odds: string}>
     */
    private function oddEvenSelections(MarketData $source): array
    {
        $odds = [];
        foreach ($source->selections as $selection) {
            $key = self::ODD_EVEN_NAMES[mb_strtolower(trim($selection->name))]
                ?? throw new \InvalidArgumentException("Sélection inconnue : {$selection->name}");
            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$selection->name}");
            }
            $odds[$key] = ['name' => $key === 'odd' ? 'Impair' : 'Pair', 'odds' => $selection->odds];
        }

        if (! isset($odds['odd'], $odds['even'])) {
            throw new \InvalidArgumentException('Marché pair/impair incomplet.');
        }

        return ['odd' => $odds['odd'], 'even' => $odds['even']];
    }

    /**
     * Sélections d'un marché à plusieurs lignes, triées (critères de tri en tête de chaque ligne, puis clé et
     * sélection), sans doublon et au moins deux.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<string, array{name: string, odds: string}>
     */
    private function lineSelections(array $rows, string $label): array
    {
        usort($rows, function (array $a, array $b) {
            foreach (array_keys(array_slice($a, 0, -2)) as $i) {
                $order = is_string($a[$i]) ? bccomp($a[$i], $b[$i], 2) : $a[$i] <=> $b[$i];
                if ($order !== 0) {
                    return $order;
                }
            }

            return 0;
        });

        $odds = [];
        foreach ($rows as $row) {
            [$key, $selection] = array_slice($row, -2);
            if (isset($odds[$key])) {
                throw new \InvalidArgumentException("Sélection en double : {$key}");
            }
            $odds[$key] = $selection;
        }

        if (count($odds) < 2) {
            throw new \InvalidArgumentException("Marché {$label} incomplet.");
        }

        return $odds;
    }

    /**
     * Ligne entière ou demi-but ("-1.50" → "-1.5", "+2" → "2") ; null si absente, invalide, en quart de but, ou
     * négative pour un total.
     */
    private function halfLine(?string $point, bool $signed): ?string
    {
        if ($point === null || ! preg_match('/^[+-]?\d{1,3}(\.\d{1,2})?$/', $point)) {
            return null;
        }

        $value = bcadd(ltrim($point, '+'), '0', 2);
        if (! in_array(substr($value, -2), ['00', '50'], true) || (! $signed && bccomp($value, '0', 2) <= 0)) {
            return null;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '-0' ? '0' : $value;
    }

    /** "1.5" → "+1.5", "-1.5" → "-1.5", "0" → "0". */
    private function signed(string $line): string
    {
        return bccomp($line, '0', 2) > 0 ? "+{$line}" : $line;
    }

    /** Ligne en chaîne décimale positive sans zéros inutiles ("2.50" → "2.5", "3.0" → "3") ; null si invalide. */
    private function line(?string $point): ?string
    {
        if ($point === null || ! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $point) || bccomp($point, '0', 2) <= 0) {
            return null;
        }

        return rtrim(rtrim(bcadd($point, '0', 2), '0'), '.');
    }

    /**
     * Cote proposée de chaque sélection : la cote calculée ($odds), sauf une cote fixée à la main en vigueur
     * (selections.adjusted, V2-D03), que la synchronisation n'écrase pas. Cote calculée, cote du bookmaker de référence
     * et meilleure cote suivie sont mises à jour à chaque passage, sans nouvelle version si la cote proposée ne change
     * pas ($provider et $best : null pour un marché maison, sans bookmaker).
     *
     * @param  array<string, array{name: string, odds: string}>  $odds
     * @param  array<string, string>|null  $provider
     * @param  array<string, string>|null  $best
     * @return bool true si les cotes proposées ou la ligne ont changé (nouvelle version)
     */
    private function syncMarket(Event $event, MarketData $source, array $odds, ?string $line, ?array $provider = null, ?array $best = null): bool
    {
        /** @var Market $market */
        $market = $event->markets()->firstOrNew(['key' => $source->key]);

        // Cote plus ancienne que celle déjà enregistrée (réponses désordonnées) : ignorée.
        if ($market->exists && $market->odds_updated_at !== null && $source->updatedAt->lt($market->odds_updated_at)) {
            return false;
        }

        // Une seule lecture des sélections (comme avant) : sert à la comparaison et aux mises à jour.
        $existing = $market->exists ? $market->selections()->get()->keyBy('key') : collect();
        $current = $existing->map(fn ($s) => $this->normalize((string) $s->odds))->all();
        $incoming = [];
        foreach ($odds as $key => $selection) {
            $kept = $existing->get($key);
            $incoming[$key] = $kept?->adjusted ? $this->normalize((string) $kept->odds) : $this->normalize($selection['odds']);
        }
        ksort($current);
        ksort($incoming);
        // Un changement de ligne (ex. 2.5 → 3.5) change le pari même à cotes égales : nouvelle version.
        $changed = $current !== $incoming || ($market->exists && $market->line !== $line);

        if (! $market->exists) {
            $market->odds_version = 1;
            $market->status = MarketStatus::Open;
        } elseif ($changed) {
            $market->odds_version++;
        }

        // Des cotes fraîches lèvent une suspension automatique, jamais une suspension posée par un administrateur.
        if ($market->status === MarketStatus::Suspended && str_starts_with((string) $market->suspension_reason, self::AUTO_SUSPENSION_PREFIX)) {
            $market->status = MarketStatus::Open;
            $market->suspension_reason = null;
        }

        $market->fill([
            'line' => $line,
            'source_bookmaker' => $source->bookmaker,
            'odds_updated_at' => $source->updatedAt,
        ])->save();

        foreach ($odds as $key => $selection) {
            // Sélection déjà lue, sinon nouvelle ; save() n'écrit rien si rien n'a changé (test de charge P8-03).
            $model = $existing->get($key) ?? $market->selections()->make(['key' => $key]);
            $model->fill([
                'name' => $selection['name'],
                'odds' => $incoming[$key],
                'computed_odds' => $this->normalize($selection['odds']),
                'provider_odds' => isset($provider[$key]) ? $this->normalize($provider[$key]) : null,
                'best_odds' => isset($best[$key]) ? $this->normalize($best[$key]) : null,
            ])->save();

            if ($changed) {
                OddsSnapshot::create([
                    'selection_id' => $model->id,
                    'odds_version' => $market->odds_version,
                    'odds' => $incoming[$key],
                    'computed_odds' => $model->computed_odds,
                    'provider_odds' => $model->provider_odds,
                    'adjusted' => (bool) $model->adjusted,
                    'line' => $line,
                    'provider_updated_at' => $source->updatedAt,
                ]);
            }
        }

        if (! $changed) {
            return false;
        }

        // Une sélection disparue du fournisseur ne doit plus être proposée.
        $market->selections()->whereNotIn('key', array_keys($odds))->delete();

        return true;
    }

    private function normalize(string $odds): string
    {
        return bcadd($odds, '0', 3);
    }
}
