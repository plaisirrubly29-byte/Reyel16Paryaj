<?php

namespace App\Modules\SportsData\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Enums\OddsOverrideStatus;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Models\OddsOverride;
use App\Modules\SportsData\Models\OddsSnapshot;
use App\Modules\SportsData\Models\Selection;
use App\Support\Http\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cotes fixées à la main par l'administration (V2-D03, décision D-18) : Opérations et Super Admin (odds.override),
 * motif obligatoire, audit à chaque étape.
 *
 * - Écart de odds.override_approval_threshold_percent au plus avec la cote calculée : en vigueur tout de suite.
 *   Au-delà : en attente d'un second administrateur (jamais le demandeur, rôle de plus de 24 h, comme les résultats).
 * - En vigueur : la cote est proposée aux joueurs (selections.adjusted), nouvelle version de cote ; la synchronisation
 *   ne l'écrase pas (CatalogueSyncService). Les tickets déjà placés gardent leur version.
 * - Fin : à la demande d'un administrateur (ended), ou à la date limite et au plus tard au début du match (expired,
 *   commande odds:expire-overrides) ; la cote calculée revient, nouvelle version.
 * - Une seule demande en attente ou en vigueur par sélection (vérifié sous le verrou du marché).
 */
class OddsOverrideService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ApiException market_not_open | event_started | override_already_exists
     * @throws ValidationException sélection d'un autre marché, date limite après le début du match
     */
    public function request(User $actor, Market $market, int $selectionId, string $odds, string $reason, ?CarbonInterface $expiresAt): OddsOverride
    {
        return DB::transaction(function () use ($actor, $market, $selectionId, $odds, $reason, $expiresAt) {
            $market = $this->lockMarket($market);
            $selection = $market->selections()->whereKey($selectionId)->first()
                ?? throw ValidationException::withMessages(['selection_id' => __('Cette sélection n\'appartient pas à ce marché.')]);

            $this->assertOpen($market);
            $startsAt = $market->event->starts_at;
            $expiresAt ??= $startsAt;
            if ($expiresAt->gt($startsAt)) {
                throw ValidationException::withMessages(['expires_at' => __('La date limite ne peut pas dépasser le début du match.')]);
            }

            $existing = OddsOverride::where('selection_id', $selection->id)
                ->whereIn('status', [OddsOverrideStatus::Pending, OddsOverrideStatus::Active])->first();
            if ($existing !== null) {
                throw new ApiException('override_already_exists', __('Une cote fixée à la main est déjà en attente ou en vigueur pour cette sélection.'), 409,
                    ['override_id' => (string) $existing->id]);
            }

            $odds = bcadd($odds, '0', 3);
            $computed = bcadd((string) ($selection->computed_odds ?? $selection->odds), '0', 3);
            $threshold = (string) config('odds.override_approval_threshold_percent');
            $deviation = OddsOverride::deviation($odds, $computed);
            $requiresApproval = bccomp(ltrim($deviation, '-'), $threshold, 2) > 0;

            $override = OddsOverride::create([
                'market_id' => $market->id,
                'selection_id' => $selection->id,
                'status' => OddsOverrideStatus::Pending,
                'odds' => $odds,
                'computed_odds' => $computed,
                'requires_approval' => $requiresApproval,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::OddsOverrideRequested, $override,
                after: ['odds' => $odds, 'computed_odds' => $computed, 'deviation_percent' => $deviation,
                    'requires_approval' => $requiresApproval, 'expires_at' => $expiresAt->toIso8601String()],
                reason: $reason, actor: $actor,
                metadata: ['market_id' => (string) $market->id, 'selection_id' => (string) $selection->id]);

            if (! $requiresApproval) {
                $this->activate($override, $market, $selection);
                $this->audit->record(AuditAction::OddsOverrideApproved, $override,
                    after: ['odds' => $odds, 'odds_version' => $market->odds_version],
                    reason: __('En vigueur sans double validation : écart de :threshold % au plus avec la cote calculée.', ['threshold' => $threshold]),
                    actor: $actor, metadata: ['market_id' => (string) $market->id, 'automatic' => true]);
            }

            return $override->refresh();
        });
    }

    /**
     * @throws ApiException override_not_pending | self_approval_forbidden | approver_role_too_recent | override_expired | market_not_open
     */
    public function approve(User $approver, OddsOverride $override, string $reason): OddsOverride
    {
        return DB::transaction(function () use ($approver, $override, $reason) {
            $market = $this->lockMarket($override->market);
            $override = $this->lockStatus($override, OddsOverrideStatus::Pending, 'override_not_pending');

            if ($override->requested_by === $approver->id) {
                throw new ApiException('self_approval_forbidden', __('Une cote doit être validée par un autre administrateur.'), 403);
            }
            // M3 (D-07) : un compte qui vient de recevoir son rôle ne sert pas de second validateur.
            if ($approver->holdsPermissionOnlyRecently('odds.override', (int) config('identity.approval_role_min_age_hours'))) {
                throw new ApiException('approver_role_too_recent', __('Votre rôle est trop récent pour valider une demande.'), 403);
            }
            if ($this->isDue($override, $market)) {
                throw new ApiException('override_expired', __('Le match a commencé ou la date limite est passée : cette cote ne peut plus entrer en vigueur.'), 409);
            }
            $this->assertOpen($market);

            $override->forceFill(['decided_by' => $approver->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            $this->activate($override, $market, $override->selection);

            $this->audit->record(AuditAction::OddsOverrideApproved, $override,
                after: ['odds' => (string) $override->odds, 'odds_version' => $market->odds_version],
                reason: $reason, actor: $approver, metadata: ['market_id' => (string) $market->id]);

            return $override->refresh();
        });
    }

    /**
     * Refus par un second administrateur, ou retrait par le demandeur. Aucune cote ne change.
     *
     * @throws ApiException override_not_pending
     */
    public function reject(User $actor, OddsOverride $override, string $reason): OddsOverride
    {
        return DB::transaction(function () use ($actor, $override, $reason) {
            $override = $this->lockStatus($override, OddsOverrideStatus::Pending, 'override_not_pending');

            $override->forceFill([
                'status' => OddsOverrideStatus::Rejected,
                'decided_by' => $actor->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::OddsOverrideRejected, $override, after: ['status' => OddsOverrideStatus::Rejected->value],
                reason: $reason, actor: $actor, metadata: ['market_id' => (string) $override->market_id]);

            return $override;
        });
    }

    /**
     * Retour à la cote calculée, à la demande d'un administrateur.
     *
     * @throws ApiException override_not_active
     */
    public function end(User $actor, OddsOverride $override, string $reason): OddsOverride
    {
        return DB::transaction(function () use ($actor, $override, $reason) {
            $market = $this->lockMarket($override->market);
            $override = $this->lockStatus($override, OddsOverrideStatus::Active, 'override_not_active');

            $this->revert($override, $market, OddsOverrideStatus::Ended, $actor);
            $this->audit->record(AuditAction::OddsOverrideEnded, $override,
                after: ['odds' => (string) $override->selection->odds, 'odds_version' => $market->odds_version],
                reason: $reason, actor: $actor, metadata: ['market_id' => (string) $market->id]);

            return $override->refresh();
        });
    }

    /**
     * Fin des cotes arrivées à leur date limite, ou dont le match a commencé (commande odds:expire-overrides, chaque
     * minute) : une cote en vigueur rend la place à la cote calculée ; une demande en attente n'entre jamais en vigueur.
     *
     * @return int nombre de cotes expirées
     */
    public function expireDue(): int
    {
        $ids = OddsOverride::whereIn('status', [OddsOverrideStatus::Pending, OddsOverrideStatus::Active])
            ->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->orWhereHas('market.event', fn ($e) => $e->where('starts_at', '<=', now())->orWhere('status', '!=', EventStatus::Scheduled)))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $override = OddsOverride::with('market')->findOrFail($id);
                $market = $this->lockMarket($override->market);
                $override = OddsOverride::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $override->status->isOpen()) {
                    return;
                }

                $wasActive = $override->status === OddsOverrideStatus::Active;
                if ($wasActive) {
                    $this->revert($override, $market, OddsOverrideStatus::Expired, null);
                } else {
                    $override->forceFill(['status' => OddsOverrideStatus::Expired, 'ended_at' => now()])->save();
                }

                $this->audit->record(AuditAction::OddsOverrideExpired, $override,
                    after: ['status' => OddsOverrideStatus::Expired->value, 'was_active' => $wasActive],
                    reason: __('Date limite atteinte ou match commencé.'), actor: null,
                    metadata: ['market_id' => (string) $market->id]);
            });
        }

        return $ids->count();
    }

    /** La cote fixée devient la cote proposée : nouvelle version du marché. */
    private function activate(OddsOverride $override, Market $market, Selection $selection): void
    {
        $selection->forceFill(['odds' => (string) $override->odds, 'adjusted' => true])->save();
        $override->forceFill(['status' => OddsOverrideStatus::Active])->save();
        $this->newVersion($market);
    }

    /** La cote calculée revient : nouvelle version du marché. */
    private function revert(OddsOverride $override, Market $market, OddsOverrideStatus $status, ?User $actor): void
    {
        $selection = $override->selection;
        $selection->forceFill(['odds' => (string) ($selection->computed_odds ?? $override->computed_odds), 'adjusted' => false])->save();
        $override->forceFill(['status' => $status, 'ended_by' => $actor?->id, 'ended_at' => now()])->save();
        $this->newVersion($market);
    }

    /** Version suivante, avec l'historique de chaque sélection (comme une synchronisation qui change une cote). */
    private function newVersion(Market $market): void
    {
        $market->odds_version++;
        $market->save();

        foreach ($market->selections()->get() as $selection) {
            OddsSnapshot::create([
                'selection_id' => $selection->id,
                'odds_version' => $market->odds_version,
                'odds' => (string) $selection->odds,
                'computed_odds' => $selection->computed_odds,
                'provider_odds' => $selection->provider_odds,
                'adjusted' => $selection->adjusted,
                'line' => $market->line,
                'provider_updated_at' => $market->odds_updated_at,
            ]);
        }
    }

    private function lockMarket(Market $market): Market
    {
        return Market::with('event')->whereKey($market->id)->lockForUpdate()->firstOrFail();
    }

    private function lockStatus(OddsOverride $override, OddsOverrideStatus $expected, string $code): OddsOverride
    {
        $locked = OddsOverride::with('selection')->whereKey($override->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expected) {
            throw new ApiException($code, __('Cette cote n\'est plus dans l\'état attendu (:status).', ['status' => $locked->status->value]), 409);
        }

        return $locked;
    }

    /** Marché ouvert, match pas encore commencé. */
    private function assertOpen(Market $market): void
    {
        if ($market->event->status !== EventStatus::Scheduled || $market->event->starts_at->lte(now())) {
            throw new ApiException('event_started', __('Le match a commencé : la cote ne peut plus être fixée à la main.'), 409);
        }
        if ($market->status !== MarketStatus::Open) {
            throw new ApiException('market_not_open', __('Le marché est suspendu, fermé ou réglé.'), 409);
        }
    }

    private function isDue(OddsOverride $override, Market $market): bool
    {
        return $override->expires_at->lte(now()) || $market->event->starts_at->lte(now())
            || $market->event->status !== EventStatus::Scheduled;
    }
}
