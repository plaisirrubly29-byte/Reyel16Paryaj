<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Admin\Models\FinanceCorrection;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use Illuminate\Support\Carbon;

/**
 * Indicateurs de la vue d'ensemble admin. Chaque section n'est calculée que si le rôle
 * a la permission correspondante : un Support ne reçoit jamais les chiffres financiers.
 */
class DashboardService
{
    /** Nombre de jours des séries quotidiennes (graphiques). */
    public const DAYS = 14;

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    public function summary(array $permissions): array
    {
        $can = fn (string $permission) => in_array($permission, $permissions, true);

        return array_filter([
            'generated_at' => now()->toIso8601String(),
            'currency' => config('wallet.currency'),
            'users' => $can('users.view') ? $this->users() : null,
            'betting' => $can('tickets.view') ? $this->betting() : null,
            'finance' => $can('ledger.view') ? $this->finance() : null,
            'catalogue' => $can('events.view') ? $this->catalogue() : null,
        ], fn ($section) => $section !== null);
    }

    /** @return array<string, mixed> */
    private function users(): array
    {
        $counts = User::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[AccountStatus::Active->value] ?? 0),
            'suspended' => (int) ($counts[AccountStatus::Suspended->value] ?? 0),
            'excluded' => (int) ($counts[AccountStatus::Excluded->value] ?? 0),
            'new_7d' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'registrations' => $this->daily(User::query(), 'created_at', 'COUNT(*)'),
        ];
    }

    /** @return array<string, mixed> */
    private function betting(): array
    {
        $today = BetTicket::query()->where('placed_at', '>=', now()->startOfDay());
        $pending = BetTicket::query()->where('status', BetTicketStatus::Pending);
        $byStatus = BetTicket::query()
            ->where('placed_at', '>=', $this->since())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'tickets_today' => (clone $today)->count(),
            'stake_today_minor' => (int) (clone $today)->sum('stake_minor'),
            'pending_count' => (clone $pending)->count(),
            // Gain maximal dû si tous les tickets en cours gagnaient : exposition de la maison.
            'pending_exposure_minor' => (int) (clone $pending)->sum('potential_payout_minor'),
            'status_breakdown' => collect(BetTicketStatus::cases())
                ->map(fn (BetTicketStatus $s) => ['status' => $s->value, 'count' => (int) ($byStatus[$s->value] ?? 0)])
                ->all(),
            'tickets' => $this->daily(BetTicket::query(), 'placed_at', 'COUNT(*)'),
            'stakes_minor' => $this->daily(BetTicket::query(), 'placed_at', 'SUM(stake_minor)'),
        ];
    }

    /** @return array<string, mixed> */
    private function finance(): array
    {
        $settled = BetTicket::query()->where('placed_at', '>=', $this->since())->where('status', '!=', BetTicketStatus::Pending);
        $stakes = (int) (clone $settled)->where('status', '!=', BetTicketStatus::Void)->sum('stake_minor');
        $payouts = (int) (clone $settled)->whereIn('status', [BetTicketStatus::Won])->sum('payout_minor');

        return [
            'period_days' => self::DAYS,
            'settled_stakes_minor' => $stakes,
            'payouts_minor' => $payouts,
            // Produit brut des jeux sur les tickets réglés de la période : mises - gains versés.
            'gross_gaming_revenue_minor' => $stakes - $payouts,
            'pending_corrections' => FinanceCorrection::query()->where('status', FinanceCorrectionStatus::Pending)->count(),
        ];
    }

    /** @return array<string, int> */
    private function catalogue(): array
    {
        $markets = Market::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'events_live' => Event::query()->where('status', EventStatus::Live)->count(),
            'events_next_24h' => Event::query()
                ->where('status', EventStatus::Scheduled)
                ->whereBetween('starts_at', [now(), now()->addDay()])
                ->count(),
            'markets_open' => (int) ($markets[MarketStatus::Open->value] ?? 0),
            'markets_suspended' => (int) ($markets[MarketStatus::Suspended->value] ?? 0),
        ];
    }

    private function since(): Carbon
    {
        return now()->subDays(self::DAYS - 1)->startOfDay();
    }

    /**
     * Série quotidienne sur DAYS jours (jours sans donnée à 0), du plus ancien au plus récent.
     * DATE() est compris par SQLite, MySQL et PostgreSQL.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return list<array{date: string, value: int}>
     */
    private function daily($query, string $column, string $aggregate): array
    {
        $rows = $query
            ->where($column, '>=', $this->since())
            ->selectRaw("DATE({$column}) as day, {$aggregate} as value")
            ->groupBy('day')
            ->pluck('value', 'day');

        return collect(range(self::DAYS - 1, 0))
            ->map(function (int $daysAgo) use ($rows) {
                $day = now()->subDays($daysAgo)->toDateString();

                return ['date' => $day, 'value' => (int) ($rows[$day] ?? 0)];
            })
            ->all();
    }
}
