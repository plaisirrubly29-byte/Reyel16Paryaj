<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Admin\Support\ReportingPeriod;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs de la vue d'ensemble admin. Chaque section n'est calculée que si le rôle
 * a la permission correspondante : un Support ne reçoit jamais les chiffres financiers.
 *
 * Définitions (aucun indicateur n'en remplace un autre) :
 * - mises : montant des tickets PLACÉS dans la période (date de placement) ;
 * - gains payés : montants versés aux tickets gagnants RÉGLÉS dans la période (date de règlement) ;
 * - remboursements : mises rendues aux tickets annulés réglés dans la période ;
 * - produit brut : mises des tickets réglés (hors annulés) - gains payés, sur les tickets réglés dans la période ;
 * - exposition : gains potentiels des tickets encore en cours, à l'instant présent (pas une perte certaine).
 */
class DashboardService
{
    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    public function summary(array $permissions, ReportingPeriod $period): array
    {
        $can = fn (string $permission) => in_array($permission, $permissions, true);

        return array_filter([
            'generated_at' => now()->toIso8601String(),
            'currency' => config('wallet.currency'),
            'period' => $period->toArray(),
            'users' => $can('users.view') ? $this->users($period) : null,
            'betting' => $can('tickets.view') ? $this->betting($period) : null,
            'finance' => $can('ledger.view') ? $this->finance($period) : null,
            'catalogue' => $can('events.view') ? $this->catalogue() : null,
        ], fn ($section) => $section !== null);
    }

    /** @return array<string, mixed> */
    private function users(ReportingPeriod $period): array
    {
        $counts = User::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $todayStart = CarbonImmutable::now(ReportingPeriod::timezone())->startOfDay()->utc();

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[AccountStatus::Active->value] ?? 0),
            'suspended' => (int) ($counts[AccountStatus::Suspended->value] ?? 0),
            'excluded' => (int) ($counts[AccountStatus::Excluded->value] ?? 0),
            'new_today' => User::query()->where('created_at', '>=', $todayStart)->count(),
            'new_in_period' => $this->inPeriod(User::query(), 'created_at', $period)->count(),
            'registrations' => $this->daily(User::query(), 'created_at', 'COUNT(*)', $period),
        ];
    }

    /** @return array<string, mixed> */
    private function betting(ReportingPeriod $period): array
    {
        $placed = fn () => $this->inPeriod(BetTicket::query(), 'placed_at', $period);
        $settled = fn () => $this->inPeriod(BetTicket::query(), 'settled_at', $period);
        $pending = BetTicket::query()->where('status', BetTicketStatus::Pending);
        $byStatus = $placed()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'tickets_placed' => $placed()->count(),
            'stakes_minor' => (int) $placed()->sum('stake_minor'),
            'tickets_settled' => $settled()->count(),
            'payouts_minor' => (int) $settled()->where('status', BetTicketStatus::Won)->sum('payout_minor'),
            'refunds_minor' => (int) $settled()->where('status', BetTicketStatus::Void)->sum('payout_minor'),
            'pending_count' => (clone $pending)->count(),
            // Gain maximal dû si tous les tickets en cours gagnaient : exposition de la maison, pas une perte certaine.
            'pending_exposure_minor' => (int) (clone $pending)->sum('potential_payout_minor'),
            'status_breakdown' => collect(BetTicketStatus::cases())
                ->map(fn (BetTicketStatus $s) => ['status' => $s->value, 'count' => (int) ($byStatus[$s->value] ?? 0)])
                ->all(),
            'stakes_by_sport' => $this->stakesBySport($period),
            'tickets' => $this->daily(BetTicket::query(), 'placed_at', 'COUNT(*)', $period),
            'stakes_daily_minor' => $this->daily(BetTicket::query(), 'placed_at', 'SUM(stake_minor)', $period),
        ];
    }

    /** @return array<string, mixed> */
    private function finance(ReportingPeriod $period): array
    {
        $settled = $this->inPeriod(BetTicket::query(), 'settled_at', $period);
        $stakes = (int) (clone $settled)->where('status', '!=', BetTicketStatus::Void)->sum('stake_minor');
        $payouts = (int) (clone $settled)->where('status', BetTicketStatus::Won)->sum('payout_minor');

        return [
            'settled_stakes_minor' => $stakes,
            'payouts_minor' => $payouts,
            'gross_gaming_revenue_minor' => $stakes - $payouts,
            'pending_corrections' => FinanceCorrection::query()->where('status', FinanceCorrectionStatus::Pending)->count(),
            // Fonds fictifs en V1 : aucun dépôt ni retrait réel tant que les paiements (P7-01) ne sont pas intégrés.
            'payments_available' => false,
        ];
    }

    /** @return array<string, int> */
    private function catalogue(): array
    {
        $markets = Market::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'events_live' => Event::query()->where('status', EventStatus::Live)->count(),
            'events_scheduled' => Event::query()->where('status', EventStatus::Scheduled)->where('starts_at', '>=', now())->count(),
            'events_next_24h' => Event::query()
                ->where('status', EventStatus::Scheduled)
                ->whereBetween('starts_at', [now(), now()->addDay()])
                ->count(),
            'markets_open' => (int) ($markets[MarketStatus::Open->value] ?? 0),
            'markets_suspended' => (int) ($markets[MarketStatus::Suspended->value] ?? 0),
        ];
    }

    /**
     * Mises de la période par sport. Un combiné qui mêle plusieurs sports est compté à part
     * (« multisport ») : sa mise n'est pas répartie arbitrairement entre les sports.
     *
     * @return list<array{sport: string, name: string, tickets: int, stakes_minor: int}>
     */
    private function stakesBySport(ReportingPeriod $period): array
    {
        $ticketSports = DB::table('bet_selections')
            ->join('events', 'events.id', '=', 'bet_selections.event_id')
            ->join('competitions', 'competitions.id', '=', 'events.competition_id')
            ->groupBy('bet_selections.bet_ticket_id')
            ->selectRaw('bet_selections.bet_ticket_id AS ticket_id, MIN(competitions.sport_id) AS sport_id, COUNT(DISTINCT competitions.sport_id) AS sports');

        $rows = DB::table('bet_tickets')
            ->joinSub($ticketSports, 'ts', 'ts.ticket_id', '=', 'bet_tickets.id')
            ->leftJoin('sports', 'sports.id', '=', 'ts.sport_id')
            ->where('bet_tickets.placed_at', '>=', $period->startUtc())
            ->where('bet_tickets.placed_at', '<', $period->endUtc())
            ->groupByRaw("CASE WHEN ts.sports > 1 THEN 'multisport' ELSE sports.slug END, CASE WHEN ts.sports > 1 THEN 'Combinés multisports' ELSE sports.name END")
            ->selectRaw("CASE WHEN ts.sports > 1 THEN 'multisport' ELSE sports.slug END AS sport")
            ->selectRaw("CASE WHEN ts.sports > 1 THEN 'Combinés multisports' ELSE sports.name END AS name")
            ->selectRaw('COUNT(*) AS tickets, SUM(bet_tickets.stake_minor) AS stakes_minor')
            ->get();

        return $rows
            ->map(fn ($row) => [
                'sport' => (string) $row->sport,
                'name' => (string) $row->name,
                'tickets' => (int) $row->tickets,
                'stakes_minor' => (int) $row->stakes_minor,
            ])
            ->sortByDesc('stakes_minor')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function inPeriod(Builder $query, string $column, ReportingPeriod $period): Builder
    {
        return $query->where($column, '>=', $period->startUtc())->where($column, '<', $period->endUtc());
    }

    /**
     * Série quotidienne sur la période, journées découpées dans le fuseau d'affichage (jours vides à 0).
     * Agrégation par heure UTC en base, puis regroupement en journées locales : exact même lors
     * d'un changement d'heure, et compatible SQLite, MySQL et PostgreSQL.
     *
     * @param  Builder<*>  $query
     * @return list<array{date: string, value: int}>
     */
    private function daily(Builder $query, string $column, string $aggregate, ReportingPeriod $period): array
    {
        $bucket = match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD HH24')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d %H')",
            default => "strftime('%Y-%m-%d %H', {$column})",
        };

        $hours = $this->inPeriod($query, $column, $period)
            ->selectRaw("{$bucket} AS hour_bucket, {$aggregate} AS value")
            ->groupBy('hour_bucket')
            ->pluck('value', 'hour_bucket');

        $totals = array_fill_keys($period->dates(), 0);
        foreach ($hours as $hour => $value) {
            $day = CarbonImmutable::createFromFormat('Y-m-d H', (string) $hour, 'UTC')
                ->setTimezone(ReportingPeriod::timezone())
                ->toDateString();
            if (array_key_exists($day, $totals)) {
                $totals[$day] += (int) $value;
            }
        }

        return array_map(fn (string $date, int $value) => ['date' => $date, 'value' => $value], array_keys($totals), $totals);
    }
}
