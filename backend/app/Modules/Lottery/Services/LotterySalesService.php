<?php

namespace App\Modules\Lottery\Services;

use App\Models\User;
use App\Modules\Compliance\Exceptions\ResponsibleGamingException;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\Lottery\Enums\LotteryTicketStatus;
use App\Modules\Lottery\Models\LotteryBlockedNumber;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryPlay;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vente d'une fiche de borlette (V2-D09, contrat POST /lottery/tickets). Tout ou rien : une ligne refusée refuse la
 * fiche et rien n'est débité.
 *
 * Contrôles, dans une transaction : vente ouverte, tirage et date encore ouverts, forme des lignes, mises min/max du jeu,
 * jeu responsable (pause, limites), boules bloquées, plafond d'engagement par combinaison (sous verrou du tirage : deux
 * ventes simultanées sur un même tirage passent une par une), solde (ledger, écriture "stake").
 */
class LotterySalesService
{
    public const LEDGER_TYPE = 'lottery_ticket';

    public function __construct(
        private readonly LotteryRules $rules,
        private readonly LedgerService $ledger,
        private readonly ResponsibleGamingService $responsibleGaming,
    ) {}

    /**
     * @param  list<array{game: string, option?: int|null, numbers: list<string>, stake: array{minor: int, currency?: string}}>  $plays
     *
     * @throws ApiException|ValidationException
     */
    public function place(User $user, string $drawKey, ?string $date, array $plays, ?string $placementKey = null): LotteryTicket
    {
        if ($user->isAdmin()) {
            throw new ApiException('not_a_player', __('Seuls les comptes joueurs peuvent jouer.'), 422);
        }

        if (! $this->rules->salesOpen()) {
            throw new ApiException('lottery_sales_closed', __('La vente de fiches de borlette est fermée pour le moment.'), 403);
        }

        if ($placementKey !== null && ($existing = $this->replayed($user, $placementKey, $drawKey, $plays)) !== null) {
            return $existing;
        }

        $lines = $this->normalize($plays);

        try {
            return $this->accept($user, $drawKey, $date, $lines, $placementKey);
        } catch (ResponsibleGamingException $e) {
            $this->responsibleGaming->recordRefusal($user, $e, array_sum(array_column($lines, 'stake_minor')));

            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            if ($placementKey !== null && ($existing = $this->replayed($user, $placementKey, $drawKey, $plays)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Fiche déjà vendue à ce joueur avec cette clé ; 409 si la clé a servi à une autre fiche.
     *
     * @param  list<array<string, mixed>>  $plays
     */
    private function replayed(User $user, string $placementKey, string $drawKey, array $plays): ?LotteryTicket
    {
        $ticket = LotteryTicket::where('user_id', $user->id)->where('placement_key', $placementKey)->with('draw', 'plays')->first();
        if ($ticket === null) {
            return null;
        }

        $same = $ticket->draw->external_key === $drawKey
            && $ticket->plays->count() === count($plays)
            && $ticket->stake_minor === array_sum(array_map(fn ($p) => (int) ($p['stake']['minor'] ?? 0), $plays));

        if (! $same) {
            throw new ApiException('idempotency_key_reused', __('Cette clé d\'idempotence a déjà servi pour une autre requête.'), 409);
        }

        return $ticket;
    }

    /**
     * Forme de chaque ligne, d'après le barème : numéros, option, mise. Erreurs par ligne (plays.0.numbers…).
     *
     * @param  list<array<string, mixed>>  $plays
     * @return list<array{game: string, option: int|null, numbers: list<string>, stake_minor: int, currency: string|null, multipliers: array<int, string>, max_payout_minor: int}>
     */
    private function normalize(array $plays): array
    {
        $max = $this->rules->maxPlaysPerTicket();
        if (count($plays) > $max) {
            throw ValidationException::withMessages(['plays' => __('Une fiche compte au plus :max lignes.', ['max' => $max])]);
        }

        $errors = [];
        $lines = [];
        foreach (array_values($plays) as $i => $play) {
            $game = $this->rules->game((string) $play['game']);
            if ($game === null) {
                $errors["plays.{$i}.game"] = __('Ce jeu n\'est pas en vente.');

                continue;
            }

            $numbers = array_map('strval', $play['numbers']);
            $pattern = '/^\d{'.$game['digits'].'}$/';
            $valid = count($numbers) === $game['count'] && array_reduce($numbers, fn ($ok, $n) => $ok && preg_match($pattern, $n) === 1, true);
            if (! $valid) {
                $errors["plays.{$i}.numbers"] = $game['count'] > 1
                    ? __(':count boules de :digits chiffres.', ['count' => $game['count'], 'digits' => $game['digits']])
                    : __('Un numéro de :digits chiffres.', ['digits' => $game['digits']]);
            } elseif ($game['count'] === 2 && $numbers[0] === $numbers[1]) {
                $errors["plays.{$i}.numbers"] = __('Les deux boules d\'un maryaj doivent être différentes.');
            }

            $option = isset($play['option']) ? (int) $play['option'] : null;
            if ($game['options'] === [] ? $option !== null : ! in_array($option, $game['options'], true)) {
                $errors["plays.{$i}.option"] = $game['options'] === []
                    ? __('Ce jeu n\'a pas d\'option.')
                    : __('Choisissez l\'option :options.', ['options' => implode(', ', $game['options'])]);
            }

            $stake = (int) $play['stake']['minor'];
            if ($stake < $game['min_stake_minor'] || $stake > $game['max_stake_minor']) {
                $errors["plays.{$i}.stake"] = __('Mise entre :min et :max HTG.', [
                    'min' => number_format($game['min_stake_minor'] / 100, 0, ',', ' '),
                    'max' => number_format($game['max_stake_minor'] / 100, 0, ',', ' '),
                ]);
            }

            // Maryaj : boules rangées dans l'ordre (l'ordre ne compte pas), pour comparer les engagements.
            if ($game['count'] === 2) {
                sort($numbers, SORT_STRING);
            }

            $lines[] = [
                'game' => (string) $play['game'],
                'option' => $option,
                'numbers' => $numbers,
                'stake_minor' => $stake,
                'currency' => isset($play['stake']['currency']) ? strtoupper((string) $play['stake']['currency']) : null,
                'multipliers' => $game['payouts'],
                'max_payout_minor' => LotteryRules::payout($stake, LotteryRules::maxMultiplier($game['payouts'])),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /** @param  list<array{game: string, option: int|null, numbers: list<string>, stake_minor: int, currency: string|null, multipliers: array<int, string>, max_payout_minor: int}>  $lines */
    private function accept(User $user, string $drawKey, ?string $date, array $lines, ?string $placementKey): LotteryTicket
    {
        $wallet = $this->ledger->walletFor($user);
        foreach ($lines as $line) {
            if ($line['currency'] !== null && $line['currency'] !== $wallet->currency) {
                throw new ApiException('currency_mismatch', __('La mise doit être en :currency.', ['currency' => $wallet->currency]), 422);
            }
        }

        $total = array_sum(array_column($lines, 'stake_minor'));

        return DB::transaction(function () use ($user, $wallet, $drawKey, $date, $lines, $total, $placementKey) {
            $this->responsibleGaming->assertCanBet($user, $total);

            // Verrou du tirage : les engagements d'un même tirage se calculent une vente après l'autre.
            $draw = LotteryDraw::where('external_key', $drawKey)->where('is_active', true)->lockForUpdate()->first();
            if ($draw === null) {
                throw new ApiException('lottery_draw_unknown', __('Ce tirage n\'existe pas ou n\'est plus proposé.'), 422);
            }

            $drawDate = $this->openDate($draw, $date);

            $this->assertNotBlocked($draw, $drawDate, $lines);
            $this->assertWithinExposure($draw, $drawDate, $lines);

            $maxPayout = min(array_sum(array_column($lines, 'max_payout_minor')), $this->rules->maxPayoutPerTicketMinor());

            $ticket = LotteryTicket::create([
                'placement_key' => $placementKey,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'lottery_draw_id' => $draw->id,
                'draw_date' => $drawDate,
                'draw_at' => $draw->drawAt($drawDate),
                'status' => LotteryTicketStatus::Pending,
                'stake_minor' => $total,
                'max_payout_minor' => $maxPayout,
                'currency' => $wallet->currency,
                'rules_version' => (string) config('lottery.sales.rules_version'),
                'rules_snapshot' => $this->rules->snapshot(),
            ]);

            foreach ($lines as $position => $line) {
                LotteryPlay::create([
                    'lottery_ticket_id' => $ticket->id,
                    'position' => $position,
                    'game' => $line['game'],
                    'option' => $line['option'],
                    'number1' => $line['numbers'][0],
                    'number2' => $line['numbers'][1] ?? null,
                    'stake_minor' => $line['stake_minor'],
                    'multipliers' => $line['multipliers'],
                    'max_payout_minor' => $line['max_payout_minor'],
                    'status' => LotteryTicketStatus::Pending,
                ]);
            }

            // Solde insuffisant ou portefeuille gelé : l'exception annule toute la transaction.
            $this->ledger->reserveStake($wallet, Money::ofMinor($total, $wallet->currency), "lottery:{$ticket->id}:stake", (string) $ticket->id, self::LEDGER_TYPE);

            return $ticket->load('draw', 'plays');
        });
    }

    /** Date locale visée : la prochaine encore ouverte ; une autre date demandée → 409 lottery_draw_closed. */
    private function openDate(LotteryDraw $draw, ?string $date): string
    {
        $next = $this->rules->nextOpenDate($draw);

        if ($next === null || ($date !== null && $date !== $next)) {
            throw new ApiException('lottery_draw_closed', __('La vente est fermée pour ce tirage.'), 409, [
                'next_date' => $next,
                'closes_at' => $next === null ? null : $this->rules->closesAt($draw, $next)->toIso8601ZuluString(),
            ]);
        }

        return $next;
    }

    /** @param  list<array{game: string, numbers: list<string>}>  $lines */
    private function assertNotBlocked(LotteryDraw $draw, string $date, array $lines): void
    {
        $blocked = LotteryBlockedNumber::whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('lottery_draw_id')->orWhere('lottery_draw_id', $draw->id))
            ->where(fn ($q) => $q->whereNull('draw_date')->orWhere('draw_date', $date))
            ->pluck('number')
            ->all();

        foreach ($lines as $i => $line) {
            $hit = array_values(array_intersect(LotteryRules::blockableNumbers($line['game'], $line['numbers']), $blocked));
            if ($hit !== []) {
                throw new ApiException('lottery_number_blocked', __('La boule :number n\'est pas en vente pour ce tirage.', ['number' => $hit[0]]), 422, [
                    'play' => $i,
                    'numbers' => $hit,
                ]);
            }
        }
    }

    /**
     * Gain possible cumulé par combinaison (jeu, option, numéros), pour ce tirage et cette date, plafonné (L-2).
     *
     * @param  list<array{game: string, option: int|null, numbers: list<string>, max_payout_minor: int, multipliers: array<int, string>}>  $lines
     */
    private function assertWithinExposure(LotteryDraw $draw, string $date, array $lines): void
    {
        $limit = $this->rules->maxExposurePerNumberMinor();
        if ($limit === null) {
            return;
        }

        $inTicket = [];
        foreach ($lines as $i => $line) {
            $key = $line['game'].'|'.$line['option'].'|'.implode('-', $line['numbers']);
            $inTicket[$key] ??= $this->exposure($draw, $date, $line['game'], $line['option'], $line['numbers']);
            $used = $inTicket[$key];

            if ($used + $line['max_payout_minor'] > $limit) {
                $multiplier = LotteryRules::maxMultiplier($line['multipliers']);
                $remaining = bccomp($multiplier, '0', 6) > 0 ? (int) bcdiv((string) max(0, $limit - $used), $multiplier, 0) : 0;

                throw new ApiException('lottery_number_limit', __('Mise trop forte sur :numbers pour ce tirage.', ['numbers' => implode(' - ', $line['numbers'])]), 422, [
                    'play' => $i,
                    'numbers' => $line['numbers'],
                    'remaining_stake' => ['minor' => $remaining, 'currency' => (string) config('app.currency', 'HTG')],
                ]);
            }

            $inTicket[$key] = $used + $line['max_payout_minor'];
        }
    }

    /** @param  list<string>  $numbers */
    private function exposure(LotteryDraw $draw, string $date, string $game, ?int $option, array $numbers): int
    {
        return (int) LotteryPlay::query()
            ->join('lottery_tickets', 'lottery_tickets.id', '=', 'lottery_plays.lottery_ticket_id')
            ->where('lottery_tickets.lottery_draw_id', $draw->id)
            ->where('lottery_tickets.draw_date', $date)
            ->where('lottery_tickets.status', LotteryTicketStatus::Pending->value)
            ->where('lottery_plays.game', $game)
            ->where('lottery_plays.option', $option)
            ->where('lottery_plays.number1', $numbers[0])
            ->where('lottery_plays.number2', $numbers[1] ?? null)
            ->sum('lottery_plays.max_payout_minor');
    }
}
