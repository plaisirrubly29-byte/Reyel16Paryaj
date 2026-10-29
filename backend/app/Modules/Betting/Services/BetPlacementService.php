<?php

namespace App\Modules\Betting\Services;

use App\Models\User;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\Compliance\Exceptions\ResponsibleGamingException;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Selection;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acceptation d'un pari (P4-03, architecture §4.2). Le serveur décide de tout :
 *
 * - marchés et cotes rechargés (et verrouillés) au moment du pari : la cote du client ne sert qu'à
 *   détecter un changement, jamais de base de calcul ;
 * - disponibilité (match pas commencé ou en direct, marché ouvert, cote assez récente), limites de mise,
 *   type de pari et une seule sélection par match ;
 * - politique de changement de cote (config betting.odds_change_policy, décision D-05 provisoire) :
 *   409 odds_changed avec les nouvelles cotes, rien n'est créé ;
 * - ticket, sélections figées et réservation de la mise dans UNE transaction : solde insuffisant → rien n'est créé.
 *
 * Doublon : le middleware "idempotent" rejoue la réponse d'une requête déjà traitée. Il libère pourtant sa clé
 * si la requête échoue après le commit ou si le processus meurt (revue P8-02, M4) : la clé est donc aussi
 * figée sur le ticket (placement_key, unique par joueur) et un rejeu renvoie le ticket existant, jamais un
 * second ticket ni une seconde mise.
 */
class BetPlacementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ResponsibleGamingService $responsibleGaming,
    ) {}

    /**
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @param  ?string  $placementKey  clé d'idempotence de la requête (null hors API)
     *
     * @throws ApiException stake_out_of_range, multiple_not_allowed, selection_unavailable,
     *                      same_event_selections, odds_changed, insufficient_funds, wallet_frozen,
     *                      idempotency_key_reused, betting_paused, stake_limit_exceeded
     */
    public function place(User $user, array $picks, int $stakeMinor, ?string $currency = null, ?string $placementKey = null): BetTicket
    {
        // Pari déjà accepté avec cette clé (rejeu après une panne) : le même ticket, rien de plus.
        if ($placementKey !== null && ($existing = $this->replayed($user, $placementKey, $picks, $stakeMinor)) !== null) {
            return $existing;
        }

        try {
            return $this->accept($user, $picks, $stakeMinor, $currency, $placementKey);
        } catch (ResponsibleGamingException $e) {
            // Journalisé ici, après l'annulation de la transaction du pari (P7-02).
            $this->responsibleGaming->recordRefusal($user, $e, $stakeMinor);

            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            // Deux requêtes simultanées avec la même clé : la contrainte unique a arrêté la seconde.
            if ($placementKey !== null && ($existing = $this->replayed($user, $placementKey, $picks, $stakeMinor)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Ticket déjà créé par ce joueur avec cette clé ; 409 si la clé a servi à un autre pari.
     *
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     */
    private function replayed(User $user, string $placementKey, array $picks, int $stakeMinor): ?BetTicket
    {
        $ticket = BetTicket::where('user_id', $user->id)->where('placement_key', $placementKey)->first();
        if ($ticket === null) {
            return null;
        }

        $requested = array_map(fn ($p) => (int) $p['selection_id'], $picks);
        $selections = $ticket->selections()->get();
        $same = $ticket->stake_minor === $stakeMinor
            && $selections->count() === count($requested)
            // Une sélection retirée du catalogue depuis (selection_id nul) ne peut plus être comparée.
            && $selections->every(fn (BetSelection $s) => $s->selection_id === null || in_array($s->selection_id, $requested, true));

        if (! $same) {
            throw new ApiException('idempotency_key_reused', __('Cette clé d\'idempotence a déjà servi pour une autre requête.'), 409);
        }

        return $ticket->load('selections.event.competition.sport');
    }

    /**
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     */
    private function accept(User $user, array $picks, int $stakeMinor, ?string $currency, ?string $placementKey): BetTicket
    {
        $wallet = $this->ledger->walletFor($user);

        if ($currency !== null && strtoupper($currency) !== $wallet->currency) {
            throw new ApiException('currency_mismatch', __('La mise doit être en :currency.', ['currency' => $wallet->currency]), 422);
        }

        $min = (int) config('betting.min_stake_minor');
        $max = (int) config('betting.max_stake_minor');
        if ($stakeMinor < $min || $stakeMinor > $max) {
            throw new ApiException('stake_out_of_range', __('Mise hors limites.'), 422, [
                'min' => ['minor' => $min, 'currency' => $wallet->currency],
                'max' => ['minor' => $max, 'currency' => $wallet->currency],
            ]);
        }

        if (count($picks) > 1 && ! config('betting.allow_multiple')) {
            throw new ApiException('multiple_not_allowed', __('Les paris combinés ne sont pas proposés.'), 422);
        }

        return DB::transaction(function () use ($user, $wallet, $picks, $stakeMinor, $placementKey) {
            // Jeu responsable (pause, limites de mise) : verrou sur le joueur, ses paris simultanés passent un par un.
            $this->responsibleGaming->assertCanBet($user, $stakeMinor);

            $selections = $this->lockSelections($picks);
            $this->assertAvailable($picks, $selections);
            $this->assertOddsUnchanged($picks, $selections);

            // Cotes du serveur uniquement. Produit gardé à 6 décimales pour le gain, arrondi à 3 pour l'affichage.
            $product = $selections->reduce(fn (string $carry, Selection $s) => bcmul($carry, (string) $s->odds, 6), '1');
            $payout = min((int) bcmul((string) $stakeMinor, $product, 0), (int) config('betting.max_payout_minor'));

            $ticket = BetTicket::create([
                'placement_key' => $placementKey,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => $selections->count() > 1 ? BetType::Multiple : BetType::Single,
                'stake_minor' => $stakeMinor,
                'currency' => $wallet->currency,
                'total_odds' => bcadd($product, '0', 3),
                'potential_payout_minor' => $payout,
                'rules_version' => (string) config('betting.rules_version'),
                'rules_snapshot' => $this->rulesSnapshot(),
            ]);

            foreach ($selections as $selection) {
                $market = $selection->market;
                $event = $market->event;

                BetSelection::create([
                    'bet_ticket_id' => $ticket->id,
                    'event_id' => $event->id,
                    'market_id' => $market->id,
                    'selection_id' => $selection->id,
                    'event_name' => "{$event->homeTeam->name} – {$event->awayTeam->name}",
                    'event_starts_at' => $event->starts_at,
                    'market_key' => $market->key,
                    'market_line' => $market->line,
                    'selection_key' => $selection->key,
                    'selection_name' => $selection->name,
                    'odds' => $selection->odds,
                    'odds_version' => $market->odds_version,
                ]);
            }

            // Échec (solde insuffisant, portefeuille gelé) : l'exception annule toute la transaction.
            $this->ledger->reserveStake($wallet, Money::ofMinor($stakeMinor, $wallet->currency), "bet:{$ticket->id}:stake", (string) $ticket->id);

            return $ticket->load('selections.event.competition.sport');
        });
    }

    /**
     * Sélections demandées, dans l'ordre du coupon, avec marché et match ; marchés verrouillés jusqu'à la fin
     * de la transaction (une synchro ne peut pas changer la cote entre la vérification et le ticket).
     *
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @return Collection<int, Selection>
     */
    private function lockSelections(array $picks): Collection
    {
        $ids = array_map(fn ($p) => (int) $p['selection_id'], $picks);
        $selections = Selection::whereKey($ids)->get()->keyBy('id');

        $marketIds = $selections->pluck('market_id')->unique()->sort()->values()->all();
        $markets = Market::whereKey($marketIds)->orderBy('id')->lockForUpdate()->with('event.homeTeam', 'event.awayTeam')->get()->keyBy('id');

        // Relues après le verrou : la cote est celle en vigueur.
        $fresh = Selection::whereKey($ids)->get()->keyBy('id');

        return collect($ids)
            ->filter(fn (int $id) => $fresh->has($id))
            ->map(fn (int $id) => $fresh[$id]->setRelation('market', $markets[$fresh[$id]->market_id]))
            ->values();
    }

    /**
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @param  Collection<int, Selection>  $selections
     */
    private function assertAvailable(array $picks, Collection $selections): void
    {
        $found = $selections->keyBy('id');
        $unavailable = [];

        foreach ($picks as $pick) {
            $selection = $found->get((int) $pick['selection_id']);
            if ($selection === null || ! $this->isOpen($selection->market)) {
                $unavailable[] = (string) $pick['selection_id'];
            }
        }

        if ($unavailable !== []) {
            throw new ApiException('selection_unavailable', __('Une ou plusieurs sélections ne sont plus proposées.'), 422, [
                'selection_ids' => $unavailable,
            ]);
        }

        $events = $selections->map(fn (Selection $s) => $s->market->event_id);
        if ($events->count() !== $events->unique()->count()) {
            throw new ApiException('same_event_selections', __('Un combiné ne peut contenir qu\'une sélection par match.'), 422);
        }
    }

    /**
     * Match pas encore commencé, marché ouvert et cote assez récente. Un match en direct n'est pariable que si
     * betting.allow_live est activé (vérifié par Market::isBettable, comme le "bettable" du catalogue).
     */
    private function isOpen(Market $market): bool
    {
        $event = $market->event;
        $eventOpen = ($event->status === EventStatus::Scheduled && $event->starts_at->isFuture())
            || $event->status === EventStatus::Live;

        return $eventOpen && $market->isBettable();
    }

    /**
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @param  Collection<int, Selection>  $selections
     */
    private function assertOddsUnchanged(array $picks, Collection $selections): void
    {
        $found = $selections->keyBy('id');
        $changed = [];

        foreach ($picks as $pick) {
            $selection = $found[(int) $pick['selection_id']];
            if (bccomp($pick['odds'], (string) $selection->odds, 3) !== 0) {
                $changed[] = [
                    'selection_id' => (string) $selection->id,
                    'odds' => MarketResource::odds((string) $selection->odds), // même format que le catalogue

                    'odds_version' => $selection->market->odds_version,
                ];
            }
        }

        if ($changed !== []) {
            throw new ApiException('odds_changed', __('La cote a changé : vérifiez la nouvelle cote et confirmez votre pari.'), 409, [
                'selections' => $changed,
            ]);
        }
    }

    /** @return array<string, mixed> règles appliquées, figées sur le ticket */
    private function rulesSnapshot(): array
    {
        return [
            'min_stake_minor' => (int) config('betting.min_stake_minor'),
            'max_stake_minor' => (int) config('betting.max_stake_minor'),
            'max_payout_minor' => (int) config('betting.max_payout_minor'),
            'allow_multiple' => (bool) config('betting.allow_multiple'),
            'max_selections' => (int) config('betting.max_selections'),
            'allow_live' => (bool) config('betting.allow_live'),
            'odds_change_policy' => (string) config('betting.odds_change_policy'),
        ];
    }
}
