<?php

namespace App\Modules\Settlement\Models;

use App\Models\User;
use App\Modules\Settlement\Enums\ResultCorrectionStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\SportsData\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Demande de correction d'un résultat déjà réglé (P5-06), à double contrôle.
 *
 * @property int $id
 * @property int $event_id
 * @property int $based_on_result_id
 * @property ResultCorrectionStatus $status
 * @property ResultStatus $result_status
 * @property string|null $home_score
 * @property string|null $away_score
 * @property string $source
 * @property string $reason
 * @property int|null $requested_by null : correction envoyée par le fournisseur
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property int|null $applied_result_id
 * @property array<string, mixed>|null $impact
 */
class ResultCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ResultCorrectionStatus::class,
            'result_status' => ResultStatus::class,
            'provider_updated_at' => 'datetime',
            'decided_at' => 'datetime',
            'impact' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Result, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'based_on_result_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
