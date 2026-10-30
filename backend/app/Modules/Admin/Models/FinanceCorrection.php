<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Modules\Admin\Enums\FinanceCorrectionStatus;
use App\Modules\Wallet\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Correction financière à double validation. Écrire uniquement via
 * App\Modules\Admin\Services\FinanceCorrectionService.
 *
 * @property int $id
 * @property int $ledger_entry_id
 * @property FinanceCorrectionStatus $status
 * @property string $reason
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property int|null $reversal_entry_id
 * @property Carbon $created_at
 */
class FinanceCorrection extends Model
{
    /** Toutes les colonnes sauf id : liste explicite, sans introspection du schéma à chaque requête (test de charge P8-03). */
    protected $fillable = [
        'ledger_entry_id', 'status', 'reason', 'requested_by', 'decided_by', 'decision_reason', 'decided_at',
        'reversal_entry_id', 'created_at', 'updated_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => FinanceCorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /** @return BelongsTo<LedgerEntry, $this> */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'reversal_entry_id');
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
