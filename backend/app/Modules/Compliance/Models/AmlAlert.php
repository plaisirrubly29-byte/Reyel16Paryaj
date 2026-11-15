<?php

namespace App\Modules\Compliance\Models;

use App\Models\User;
use App\Modules\Compliance\Enums\AmlAlertStatus;
use App\Modules\Compliance\Enums\AmlRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerte AML (V2-C04). Créée par AmlMonitor quand une règle se déclenche ; traitée uniquement via
 * AmlMonitor::review (classée ou signalée, motif, audit).
 *
 * @property int $id
 * @property int $user_id
 * @property AmlRule $rule
 * @property AmlAlertStatus $status
 * @property int $ledger_entry_id
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed> $details
 * @property int|null $reviewed_by
 * @property string|null $review_reason
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 */
class AmlAlert extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'user_id', 'rule', 'status', 'ledger_entry_id', 'amount_minor', 'currency', 'details', 'reviewed_by',
        'review_reason', 'reviewed_at', 'created_at', 'updated_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'rule' => AmlRule::class,
            'status' => AmlAlertStatus::class,
            'amount_minor' => 'integer',
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
