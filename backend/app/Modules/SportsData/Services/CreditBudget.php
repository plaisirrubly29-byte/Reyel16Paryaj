<?php

namespace App\Modules\SportsData\Services;

use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\QuotaData;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\ProviderApiCall;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Budget de crédits du fournisseur (The Odds API : 500 crédits/mois en offre gratuite).
 *
 * - Crédits restants : quota lu dans les en-têtes x-requests-* s'il date de la période en cours,
 *   sinon crédits mensuels − crédits journalisés depuis le début de la période (ProviderApiCall).
 * - Budget d'une synchro = (restants − réserve) / synchros prévues d'ici la remise à zéro (cron de synchro).
 * - Un appel de cotes coûte marchés × régions crédits ; les compétitions synchronisées le moins récemment
 *   passent en premier, les autres sont reportées. Les compétitions hors saison (liste gratuite /sports)
 *   ou sans match dans l'horizon (liste gratuite /events) ne sont jamais appelées.
 *
 * Aucun appel payant ici ; l'horloge est now() (figeable en test).
 */
class CreditBudget
{
    /** Plafond du décompte des synchros restantes (un cron "chaque minute" en compte ~43 000 par mois). */
    public const MAX_RUNS = 10000;

    private const SPORTS_TTL_SECONDS = 6 * 3600;

    /**
     * @param  array<string, mixed>  $config  config('sports_data')
     */
    public function __construct(
        private readonly SportsDataProvider $provider,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    /** Début de la période de quota contenant $now (jour de remise à zéro, ramené au dernier jour des mois courts). */
    public static function periodStartFor(CarbonImmutable $now, int $resetDay): CarbonImmutable
    {
        $start = self::resetDateIn($now->startOfMonth(), $resetDay);

        return $now->gte($start) ? $start : self::resetDateIn($now->startOfMonth()->subMonthNoOverflow(), $resetDay);
    }

    public static function nextResetFor(CarbonImmutable $now, int $resetDay): CarbonImmutable
    {
        return self::resetDateIn(self::periodStartFor($now, $resetDay)->startOfMonth()->addMonthNoOverflow(), $resetDay);
    }

    /** Nombre d'exécutions du cron dans [$from, $to[ (minute de $from comprise), plafonné à $cap. */
    public static function runsBetween(string $cron, CarbonImmutable $from, CarbonImmutable $to, int $cap = self::MAX_RUNS): int
    {
        $expression = new CronExpression($cron);
        $runs = 0;
        $next = $expression->getNextRunDate($from->startOfMinute(), 0, true);

        while ($next < $to && $runs < $cap) {
            $runs++;
            $next = $expression->getNextRunDate($next);
        }

        return $runs;
    }

    public function provider(): string
    {
        return $this->provider->name();
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['budget_enabled'] ?? true);
    }

    public function monthlyCredits(): int
    {
        return (int) ($this->config['monthly_credits'] ?? 500);
    }

    public function reserve(): int
    {
        return (int) ($this->config['min_remaining_requests'] ?? 0);
    }

    public function cron(): string
    {
        return (string) ($this->config['sync_cron'] ?? '0 */4 * * *');
    }

    public function periodStart(): CarbonImmutable
    {
        return self::periodStartFor(CarbonImmutable::now(), $this->resetDay());
    }

    public function nextReset(): CarbonImmutable
    {
        return self::nextResetFor(CarbonImmutable::now(), $this->resetDay());
    }

    /** Synchros planifiées d'ici la remise à zéro, celle de la minute en cours comprise. */
    public function runsLeft(): int
    {
        return self::runsBetween($this->cron(), CarbonImmutable::now(), $this->nextReset());
    }

    /** Coût d'un appel de cotes pour une compétition : nombre de marchés × nombre de régions. */
    public function costPerCompetition(): int
    {
        $count = fn (string $list) => max(1, count(array_filter(array_map('trim', explode(',', $list)))));

        return $count((string) ($this->config['theodds']['markets'] ?? 'h2h')) * $count((string) ($this->config['theodds']['regions'] ?? 'eu'));
    }

    /** "fournisseur" si le quota des en-têtes est à jour pour la période, sinon "journal". */
    public function source(): string
    {
        return $this->freshQuota() !== null ? 'fournisseur' : 'journal';
    }

    public function remaining(): int
    {
        return $this->freshQuota()?->remaining ?? max(0, $this->monthlyCredits() - $this->journalCost());
    }

    public function used(): int
    {
        return $this->freshQuota()?->used ?? $this->journalCost();
    }

    public function perRunBudget(): int
    {
        return max(0, intdiv($this->remaining() - $this->reserve(), max(1, $this->runsLeft())));
    }

