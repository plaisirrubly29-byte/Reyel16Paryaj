<?php

namespace App\Modules\Compliance\Services;

use App\Models\User;
use App\Modules\Compliance\Contracts\DepositHistory;
use App\Modules\Compliance\Contracts\LossHistory;
use App\Modules\Compliance\Contracts\SpendingHistory;
use App\Modules\Compliance\Contracts\StakeHistory;
use App\Modules\Compliance\Enums\LimitKind;
use App\Modules\Compliance\Enums\LimitPeriod;
use App\Modules\Compliance\Enums\ResponsiblePlayEventType;
use App\Modules\Compliance\Enums\RestrictionType;
use App\Modules\Compliance\Exceptions\ResponsibleGamingException;
use App\Modules\Compliance\Models\BettingLimit;
use App\Modules\Compliance\Models\ResponsibleGamingRestriction;
use App\Modules\Compliance\Models\ResponsiblePlayEvent;
use App\Modules\IdentityAccess\Services\AccountStatusService;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Jeu responsable côté joueur (P7-02, D-09) : limites de mise, pause, auto-exclusion, contrôle avant chaque pari ;
 * limites de dépôt, contrôlées avant chaque dépôt (V2-B06) ; limites de pertes nettes, contrôlées avant chaque pari,
 * rappel de durée de session et historique des dépenses (V2-E06).
 *
 * Règles (les plus protectrices tant que l'avis juridique J-8 n'est pas rendu) :
 * - une limite se baisse tout de suite ; une hausse ou une suppression attend le délai de réflexion ;
 * - une pause et une auto-exclusion sont irrévocables par le joueur ; une pause peut seulement être prolongée ;
 * - chaque changement et chaque pari refusé est inscrit au journal (responsible_play_events).
 */
class ResponsibleGamingService
{
    public function __construct(
        private readonly StakeHistory $stakes,
        private readonly DepositHistory $deposits,
        private readonly LossHistory $losses,
        private readonly SpendingHistory $spending,
        private readonly AccountStatusService $accounts,
    ) {}

    /**
     * État du joueur : limites de mise, de dépôt et de pertes (montant, utilisé sur la période, reste, changement
     * en attente), pause et rappel de durée de session.
     *
     * @return array{limits: array<string, array<string, mixed>>, deposit_limits: array<string, array<string, mixed>>, loss_limits: array<string, array<string, mixed>>, pause: ?ResponsibleGamingRestriction, session_reminder_minutes: ?int}
     */
    public function status(User $user): array
    {
        return [
            'limits' => $this->limitsStatus($user, LimitKind::Stake),
            'deposit_limits' => $this->limitsStatus($user, LimitKind::Deposit),
            'loss_limits' => $this->limitsStatus($user, LimitKind::Loss),
            'pause' => $this->activePause($user),
            'session_reminder_minutes' => $user->session_reminder_minutes,
        ];
    }

    /**
     * Fixe la limite d'une période (de mise, de dépôt ou de pertes, selon $kind). Baisse (ou première limite) :
     * immédiate, et annule une hausse en attente. Hausse ou suppression ($amountMinor null) : appliquée après le délai
     * de réflexion.
     */
    public function setLimit(User $user, LimitPeriod $period, ?int $amountMinor, LimitKind $kind = LimitKind::Stake): BettingLimit
    {
        return DB::transaction(function () use ($user, $period, $amountMinor, $kind) {
            $model = $kind->model();
            $limit = $this->applyDueChange(
                $model::where('user_id', $user->id)->where('period', $period)->lockForUpdate()->first()
            ) ?? new $model(['user_id' => $user->id, 'period' => $period]);

            $current = $limit->amount_minor;
            $stricter = $amountMinor !== null && ($current === null || $amountMinor <= $current);

            if ($stricter) {
                if ($amountMinor === $current && ! $limit->hasPendingChange()) {
                    return $limit; // rien ne change
                }

                $limit->fill(['amount_minor' => $amountMinor, 'pending_amount_minor' => null, 'pending_effective_at' => null])->save();
                $this->record($user, $kind->loweredEvent(), ['period' => $period->value, 'from_minor' => $current, 'to_minor' => $amountMinor]);

                return $limit;
            }

            if ($current === null) {
                return $limit; // suppression demandée alors qu'aucune limite n'existe
            }

            // Hausse ou suppression : le délai repart de zéro à chaque nouvelle demande.
            $effectiveAt = now()->addDays((int) config('compliance.limits.increase_delay_days'));
            $limit->fill(['pending_amount_minor' => $amountMinor, 'pending_effective_at' => $effectiveAt])->save();
            $this->record($user, $kind->increaseScheduledEvent(), [
                'period' => $period->value,
                'from_minor' => $current,
                'to_minor' => $amountMinor,
                'effective_at' => $effectiveAt->toIso8601String(),
            ]);

            return $limit;
        });
    }

