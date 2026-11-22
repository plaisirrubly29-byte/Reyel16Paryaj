<?php

namespace App\Modules\Betting\Services;

use App\Models\User;
use App\Modules\Betting\Enums\BetTicketStatus;
use App\Modules\Betting\Enums\BetType;
use App\Modules\Betting\Jobs\ProcessLiveTicket;
use App\Modules\Betting\Models\BetSelection;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Catalogue\Http\Resources\MarketResource;
use App\Modules\Compliance\Exceptions\ResponsibleGamingException;
use App\Modules\Compliance\Services\ResponsibleGamingService;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\Selection;
use App\Modules\SportsData\Services\HouseMarkets;
use App\Modules\SportsData\Services\LiveOddsService;
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
 * - politique de changement de cote (config betting.odds_change_policy, décision D-05) :
 *   409 odds_changed avec les nouvelles cotes, rien n'est créé ;
 * - plafonds d'engagement par match et par marché (V2-D05, ExposureGuard) et mise maximale propre au joueur ;
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
        private readonly ExposureGuard $exposure,
    ) {}

    /**
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @param  ?string  $placementKey  clé d'idempotence de la requête (null hors API)
     *
     * @throws ApiException stake_out_of_range, multiple_not_allowed, selection_unavailable,
     *                      same_event_selections, odds_changed, insufficient_funds, wallet_frozen,
     *                      idempotency_key_reused, betting_paused, stake_limit_exceeded, risk_limit_exceeded
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
        // Mise maximale posée par un administrateur pour ce joueur (V2-D05), jamais au-dessus de la limite générale.
        $max = min((int) config('betting.max_stake_minor'), $user->max_stake_minor ?? PHP_INT_MAX);
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
            $live = $selections->contains(fn (Selection $s) => $s->market->event->status === EventStatus::Live);
            if ($live) {
                $this->assertLiveRules($selections, $stakeMinor, $wallet->currency);
            }
            $odds = $this->resolveOdds($picks, $selections);
            $this->assertHouseMarketStake($selections, $stakeMinor, $wallet->currency);

            // Cotes du serveur uniquement. Produit gardé à 6 décimales pour le gain, arrondi à 3 pour l'affichage.
            $product = $selections->reduce(fn (string $carry, Selection $s) => bcmul($carry, $odds[$s->id], 6), '1');
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
                // En direct : en attente d'acceptation, revérifié à la fin du délai (D-20, LiveAcceptanceService).
                'status' => $live ? BetTicketStatus::Awaiting : BetTicketStatus::Pending,
                'live' => $live,
                'acceptance_due_at' => $live ? now()->addSeconds(LiveAcceptanceService::delaySeconds()) : null,
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
                    'odds' => $odds[$selection->id],
                    'odds_version' => $market->odds_version,
                    // Score au moment du pari en direct : un score différent à la fin du délai refuse le pari.
                    'live_score' => $event->status === EventStatus::Live ? "{$event->home_score}-{$event->away_score}" : null,
                ]);
            }

            // Plafonds d'engagement, ticket inséré : il compte dans le calcul ; un refus annule la transaction.
            $this->exposure->assertWithinLimits($ticket);

            // Échec (solde insuffisant, portefeuille gelé) : l'exception annule toute la transaction.
            $this->ledger->reserveStake($wallet, Money::ofMinor($stakeMinor, $wallet->currency), "bet:{$ticket->id}:stake", (string) $ticket->id);

            if ($live) {
                ProcessLiveTicket::dispatch($ticket->id)->delay($ticket->acceptance_due_at)->afterCommit();
            }

            return $ticket->load('selections.event.competition.sport');
        });
    }

    /**
     * Règles propres au direct (D-20) : paris simples seulement (sauf betting.live_allow_multiple), mise maximale du
     * direct, jamais au-dessus de la mise maximale générale ni de celle du joueur.
     *
     * @param  Collection<int, Selection>  $selections
     */
    private function assertLiveRules(Collection $selections, int $stakeMinor, string $currency): void
    {
        if ($selections->count() > 1 && ! config('betting.live_allow_multiple')) {
            throw new ApiException('live_multiple_not_allowed', __('Les paris en direct se jouent en simple : retirez les autres sélections.'), 422);
        }

        $max = (int) config('betting.live_max_stake_minor');
        if ($stakeMinor <= $max) {
            return;
        }

        throw new ApiException('stake_out_of_range', __('Mise maximale en direct : :max.', [
            'max' => number_format(intdiv($max, 100), 0, ',', ' ')." {$currency}",
        ]), 422, [
            'min' => ['minor' => (int) config('betting.min_stake_minor'), 'currency' => $currency],
            'max' => ['minor' => $max, 'currency' => $currency],
        ]);
    }

    /**
     * Sélections demandées, dans l'ordre du coupon, avec marché et match ; marchés verrouillés jusqu'à la fin
     * de la transaction (une synchro ne peut pas changer la cote entre la vérification et le ticket).
     *
     * Verrou PARTAGÉ (FOR SHARE) : la synchro et la suspension, qui modifient la ligne du marché, attendent la fin
     * des paris en cours (et inversement), mais les paris sur un même marché ne s'attendent plus entre eux.
     * Avec un verrou exclusif, tous les paris d'un match populaire passaient un par un (test de charge P8-03).
     *
     * @param  list<array{selection_id: int|string, odds: string}>  $picks
     * @return Collection<int, Selection>
     */
    private function lockSelections(array $picks): Collection
    {
        $ids = array_map(fn ($p) => (int) $p['selection_id'], $picks);
        $selections = Selection::whereKey($ids)->get()->keyBy('id');

        $marketIds = $selections->pluck('market_id')->unique()->sort()->values()->all();
        $markets = Market::whereKey($marketIds)->orderBy('id')->sharedLock()->with('event.homeTeam', 'event.awayTeam')->get()->keyBy('id');

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
            if ($selection === null || ! $this->isOpen($selection->market) || ! $this->isQuotedLive($selection)) {
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
     * En direct, la sélection elle-même doit être cotée : un score exact déjà dépassé (« 1-0 » à 2-1) ou trop improbable
     * n'a pas de cote, même si son marché est ouvert. Avant le match : toujours vrai.
     */
    private function isQuotedLive(Selection $selection): bool
    {
        $market = $selection->market;

        return $market->event->status !== EventStatus::Live
            || isset(app(LiveOddsService::class)->quote($market)->odds[$selection->key]);
    }

    /**
     * Cote de chaque sélection du ticket. Refuse le pari si la cote vue a changé, ou si la version du marché vue
     * (odds_version) n'est plus la bonne : un changement de ligne (totals/spreads 2.5 → 3.5) à cote égale change le
     * pari sans changer la cote.
     *
     * En direct (D-20), la cote vient du modèle et bouge à chaque seconde : la cote vue reste acceptée si celle du moment
     * n'est pas plus basse de plus de betting.live_odds_tolerance, et le ticket prend la plus basse des deux. La version
     * n'est comparée que pour le plus/moins (sa ligne peut changer) : celle du 1X2 change à chaque cote du fournisseur.
     *
     * @param  list<array{selection_id: int|string, odds: string, odds_version?: int|string}>  $picks
     * @param  Collection<int, Selection>  $selections
     * @return array<int, string> identifiant de la sélection => cote du ticket
     */
    private function resolveOdds(array $picks, Collection $selections): array
    {
        $found = $selections->keyBy('id');
        $changed = [];
        $odds = [];
        $tolerance = (float) config('betting.live_odds_tolerance', 0.03);

        foreach ($picks as $pick) {
            $selection = $found[(int) $pick['selection_id']];
            $market = $selection->market;
            $staleVersion = isset($pick['odds_version']) && (int) $pick['odds_version'] !== (int) $market->odds_version;

            if ($market->event->status === EventStatus::Live) {
                $current = app(LiveOddsService::class)->quote($market)->odds[$selection->key] ?? null;
                if ($current !== null && ! ($staleVersion && $market->key !== 'h2h')
                    && (float) $current >= (float) $pick['odds'] * (1 - $tolerance)) {
                    $odds[$selection->id] = bccomp($current, $pick['odds'], 3) < 0 ? $current : bcadd($pick['odds'], '0', 3);

                    continue;
                }
                $changed[] = $this->changedOdds($selection, $current ?? (string) $selection->odds);

                continue;
            }

            $odds[$selection->id] = (string) $selection->odds;
            if ($staleVersion || bccomp($pick['odds'], (string) $selection->odds, 3) !== 0) {
                $changed[] = $this->changedOdds($selection, (string) $selection->odds);
            }
        }

        if ($changed !== []) {
            throw new ApiException('odds_changed', __('La cote a changé : vérifiez la nouvelle cote et confirmez votre pari.'), 409, [
                'selections' => $changed,
            ]);
        }

        return $odds;
    }

    /** @return array<string, mixed> nouvelle cote renvoyée au coupon (409 odds_changed) */
    private function changedOdds(Selection $selection, string $odds): array
    {
        return [
            'selection_id' => (string) $selection->id,
            'odds' => MarketResource::odds(bcadd($odds, '0', 3)), // même format que le catalogue
            'odds_version' => $selection->market->odds_version,
            // Libellé avec la ligne en vigueur (ex. « > 3.5 ») : le coupon montre ce qui a changé.
            'label' => MarketResource::selectionLabel($selection->key, $selection->market->line),
        ];
    }

    /**
     * Marchés maison (V2-D10), cotés par REYEL 16 sans bookmaker de référence : mise plafonnée plus bas
     * (sports_data.house_markets.max_stake_minor) pour tout ticket qui en contient un. Même plafond pour le score exact en
     * direct : coté par notre seul modèle, sans cote de bookmaker pour le borner.
     *
     * @param  Collection<int, Selection>  $selections
     */
    private function assertHouseMarketStake(Collection $selections, int $stakeMinor, string $currency): void
    {
        $max = (int) config('sports_data.house_markets.max_stake_minor');
        $housePriced = fn (Selection $s) => HouseMarkets::isHouse($s->market->key)
            || ($s->market->key === LiveOddsService::CORRECT_SCORE && $s->market->event->status === EventStatus::Live);
        if ($stakeMinor <= $max || ! $selections->contains($housePriced)) {
            return;
        }

        $label = $selections->contains(fn (Selection $s) => HouseMarkets::isHouse($s->market->key))
            ? 'Mise maximale sur les paris spéciaux REYEL 16 : :max.' : 'Mise maximale sur le score exact en direct : :max.';
        throw new ApiException('stake_out_of_range', __($label, [
            'max' => number_format(intdiv($max, 100), 0, ',', ' ')." {$currency}",
        ]), 422, [
            'min' => ['minor' => (int) config('betting.min_stake_minor'), 'currency' => $currency],
            'max' => ['minor' => $max, 'currency' => $currency],
        ]);
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
            'live_acceptance_delay_seconds' => LiveAcceptanceService::delaySeconds(),
            'live_odds_tolerance' => (float) config('betting.live_odds_tolerance'),
            'live_max_stake_minor' => (int) config('betting.live_max_stake_minor'),
            'odds_change_policy' => (string) config('betting.odds_change_policy'),
            'postponed_void_hours' => (int) config('betting.postponed_void_hours'),
        ];
    }
}
