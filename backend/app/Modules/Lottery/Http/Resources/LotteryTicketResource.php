<?php

namespace App\Modules\Lottery\Http\Resources;

use App\Modules\Lottery\Models\LotteryPlay;
use App\Modules\Lottery\Models\LotteryTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche de borlette (schéma LotteryTicket du contrat). Le résultat du tirage est joint une fois publié.
 *
 * @mixin LotteryTicket
 */
class LotteryTicketResource extends JsonResource
{
    /** Ajoute le joueur (schéma AdminLotteryTicket). */
    public bool $withUser = false;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $money = fn (?int $minor) => $minor === null ? null : ['minor' => $minor, 'currency' => $this->currency];
        $result = $this->result();
        if ($result !== null) {
            $result->setRelation('draw', $this->draw);
        }

        $data = [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'draw' => [
                'key' => $this->draw->external_key,
                'name' => $this->draw->name,
                'date' => $this->draw_date,
                'draw_at' => $this->draw_at->toIso8601ZuluString(),
            ],
            'status' => $this->status->value,
            'stake' => $money($this->stake_minor),
            'max_payout' => $money($this->max_payout_minor),
            'payout' => $money($this->payout_minor),
            'plays' => $this->plays->map(fn (LotteryPlay $play) => [
                'game' => $play->game,
                'option' => $play->option,
                'numbers' => $play->numbers(),
                'stake' => $money($play->stake_minor),
                'max_payout' => $money($play->max_payout_minor),
                'status' => $play->status->value,
                'winning_lot' => $play->winning_lot,
                'payout' => $money($play->payout_minor),
            ])->values()->all(),
            'result' => $result === null ? null : new LotteryResultResource($result),
            'placed_at' => $this->placed_at->toIso8601ZuluString(),
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
        ];

        if ($this->withUser) {
            $data['user'] = ['id' => (string) $this->user->id, 'name' => $this->user->name];
        }

        return $data;
    }
}
