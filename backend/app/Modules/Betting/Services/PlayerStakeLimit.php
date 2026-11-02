<?php

namespace App\Modules\Betting\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Mise maximale par ticket propre à un joueur (V2-D05), posée par un administrateur avec motif et auditée.
 * Elle ne fait que baisser la limite générale (betting.max_stake_minor) ; null la retire.
 */
class PlayerStakeLimit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ApiException not_a_player */
    public function set(User $actor, User $player, ?int $maxStakeMinor, string $reason): User
    {
        if ($player->isAdmin()) {
            throw new ApiException('not_a_player', __('Cette limite ne concerne que les comptes joueurs.'), 422);
        }

        return DB::transaction(function () use ($actor, $player, $maxStakeMinor, $reason) {
            $locked = User::whereKey($player->id)->lockForUpdate()->firstOrFail();
            $before = $locked->max_stake_minor;

            if ($before === $maxStakeMinor) {
                return $locked; // rien ne change, rien à journaliser
            }

            $locked->forceFill(['max_stake_minor' => $maxStakeMinor])->save();

            $this->audit->record(AuditAction::UserStakeLimitChanged, $locked,
                ['max_stake_minor' => $before], ['max_stake_minor' => $maxStakeMinor], reason: $reason, actor: $actor);

            return $locked;
        });
    }
}