    /**
     * Choisit les compétitions à synchroniser maintenant.
     *
     * @param  list<string>  $competitions  dans l'ordre configuré
     */
    public function plan(array $competitions): BudgetPlan
    {
        $skipped = [];
        $active = $this->activeCompetitions();

        if ($active !== null) {
            foreach ($competitions as $key) {
                if (! array_key_exists($key, $active)) {
                    $skipped[$key] = BudgetPlan::REASON_UNKNOWN;
                } elseif (! $active[$key]) {
                    $skipped[$key] = BudgetPlan::REASON_OFF_SEASON;
                }
            }
        }

        foreach ($competitions as $key) {
            if (! isset($skipped[$key]) && ! $this->hasUpcomingEvents($key)) {
                $skipped[$key] = BudgetPlan::REASON_NO_EVENTS;
            }
        }

        $candidates = array_values(array_filter($competitions, fn ($key) => ! isset($skipped[$key])));
        $order = array_flip($competitions);
        $lastSync = [];
        foreach ($candidates as $key) {
            $lastSync[$key] = $this->lastSyncedAt($key)?->getTimestamp() ?? 0;
        }
        usort($candidates, fn ($a, $b) => [$lastSync[$a], $order[$a]] <=> [$lastSync[$b], $order[$b]]);

        $budget = $this->perRunBudget();
        $cost = $this->costPerCompetition();
        $sync = array_slice($candidates, 0, intdiv($budget, $cost));

        foreach (array_slice($candidates, count($sync)) as $key) {
            $skipped[$key] = BudgetPlan::REASON_BUDGET;
        }

        return new BudgetPlan($sync, $skipped, $budget, $cost);
    }

    /**
     * Compétitions proposées par le fournisseur (clé => active), via la liste gratuite /sports mise en cache 6 h.
     * null si la liste est indisponible : on ne saute alors rien pour ce motif.
     *
     * @return array<string, bool>|null
     */
    public function activeCompetitions(): ?array
    {
        $cacheKey = "sports_data:{$this->provider()}:sports";
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $active = [];
            foreach ($this->provider->sports() as $sport) {
                $active[$sport->key] = $sport->active;
            }
        } catch (SportsDataException) {
            return null;
        }

        $this->cache->put($cacheKey, $active, self::SPORTS_TTL_SECONDS);

        return $active;
    }

    /**
     * Au moins un match dans l'horizon de synchro, d'après la liste gratuite /events.
     * Si la liste est indisponible, on suppose que oui (la synchro reste possible).
     */
    public function hasUpcomingEvents(string $competition): bool
    {
        try {
            $starts = $this->provider->upcomingStarts($competition);
        } catch (SportsDataException) {
            return true;
        }

        $now = CarbonImmutable::now();
        $horizon = $now->addDays((int) ($this->config['horizon_days'] ?? 7));

        foreach ($starts as $start) {
            if ($start->gt($now) && $start->lte($horizon)) {
                return true;
            }
        }

        return false;
    }

    public function markSynced(string $competition): void
    {
        $this->cache->forever($this->lastSyncKey($competition), CarbonImmutable::now()->toIso8601String());
    }

    /** Dernière synchro réussie (mémorisée, sinon déduite des matchs en base). */
    public function lastSyncedAt(string $competition): ?CarbonImmutable
    {
        $at = $this->cache->get($this->lastSyncKey($competition))
            ?? Event::query()
                ->join('competitions', 'competitions.id', '=', 'events.competition_id')
                ->where('competitions.provider', $this->provider())
                ->where('competitions.external_key', $competition)
                ->max('events.last_synced_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Consommation de la période en cours par compétition et point d'accès.
     *
     * @return Collection<int, object{competition: ?string, endpoint: string, calls: int, credits: int, last_call: string}>
     */
    public function usage(): Collection
    {
        return ProviderApiCall::query()
            ->where('provider', $this->provider())
            ->where('called_at', '>=', $this->periodStart())
            ->groupBy('competition', 'endpoint')
            ->orderBy('competition')
            ->orderBy('endpoint')
            ->selectRaw('competition, endpoint, count(*) as calls, sum(cost) as credits, max(called_at) as last_call')
            ->toBase()
            ->get();
    }

    public function lastCallAt(): ?CarbonImmutable
    {
        $at = ProviderApiCall::where('provider', $this->provider())->max('called_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /** Quota des en-têtes, ignoré s'il a été observé avant le début de la période (remise à zéro depuis). */
    private function freshQuota(): ?QuotaData
    {
        $quota = $this->provider->quota();

        return $quota !== null && $quota->observedAt->gte($this->periodStart()) ? $quota : null;
    }

    private function journalCost(): int
    {
        return (int) ProviderApiCall::where('provider', $this->provider())
            ->where('called_at', '>=', $this->periodStart())
            ->sum('cost');
    }

    private function resetDay(): int
    {
        return max(1, min(31, (int) ($this->config['quota_reset_day'] ?? 1)));
    }

    private function lastSyncKey(string $competition): string
    {
        return "sports_data:{$this->provider()}:last_sync:{$competition}";
    }

    private static function resetDateIn(CarbonImmutable $month, int $resetDay): CarbonImmutable
    {
        return $month->setDay(min(max(1, $resetDay), $month->daysInMonth))->startOfDay();
    }
}
