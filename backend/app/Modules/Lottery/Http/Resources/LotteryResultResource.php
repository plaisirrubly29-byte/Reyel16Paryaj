<?php

namespace App\Modules\Lottery\Http\Resources;

use App\Modules\Lottery\Models\LotteryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LotteryResult */
class LotteryResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $draw = $this->draw;

        return [
            'draw' => ['key' => $draw->external_key, 'name' => $draw->name],
            'date' => $this->draw_date,
            'drawn_at' => $draw->drawAt($this->draw_date)->toIso8601ZuluString(),
            // Toujours des chaînes : un lot « 07 » garde son zéro.
            'lots' => $this->lots(),
            'pick3' => $this->pick3,
            'pick4' => $this->pick4,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }
}
