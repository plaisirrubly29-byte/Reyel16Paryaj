<?php

namespace App\Modules\SportsData\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Market;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Suspension et réouverture d'un marché par un administrateur (P6-02), motif obligatoire et audité.
 *
 * Un marché suspendu n'est plus affiché au catalogue ni pariable. Le motif est stocké sans le préfixe
 * « auto: » : la synchronisation ne lève donc jamais une suspension posée par un administrateur
 * (CatalogueSyncService::AUTO_SUSPENSION_PREFIX).
 */
class MarketAvailabilityService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(User $actor, Market $market, string $reason): Market
    {
        return $this->change($market, function (Market $locked) use ($actor, $reason) {
            // Déjà suspendu par un administrateur : rien à faire, rien à journaliser.
            if ($locked->status === MarketStatus::Suspended && ! $this->isAutomatic($locked)) {
                return $locked;
            }

            // Une suspension automatique (cotes périmées) devient une suspension administrateur.
            $this->assertNotFinal($locked);

            return $this->apply($actor, $locked, MarketStatus::Suspended, $reason, AuditAction::MarketSuspended);
        });
    }

    public function reopen(User $actor, Market $market, string $reason): Market
    {
        return $this->change($market, function (Market $locked) use ($actor, $reason) {
            if ($locked->status === MarketStatus::Open) {
                return $locked;
            }

            $this->assertNotFinal($locked);

            // Rouvert ne veut pas dire pariable : la règle de fraîcheur des cotes s'applique toujours.
            return $this->apply($actor, $locked, MarketStatus::Open, $reason, AuditAction::MarketReopened);
        });
    }

    public function isAutomatic(Market $market): bool
    {
        return $market->status === MarketStatus::Suspended
            && str_starts_with((string) $market->suspension_reason, CatalogueSyncService::AUTO_SUSPENSION_PREFIX);
    }

    /**
     * Marché verrouillé pendant le changement : un pari en cours (qui verrouille aussi le marché)
     * voit l'état d'avant ou d'après, jamais un état intermédiaire.
     *
     * @param  callable(Market): Market  $change
     */
    private function change(Market $market, callable $change): Market
    {
        return DB::transaction(fn () => $change(Market::whereKey($market->id)->lockForUpdate()->firstOrFail()));
    }

    private function assertNotFinal(Market $market): void
    {
        if (in_array($market->status, [MarketStatus::Closed, MarketStatus::Settled], true)) {
            throw new ApiException('invalid_status_transition', __('Un marché fermé ou réglé ne peut plus être suspendu ni rouvert.'), 409);
        }
    }

    private function apply(User $actor, Market $market, MarketStatus $to, string $reason, AuditAction $action): Market
    {
        $before = ['status' => $market->status->value, 'suspension_reason' => $market->suspension_reason];

        $market->forceFill([
            'status' => $to,
            'suspension_reason' => $to === MarketStatus::Suspended ? $reason : null,
        ])->save();

        $this->audit->record($action, $market, $before, [
            'status' => $to->value,
            'suspension_reason' => $market->suspension_reason,
        ], $reason, $actor);

        return $market;
    }
}
