<?php

namespace App\Modules\Lottery\Services;

use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryResult;
use Carbon\CarbonImmutable;

/**
 * Barème, limites et heures de vente de la borlette, lus dans config/lottery.php (décision D-12). Aucune règle en dur
 * ailleurs : la vente et le règlement passent par ici.
 */
class LotteryRules
{
    public const GAMES = ['bolet', 'maryaj', 'loto3', 'loto4', 'loto5'];

    /** Vente ouverte : borlette activée, interrupteur de vente allumé et au moins un jeu au barème. */
    public function salesOpen(): bool
    {
        return (bool) config('lottery.enabled') && (bool) config('lottery.sales.enabled') && $this->games() !== [];
    }

    /**
     * Jeux en vente (ceux qui ont un barème), dans l'ordre de GAMES.
     *
     * @return array<string, array{digits: int, count: int, options: list<int>, min_stake_minor: int, max_stake_minor: int, payouts: array<int, string>}>
     */
    public function games(): array
    {
        $configured = (array) config('lottery.sales.games', []);
        $games = [];
        foreach (self::GAMES as $key) {
            $game = $configured[$key] ?? null;
            if (is_array($game) && ($game['payouts'] ?? []) !== []) {
                $games[$key] = [
                    'digits' => (int) $game['digits'],
                    'count' => (int) $game['count'],
                    'options' => array_map('intval', $game['options'] ?? []),
                    'min_stake_minor' => (int) $game['min_stake_minor'],
                    'max_stake_minor' => (int) $game['max_stake_minor'],
                    'payouts' => array_map('strval', $game['payouts']),
                ];
            }
        }

        return $games;
    }

    /** @return array{digits: int, count: int, options: list<int>, min_stake_minor: int, max_stake_minor: int, payouts: array<int, string>}|null */
    public function game(string $key): ?array
    {
        return $this->games()[$key] ?? null;
    }

    /** Multiplicateur le plus élevé du barème figé (bolèt : celui du 1er lot) : sert au gain maximal d'une ligne. */
    public static function maxMultiplier(array $multipliers): string
    {
        $max = '0';
        foreach ($multipliers as $multiplier) {
            if (bccomp((string) $multiplier, $max, 6) > 0) {
                $max = (string) $multiplier;
            }
        }

        return $max;
    }

    /** Mise × multiplicateur, arrondi au centime inférieur. */
    public static function payout(int $stakeMinor, string $multiplier): int
    {
        return (int) bcmul((string) $stakeMinor, $multiplier, 0);
    }

    public function closeBeforeMinutes(): int
    {
        return max(0, (int) config('lottery.sales.close_before_minutes', 5));
    }

    public function maxPlaysPerTicket(): int
    {
        return max(1, (int) config('lottery.sales.max_plays_per_ticket', 20));
    }

    public function maxExposurePerNumberMinor(): ?int
    {
        $limit = (int) config('lottery.sales.max_exposure_per_number_minor', 0);

        return $limit > 0 ? $limit : null;
    }

    public function maxPayoutPerTicketMinor(): int
    {
        return (int) config('lottery.sales.max_payout_per_ticket_minor', PHP_INT_MAX);
    }

    /** Fin des ventes d'un tirage à une date locale (UTC). */
    public function closesAt(LotteryDraw $draw, string $date): CarbonImmutable
    {
        return $draw->drawAt($date)->subMinutes($this->closeBeforeMinutes());
    }

    /** Prochaine date locale du tirage encore ouverte à la vente (pas de résultat publié), ou null. */
    public function nextOpenDate(LotteryDraw $draw, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $day = $now->setTimezone($draw->timezone)->startOfDay();

        for ($i = 0; $i < 8; $i++) {
            $date = $day->addDays($i)->toDateString();
            if ($draw->runsOn($date) && $this->closesAt($draw, $date)->greaterThan($now)
                && ! LotteryResult::where('lottery_draw_id', $draw->id)->where('draw_date', $date)->exists()) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Boules de 2 chiffres et numéro complet contenus dans une ligne : un blocage de l'une d'elles bloque la ligne.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     */
    public static function blockableNumbers(string $game, array $numbers): array
    {
        $n = $numbers[0];

        return array_values(array_unique(match ($game) {
            'loto4' => [$n, substr($n, 0, 2), substr($n, 2, 2)],
            'loto5' => [$n, substr($n, 3, 2)],
            default => $numbers,
        }));
    }

    /** @return array<string, mixed> règles figées sur la fiche */
    public function snapshot(): array
    {
        return [
            'close_before_minutes' => $this->closeBeforeMinutes(),
            'max_plays_per_ticket' => $this->maxPlaysPerTicket(),
            'max_exposure_per_number_minor' => $this->maxExposurePerNumberMinor(),
            'max_payout_per_ticket_minor' => $this->maxPayoutPerTicketMinor(),
            'void_after_hours' => (int) config('lottery.sales.void_after_hours', 48),
            'games' => $this->games(),
        ];
    }
}
