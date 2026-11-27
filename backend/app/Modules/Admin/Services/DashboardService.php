<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Admin\Support\ReportingPeriod;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Lottery\Services\LotterySalesService;
use App\Modules\Payments\Enums\PaymentDirection;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
            // Joueurs qui ont misé dans la période (paris ou borlette), d'après le ledger (V2-F05).
            'active_players' => (int) $this->ledger($period)
                ->where('ledger_entries.type', LedgerEntryType::Stake->value)
                ->join('wallets', 'wallets.id', '=', 'ledger_entries.wallet_id')
                ->distinct()
                ->count('wallets.user_id'),
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
            'lottery' => $this->lottery($period),
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
            // Configuration réelle (V2-F05) : un prestataire de dépôt activé, retraits ouverts.
            'payments_available' => collect((array) config('payments.providers'))->contains(fn ($p) => (bool) ($p['enabled'] ?? false)),
            'withdrawals_available' => (bool) config('payments.withdrawals.enabled'),
            'ledger' => $this->gaming($period),
            'payments' => $this->payments($period),
        ];
    }

    /**
     * Borlette (V2-F05) : fiches vendues dans la période ; gains d'après les écritures « gain » du ledger de la période
     * (versées au règlement), hors remboursements des lignes annulées.
     *
     * @return array<string, int>
     */
    private function lottery(ReportingPeriod $period): array
    {
        $sold = $this->inPeriod(LotteryTicket::query(), 'placed_at', $period);

        return [
            'tickets_sold' => (clone $sold)->count(),
            'stakes_minor' => (int) (clone $sold)->sum('stake_minor'),
            'tickets_settled' => $this->inPeriod(LotteryTicket::query(), 'settled_at', $period)->count(),
            'payouts_minor' => (int) $this->ledger($period)
                ->where('type', LedgerEntryType::Payout->value)
                ->where('related_type', LotterySalesService::LEDGER_TYPE)
                ->sum('amount_minor'),
        ];
    }

    /**
     * Jeu d'après le ledger (V2-F05) : écritures de la période liées à un pari ou à une fiche de borlette. Les montants
     * sont signés (mise au débit, gain au crédit) : le produit brut est l'opposé de leur somme, compensations comprises.
     *
     * @return array<string, mixed>
     */
    private function gaming(ReportingPeriod $period): array
    {
        $products = ['sports' => 'bet', 'lottery' => LotterySalesService::LEDGER_TYPE];
        $rows = $this->ledger($period)
            ->whereIn('related_type', array_values($products))
            ->whereIn('type', [LedgerEntryType::Stake->value, LedgerEntryType::Payout->value, LedgerEntryType::Refund->value, LedgerEntryType::Reversal->value])
            ->groupBy('related_type')
            ->selectRaw('related_type')
            // Mises : écritures « mise ». Retours : gains, remboursements et compensations, avec leur signe (annuler
            // un gain par une correction diminue les retours ; ce n'est pas une mise).
            ->selectRaw('SUM(CASE WHEN type = ? THEN -amount_minor ELSE 0 END) AS stakes', [LedgerEntryType::Stake->value])
            ->selectRaw('SUM(CASE WHEN type <> ? THEN amount_minor ELSE 0 END) AS returns', [LedgerEntryType::Stake->value])
            ->get()
            ->keyBy('related_type');

        $byProduct = [];
        foreach ($products as $product => $relatedType) {
            $stakes = (int) ($rows[$relatedType]->stakes ?? 0);
            $returns = (int) ($rows[$relatedType]->returns ?? 0);
            $byProduct[] = [
                'product' => $product,
                'stakes_minor' => $stakes,
                'returns_minor' => $returns,
                'gross_gaming_revenue_minor' => $stakes - $returns,
            ];
        }

        $stakes = array_sum(array_column($byProduct, 'stakes_minor'));
        $returns = array_sum(array_column($byProduct, 'returns_minor'));

        return [
            'stakes_minor' => $stakes,
            'returns_minor' => $returns,
            'gross_gaming_revenue_minor' => $stakes - $returns,
            'by_product' => $byProduct,
        ];
    }

    /**
     * Dépôts et retraits d'après le ledger (V2-F05) : prestataire et saisies manuelles du Super Admin, crédits fictifs
     * exclus. Retraits nets des retraits échoués (écriture compensatoire d'un retrait, datée de la période).
     *
     * @return array<string, mixed>
     */
    private function payments(ReportingPeriod $period): array
    {
        $deposits = $this->ledger($period)->where('ledger_entries.type', LedgerEntryType::Deposit->value);
        $withdrawals = $this->ledger($period)->where('ledger_entries.type', LedgerEntryType::Withdrawal->value);
        $returned = (int) $this->ledger($period)
            ->where('ledger_entries.type', LedgerEntryType::Reversal->value)
            ->whereIn('ledger_entries.reversal_of_id', DB::table('ledger_entries')->select('id')->where('type', LedgerEntryType::Withdrawal->value))
            ->sum('ledger_entries.amount_minor');

        // Premier dépôt de toute la vie du portefeuille daté de la période.
        $firstDeposits = DB::query()
            ->fromSub(DB::table('ledger_entries')->where('type', LedgerEntryType::Deposit->value)
                ->groupBy('wallet_id')->selectRaw('wallet_id, MIN(created_at) AS first_at'), 'firsts')
            ->where('first_at', '>=', $period->startUtc())
            ->where('first_at', '<', $period->endUtc())
            ->count();

        $depositsMinor = (int) (clone $deposits)->sum('ledger_entries.amount_minor');
        $withdrawalsMinor = -(int) (clone $withdrawals)->sum('ledger_entries.amount_minor') - $returned;
        $pending = PaymentTransaction::query()
            ->where('direction', PaymentDirection::Payout)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing]);

        return [
            'deposits_count' => (clone $deposits)->count(),
            'deposits_minor' => $depositsMinor,
            'depositors' => (int) (clone $deposits)->distinct()->count('ledger_entries.wallet_id'),
            'first_deposits' => $firstDeposits,
            'withdrawals_count' => (clone $withdrawals)->count(),
            'withdrawals_minor' => $withdrawalsMinor,
            'net_deposits_minor' => $depositsMinor - $withdrawalsMinor,
            'pending_withdrawals_count' => (clone $pending)->count(),
            'pending_withdrawals_minor' => (int) (clone $pending)->sum('amount_minor'),
            'deposits_daily_minor' => $this->daily(LedgerEntry::query()->where('type', LedgerEntryType::Deposit), 'created_at', 'SUM(amount_minor)', $period),
        ];
    }

    /** Écritures du ledger datées de la période (requête de base, sans modèle : agrégats seulement). */
    private function ledger(ReportingPeriod $period): QueryBuilder
    {
        return DB::table('ledger_entries')
            ->where('ledger_entries.created_at', '>=', $period->startUtc())
            ->where('ledger_entries.created_at', '<', $period->endUtc());
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