    /** Pause irrévocable. Une pause déjà en cours n'est jamais raccourcie, seulement prolongée. */
    public function pause(User $user, string $duration): ResponsibleGamingRestriction
    {
        $endsAt = $this->endOf(RestrictionType::Pause, $duration);

        return DB::transaction(function () use ($user, $duration, $endsAt) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $current = $this->activePause($user);
            if ($current !== null && $current->ends_at->gte($endsAt)) {
                return $current;
            }

            $pause = ResponsibleGamingRestriction::create([
                'user_id' => $user->id,
                'type' => RestrictionType::Pause,
                'duration' => $duration,
                'starts_at' => now(),
                'ends_at' => $endsAt,
            ]);
            $this->record($user, ResponsiblePlayEventType::PauseStarted, [
                'duration' => $duration,
                'ends_at' => $endsAt->toIso8601String(),
                'extends_previous' => $current !== null,
            ]);

            return $pause;
        });
    }

    /**
     * Auto-exclusion irrévocable, confirmée par le mot de passe : le compte passe en « excluded »
     * et ne peut plus se connecter. À l'échéance, réouverture uniquement sur demande (Conformité).
     *
     * @throws ApiException invalid_password
     */
    public function selfExclude(User $user, string $duration, string $password): ResponsibleGamingRestriction
    {
        $endsAt = $this->endOf(RestrictionType::SelfExclusion, $duration);

        if (! Hash::check($password, $user->password)) {
            throw new ApiException('invalid_password', __('Mot de passe incorrect.'), 422);
        }

        return DB::transaction(function () use ($user, $duration, $endsAt) {
            $exclusion = ResponsibleGamingRestriction::create([
                'user_id' => $user->id,
                'type' => RestrictionType::SelfExclusion,
                'duration' => $duration,
                'starts_at' => now(),
                'ends_at' => $endsAt,
            ]);

            $this->accounts->selfExclude($user);
            $this->record($user, ResponsiblePlayEventType::SelfExclusionStarted, ['duration' => $duration, 'ends_at' => $endsAt->toIso8601String()]);

            return $exclusion;
        });
    }

    /**
     * Contrôle avant d'accepter un pari, à appeler dans la transaction du pari : le verrou sur le joueur
     * sérialise ses paris simultanés, qui ne peuvent donc pas dépasser ensemble une limite.
     *
     * @throws ResponsibleGamingException betting_paused | stake_limit_exceeded
     */
    public function assertCanBet(User $user, int $stakeMinor): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();

        if (($pause = $this->activePause($user)) !== null) {
            throw new ResponsibleGamingException(ResponsiblePlayEventType::BetRefusedPause, 'betting_paused',
                __('Vous avez demandé une pause : les paris reprendront le :date.', ['date' => $pause->ends_at->toIso8601String()]),
                ['ends_at' => $pause->ends_at->toIso8601String()]);
        }

        foreach (LimitPeriod::cases() as $period) {
            $limit = $this->applyDueChange($this->limitFor($user, $period, LimitKind::Stake));
            if ($limit?->amount_minor === null) {
                continue;
            }

            $used = $this->stakes->stakedSince($user, now()->subMinutes($period->minutes()));
            if ($used + $stakeMinor > $limit->amount_minor) {
                throw new ResponsibleGamingException(ResponsiblePlayEventType::BetRefusedLimit, 'stake_limit_exceeded',
                    __('Cette mise dépasse votre limite de mise.'),
                    ['period' => $period->value, 'limit_minor' => $limit->amount_minor, 'remaining_minor' => max(0, $limit->amount_minor - $used)]);
            }
        }

        // Limites de pertes (V2-E06) : le pari est compté comme perdu, c'est le pire cas pour le joueur.
        foreach (LimitPeriod::cases() as $period) {
            $limit = $this->applyDueChange($this->limitFor($user, $period, LimitKind::Loss));
            if ($limit?->amount_minor === null) {
                continue;
            }

            $lost = $this->losses->lostSince($user, now()->subMinutes($period->minutes()));
            if ($lost + $stakeMinor > $limit->amount_minor) {
                throw new ResponsibleGamingException(ResponsiblePlayEventType::BetRefusedLossLimit, 'loss_limit_exceeded',
                    __('Si ce pari est perdu, vous dépasserez votre limite de pertes.'),
                    ['period' => $period->value, 'limit_minor' => $limit->amount_minor, 'remaining_minor' => max(0, $limit->amount_minor - $lost)]);
            }
        }
    }

    /**
     * Intervalle du rappel de durée de session ($minutes null : désactivé). Ce n'est pas une restriction :
     * le changement s'applique tout de suite, dans les deux sens, et il est journalisé.
     */
    public function setSessionReminder(User $user, ?int $minutes): void
    {
        $from = $user->session_reminder_minutes;
        if ($from === $minutes) {
            return;
        }

        DB::transaction(function () use ($user, $from, $minutes) {
            $user->forceFill(['session_reminder_minutes' => $minutes])->save();
            $this->record($user, ResponsiblePlayEventType::SessionReminderChanged, ['from_minutes' => $from, 'to_minutes' => $minutes]);
        });
    }

    /**
     * Historique des dépenses : les derniers jours puis les derniers mois (config compliance.history), du plus récent
     * au plus ancien, jours sans mouvement compris, dans le fuseau d'affichage.
     *
     * @return array{timezone: string, days: list<array<string, int|string>>, months: list<array<string, int|string>>}
     */
    public function spendingHistory(User $user): array
    {
        $timezone = (string) config('app.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $firstDay = $today->subDays((int) config('compliance.history.days') - 1);
        $firstMonth = $today->startOfMonth()->subMonthsNoOverflow((int) config('compliance.history.months') - 1);

        $daily = $this->spending->dailyTotals($user, $firstDay->min($firstMonth)->utc(), $timezone);
        $empty = ['deposits' => 0, 'withdrawals' => 0, 'stakes' => 0, 'payouts' => 0, 'refunds' => 0];
        // Résultat de jeu : positif si le joueur a gagné plus qu'il n'a misé.
        $row = fn (array $totals) => $totals + ['net' => $totals['payouts'] + $totals['refunds'] - $totals['stakes']];

        $days = [];
        for ($day = $today; $day->gte($firstDay); $day = $day->subDay()) {
            $days[] = ['date' => $day->toDateString()] + $row($daily[$day->toDateString()] ?? $empty);
        }

        $byMonth = [];
        foreach ($daily as $date => $totals) {
            foreach ($totals as $column => $amount) {
                $byMonth[substr($date, 0, 7)][$column] = ($byMonth[substr($date, 0, 7)][$column] ?? 0) + $amount;
            }
        }

        $months = [];
        for ($month = $today->startOfMonth(); $month->gte($firstMonth); $month = $month->subMonthNoOverflow()) {
            $months[] = ['month' => $month->format('Y-m')] + $row(($byMonth[$month->format('Y-m')] ?? []) + $empty);
        }

        return ['timezone' => $timezone, 'days' => $days, 'months' => $months];
    }

    /**
     * Contrôle avant d'enregistrer un dépôt (V2-B06), à appeler dans la transaction du dépôt : le verrou sur
     * le joueur sérialise ses dépôts simultanés, qui ne peuvent donc pas dépasser ensemble une limite.
     *
     * $pendingMinor : dépôts ouverts chez un prestataire, pas encore confirmés. Ils comptent dans la limite, sinon
     * plusieurs dépôts ouverts à la suite la dépasseraient ensemble une fois confirmés.
     *
     * @throws ResponsibleGamingException deposit_limit_exceeded
     */
    public function assertCanDeposit(User $user, int $amountMinor, int $pendingMinor = 0): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();

        foreach (LimitPeriod::cases() as $period) {
            $limit = $this->applyDueChange($this->limitFor($user, $period, LimitKind::Deposit));
            if ($limit?->amount_minor === null) {
                continue;
            }

            $used = $this->deposits->depositedSince($user, now()->subMinutes($period->minutes())) + $pendingMinor;
            if ($used + $amountMinor > $limit->amount_minor) {
                throw new ResponsibleGamingException(ResponsiblePlayEventType::DepositRefusedLimit, 'deposit_limit_exceeded',
                    __('Ce dépôt dépasse la limite de dépôt choisie par le joueur.'),
                    ['period' => $period->value, 'limit_minor' => $limit->amount_minor, 'remaining_minor' => max(0, $limit->amount_minor - $used)]);
            }
        }
    }

    /**
     * Vue de la Conformité sur un joueur : état actuel, pauses et exclusions, journal récent.
     *
     * @return array{limits: array<string, array<string, mixed>>, pause: ?ResponsibleGamingRestriction, restrictions: Collection<int, ResponsibleGamingRestriction>, events: Collection<int, ResponsiblePlayEvent>}
     */
    public function overview(User $player): array
    {
        return $this->status($player) + [
            'restrictions' => ResponsibleGamingRestriction::where('user_id', $player->id)->orderByDesc('starts_at')->orderByDesc('id')->get(),
            'events' => ResponsiblePlayEvent::where('user_id', $player->id)->orderByDesc('id')->limit(100)->get(),
        ];
    }

    /**
     * Réouverture d'une auto-exclusion arrivée à échéance, à la demande du joueur, par la Conformité.
     * Avant l'échéance : refusée (l'auto-exclusion est irrévocable pendant sa durée).
     *
     * @throws ApiException exclusion_not_ended | invalid_status_transition
     */
    public function liftSelfExclusion(User $actor, User $player, string $reason): ResponsibleGamingRestriction
    {
        return DB::transaction(function () use ($actor, $player, $reason) {
            $exclusion = ResponsibleGamingRestriction::where('user_id', $player->id)
                ->where('type', RestrictionType::SelfExclusion)
                ->whereNull('lifted_at')
                ->orderByDesc('ends_at')
                ->lockForUpdate()
                ->first();

            if ($exclusion === null) {
                throw new ApiException('invalid_status_transition', __('Aucune auto-exclusion à lever pour ce compte.'), 409);
            }

            if ($exclusion->ends_at->isFuture()) {
                throw new ApiException('exclusion_not_ended', __('L\'auto-exclusion court jusqu\'au :date : elle ne peut pas être levée avant.', [
                    'date' => $exclusion->ends_at->toIso8601String(),
                ]), 409, ['ends_at' => $exclusion->ends_at->toIso8601String()]);
            }

            $this->accounts->endSelfExclusion($actor, $player, $reason);
            $exclusion->forceFill(['lifted_at' => now(), 'lifted_by' => $actor->id, 'lift_reason' => $reason])->save();

            ResponsiblePlayEvent::create([
                'user_id' => $player->id,
                'type' => ResponsiblePlayEventType::SelfExclusionLifted,
                'data' => ['restriction_id' => $exclusion->id, 'ended_at' => $exclusion->ends_at->toIso8601String()],
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $exclusion;
        });
    }

    /** Journalise un pari refusé, hors de la transaction annulée du pari. */
    public function recordRefusal(User $user, ResponsibleGamingException $refusal, int $stakeMinor): void
    {
        $this->record($user, $refusal->event, ['stake_minor' => $stakeMinor] + $refusal->details);
    }

    /** Journalise un dépôt refusé, hors de la transaction annulée du dépôt ; $actor : l'admin qui l'enregistrait. */
    public function recordDepositRefusal(User $user, ResponsibleGamingException $refusal, int $amountMinor, ?User $actor = null): void
    {
        $this->record($user, $refusal->event, ['amount_minor' => $amountMinor] + $refusal->details, $actor);
    }

    public function activePause(User $user): ?ResponsibleGamingRestriction
    {
        return ResponsibleGamingRestriction::active()
            ->where('user_id', $user->id)
            ->where('type', RestrictionType::Pause)
            ->orderByDesc('ends_at')
            ->first();
    }

    /** @return array<string, array<string, mixed>> */
    private function limitsStatus(User $user, LimitKind $kind): array
    {
        $limits = [];
        foreach (LimitPeriod::cases() as $period) {
            $limit = $this->applyDueChange($this->limitFor($user, $period, $kind));
            $since = now()->subMinutes($period->minutes());
            $used = match ($kind) {
                LimitKind::Stake => $this->stakes->stakedSince($user, $since),
                LimitKind::Deposit => $this->deposits->depositedSince($user, $since),
                LimitKind::Loss => $this->losses->lostSince($user, $since),
            };

            $limits[$period->value] = [
                'amount_minor' => $limit?->amount_minor,
                'used_minor' => $used,
                'remaining_minor' => $limit?->amount_minor === null ? null : max(0, $limit->amount_minor - $used),
                'pending' => $limit?->hasPendingChange()
                    ? ['amount_minor' => $limit->pending_amount_minor, 'effective_at' => $limit->pending_effective_at]
                    : null,
            ];
        }

        return $limits;
    }

    private function limitFor(User $user, LimitPeriod $period, LimitKind $kind): ?BettingLimit
    {
        return $kind->model()::where('user_id', $user->id)->where('period', $period)->first();
    }

    /** Applique une hausse ou une suppression arrivée à échéance. */
    private function applyDueChange(?BettingLimit $limit): ?BettingLimit
    {
        if ($limit === null || ! $limit->hasPendingChange() || $limit->pending_effective_at->isFuture()) {
            return $limit;
        }

        $from = $limit->amount_minor;
        $limit->fill(['amount_minor' => $limit->pending_amount_minor, 'pending_amount_minor' => null, 'pending_effective_at' => null])->save();
        $this->record($limit->user_id, $limit->kind()->changeAppliedEvent(), [
            'period' => $limit->period->value,
            'from_minor' => $from,
            'to_minor' => $limit->amount_minor,
        ]);

        return $limit;
    }

    private function endOf(RestrictionType $type, string $duration): CarbonImmutable
    {
        $durations = $type->durations();
        if (! isset($durations[$duration])) {
            throw new ApiException('invalid_duration', __('Durée non proposée.'), 422, ['allowed' => array_keys($durations)]);
        }

        return CarbonImmutable::now()->modify('+'.$durations[$duration]);
    }

    /** @param  array<string, mixed>  $data */
    private function record(User|int $user, ResponsiblePlayEventType $type, array $data, ?User $actor = null): void
    {
        ResponsiblePlayEvent::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'data' => $data,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
