<?php

namespace App\Modules\Lottery\Services;

use App\Modules\Lottery\Enums\LotteryTicketStatus;
use App\Modules\Lottery\Models\LotteryPlay;
use App\Modules\Lottery\Models\LotteryResult;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Règlement des fiches de borlette (V2-D09) : à la publication du résultat, chaque ligne est jugée au barème figé à la
 * vente, les gains sont crédités par le ledger. Idempotent : une fiche réglée n'est plus touchée, et chaque écriture a
 * une référence unique (lottery:{id}:payout, lottery:{id}:refund).
 *
 * Tirage sans résultat après lottery.sales.void_after_hours : fiche annulée, mise remboursée (L-5).
 */
class LotterySettlementService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Règle toutes les fiches en attente qui ont un résultat, et annule celles restées sans résultat trop longtemps. */
    public function settleDue(?CarbonImmutable $now = null): LotterySettlementReport
    {
        $now ??= CarbonImmutable::now();
        $report = new LotterySettlementReport;

        LotteryTicket::where('status', LotteryTicketStatus::Pending->value)
            ->where('draw_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use ($now, $report) {
                foreach ($tickets as $ticket) {
                    $this->settle($ticket, $now, $report);
                }
            });

        return $report;
    }

    public function settle(LotteryTicket $ticket, ?CarbonImmutable $now = null, ?LotterySettlementReport $report = null): LotteryTicket
    {
        $now ??= CarbonImmutable::now();
        $report ??= new LotterySettlementReport;

        return DB::transaction(function () use ($ticket, $now, $report) {
            $ticket = LotteryTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== LotteryTicketStatus::Pending) {
                return $ticket; // déjà réglée
            }

            $result = $ticket->result();
            if ($result === null) {
                $voidAfter = (int) ($ticket->rules_snapshot['void_after_hours'] ?? config('lottery.sales.void_after_hours', 48));
                if ($ticket->draw_at->copy()->addHours($voidAfter)->greaterThan($now)) {
                    return $ticket; // résultat attendu
                }

                return $this->voidTicket($ticket, $report);
            }

            return $this->judge($ticket, $result, $report);
        });
    }

    private function judge(LotteryTicket $ticket, LotteryResult $result, LotterySettlementReport $report): LotteryTicket
    {
        $won = 0;
        $refunded = 0;
        $anyWon = false;
        $allVoid = true;

        foreach ($ticket->plays()->get() as $play) {
            [$status, $lot, $multiplier] = self::outcome($play, $result);
            $payout = match ($status) {
                LotteryTicketStatus::Won => LotteryRules::payout($play->stake_minor, (string) $multiplier),
                LotteryTicketStatus::Void => $play->stake_minor,
                default => 0,
            };

            $play->update(['status' => $status, 'winning_lot' => $lot, 'payout_minor' => $payout]);

            if ($status === LotteryTicketStatus::Won) {
                $won += $payout;
                $anyWon = true;
            } elseif ($status === LotteryTicketStatus::Void) {
                $refunded += $payout;
            }
            $allVoid = $allVoid && $status === LotteryTicketStatus::Void;
        }

        // Gain plafonné par fiche (R-5), plafond figé à la vente.
        $won = min($won, (int) ($ticket->rules_snapshot['max_payout_per_ticket_minor'] ?? PHP_INT_MAX));

        $wallet = $ticket->wallet;
        if ($won > 0) {
            $this->ledger->payout($wallet, Money::ofMinor($won, $ticket->currency), "lottery:{$ticket->id}:payout", (string) $ticket->id, LotterySalesService::LEDGER_TYPE);
        }
        if ($refunded > 0) {
            $this->ledger->refund($wallet, Money::ofMinor($refunded, $ticket->currency), "lottery:{$ticket->id}:refund", (string) $ticket->id, LotterySalesService::LEDGER_TYPE);
        }

        $status = match (true) {
            $anyWon => LotteryTicketStatus::Won,
            $allVoid => LotteryTicketStatus::Void,
            default => LotteryTicketStatus::Lost,
        };
        $ticket->update(['status' => $status, 'payout_minor' => $won + $refunded, 'settled_at' => now()]);
        $report->count($status);

        Log::info('lottery.ticket_settled', ['ticket' => $ticket->reference, 'status' => $status->value, 'payout_minor' => $won + $refunded]);

        return $ticket;
    }

    private function voidTicket(LotteryTicket $ticket, LotterySettlementReport $report): LotteryTicket
    {
        foreach ($ticket->plays()->get() as $play) {
            $play->update(['status' => LotteryTicketStatus::Void, 'payout_minor' => $play->stake_minor]);
        }

        $this->ledger->refund($ticket->wallet, Money::ofMinor($ticket->stake_minor, $ticket->currency), "lottery:{$ticket->id}:refund", (string) $ticket->id, LotterySalesService::LEDGER_TYPE);
        $ticket->update(['status' => LotteryTicketStatus::Void, 'payout_minor' => $ticket->stake_minor, 'settled_at' => now()]);
        $report->count(LotteryTicketStatus::Void);

        Log::warning('lottery.ticket_voided_no_result', ['ticket' => $ticket->reference]);

        return $ticket;
    }

    /**
     * Sort d'une ligne au vu du résultat (règles de config/lottery.php) : statut, lot gagnant (bolèt) et multiplicateur.
     *
     * @return array{LotteryTicketStatus, int|null, string|null}
     */
    public static function outcome(LotteryPlay $play, LotteryResult $result): array
    {
        $lots = [1 => $result->lot1, 2 => $result->lot2, 3 => $result->lot3];
        $multipliers = $play->multipliers;
        $single = (string) ($multipliers[0] ?? $multipliers['0'] ?? '0');
        $lost = [LotteryTicketStatus::Lost, null, null];

        switch ($play->game) {
            case 'bolet':
                // Un seul gain par ligne, au meilleur lot sorti (R-3).
                foreach ($lots as $lot => $number) {
                    if ($number === $play->number1) {
                        return [LotteryTicketStatus::Won, $lot, (string) ($multipliers[$lot] ?? $multipliers[(string) $lot] ?? '0')];
                    }
                }

                return $lost;

            case 'maryaj':
                // Les deux boules parmi les trois lots, à des lots différents, dans n'importe quel ordre (R-1).
                $first = array_search($play->number1, $lots, true);
                if ($first === false) {
                    return $lost;
                }
                $rest = $lots;
                unset($rest[$first]);

                return in_array($play->number2, $rest, true) ? [LotteryTicketStatus::Won, null, $single] : $lost;

            case 'loto3':
                if ($result->pick3 === null) {
                    return [LotteryTicketStatus::Void, null, null];
                }

                return $play->number1 === $result->pick3 ? [LotteryTicketStatus::Won, null, $single] : $lost;

            case 'loto4':
                $winning = match ($play->option) {
                    1 => $lots[1].$lots[2],
                    2 => $lots[2].$lots[3],
                    3 => $lots[1].$lots[3],
                    default => null,
                };

                return $winning !== null && $play->number1 === $winning ? [LotteryTicketStatus::Won, null, $single] : $lost;

            case 'loto5':
                if ($result->pick3 === null) {
                    return [LotteryTicketStatus::Void, null, null];
                }
                $winning = match ($play->option) {
                    1 => $result->pick3.$lots[2],
                    2 => $result->pick3.$lots[3],
                    default => null,
                };

                return $winning !== null && $play->number1 === $winning ? [LotteryTicketStatus::Won, null, $single] : $lost;
        }

        return [LotteryTicketStatus::Void, null, null];
    }
}
