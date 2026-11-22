<?php

namespace App\Modules\Betting\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Betting\Enums\BetSelectionStatus;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\LiveOddsService;
use App\Modules\Wallet\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fin du délai d'acceptation d'un pari en direct (décision D-20, option 3.2 C).
 *
 * Le ticket est créé « awaiting », mise réservée. À la fin du délai (tâche en file ProcessLiveTicket, ou commande
 * betting:live-acceptance en filet de sécurité, ou lecture du ticket par le joueur), tout est revérifié :
 *  - paris en direct toujours autorisés (le coupe-circuit refuse aussi les tickets en attente) ;
 *  - match toujours en cours, score identique à celui du pari et pas de changement de score depuis le pari ;
 *  - marché ouvert (score récent, pas suspendu…) et cote du moment pas plus basse que celle du ticket de plus de
 *    betting.live_odds_tolerance.
 * Tout est bon : « pending » (circuit normal du règlement). Sinon « rejected », avec le motif, et la mise rendue par une
 * écriture du ledger (jamais d'effacement). Vérification jamais faite dans live_acceptance_timeout_seconds : refusé.
 * Jamais d'acceptation par défaut.
 */
class LiveAcceptanceService
{
    public const ODDS_CHANGED = 'odds_changed';

    public const SCORE_CHANGED = 'score_changed';

    public const MARKET_SUSPENDED = 'market_suspended';

    public const LIVE_CLOSED = 'live_closed';

    public const TIMEOUT = 'timeout';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LiveOddsService $liveOdds,
    ) {}

    /** Délai d'acceptation appliqué, borné de 5 à 15 secondes (D-20). */
    public static function delaySeconds(): int
    {
        return max(5, min(15, (int) config('betting.live_acceptance_delay_seconds', 8)));
    }

    /** Tickets dont le délai est écoulé, traités un par un. @return int nombre de tickets traités */
    public function processDue(): int
    {
        $this->refreshSettings();
        $count = 0;

        BetTicket::query()
            ->where('status', BetTicketStatus::Awaiting)
            ->where('acceptance_due_at', '<=', now())
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                if ($this->process($id, refreshSettings: false) !== null) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Revérifie un ticket en attente dont le délai est écoulé.
     *
     * @return BetTicketStatus|null statut final, null si le ticket n'est pas (ou plus) à traiter
     */
    public function process(int $ticketId, bool $refreshSettings = true): ?BetTicketStatus
    {
        if ($refreshSettings) {
            $this->refreshSettings();
        }

        return DB::transaction(function () use ($ticketId) {
            /** @var BetTicket|null $ticket */
            $ticket = BetTicket::whereKey($ticketId)->lockForUpdate()->first();
            if ($ticket === null || $ticket->status !== BetTicketStatus::Awaiting
                || ($ticket->acceptance_due_at !== null && $ticket->acceptance_due_at->isFuture())) {
                return null;
            }

            $reason = $this->rejectionReason($ticket);
            if ($reason === null) {
                $ticket->update(['status' => BetTicketStatus::Pending]);
                Log::info('betting.live_ticket_accepted', ['ticket' => $ticket->reference]);

                return BetTicketStatus::Pending;
            }

            $this->reject($ticket, $reason);

            return BetTicketStatus::Rejected;
        });
    }

    private function rejectionReason(BetTicket $ticket): ?string
    {
        if (! (bool) config('betting.allow_live')) {
            return self::LIVE_CLOSED;
        }
        if ($ticket->placed_at->lt(now()->subSeconds((int) config('betting.live_acceptance_timeout_seconds', 60)))) {
            return self::TIMEOUT;
        }

        $tolerance = (float) config('betting.live_odds_tolerance', 0.03);
        $selections = $ticket->selections()->get();
        $markets = Market::whereKey($selections->pluck('market_id')->all())->orderBy('id')->sharedLock()
            ->with(['event.competition', 'selections'])->get()->keyBy('id');

        foreach ($selections as $selection) {
            /** @var BetSelection $selection */
            $market = $markets->get($selection->market_id);
            $event = $market?->event;
            if ($market === null || $event === null) {
                return self::MARKET_SUSPENDED;
            }
            if ($selection->live_score === null) {
                continue; // sélection d'un match pas encore commencé
            }
            if ($event->status !== EventStatus::Live) {
                return self::MARKET_SUSPENDED;
            }
            if ("{$event->home_score}-{$event->away_score}" !== $selection->live_score
                || ($event->score_changed_at !== null && $event->score_changed_at->gte($ticket->placed_at))) {
                return self::SCORE_CHANGED;
            }
            // La version de cote change à chaque cote reçue de The Odds API : seule une ligne différente change le pari.
            if ($market->line !== $selection->market_line) {
                return self::ODDS_CHANGED;
            }

            $quote = $this->liveOdds->quote($market);
            if (! $quote->open) {
                return $quote->reason === LiveOddsService::SCORE_CHANGED ? self::SCORE_CHANGED : self::MARKET_SUSPENDED;
            }
            $current = $quote->odds[$selection->selection_key] ?? null;
            if ($current === null || (float) $current < (float) $selection->odds * (1 - $tolerance)) {
                return self::ODDS_CHANGED;
            }
        }

        return null;
    }

    private function reject(BetTicket $ticket, string $reason): void
    {
        $entry = $this->ledger->refund($ticket->wallet, $ticket->stake(), "bet:{$ticket->id}:rejected", (string) $ticket->id);

        $ticket->selections()->where('status', BetSelectionStatus::Pending)->get()
            ->each(fn (BetSelection $s) => $s->update(['status' => BetSelectionStatus::Void, 'settled_at' => now()]));

        $ticket->update([
            'status' => BetTicketStatus::Rejected,
            'rejection_reason' => $reason,
            'payout_minor' => $ticket->stake_minor,
            'settled_at' => now(),
        ]);

        Log::info('betting.live_ticket_rejected', ['ticket' => $ticket->reference, 'reason' => $reason, 'ledger_entry' => $entry->id]);
    }

    /**
     * Un processus long (queue:work, schedule) garde les paramètres de son démarrage (PlatformSettings) : relus ici,
     * pour que le coupe-circuit coupé par un Super Admin refuse tout de suite les tickets en attente.
     */
    private function refreshSettings(): void
    {
        app(PlatformSettings::class)->apply();
    }
}
