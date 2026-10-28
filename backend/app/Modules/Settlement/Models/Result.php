<?php

namespace App\Modules\Settlement\Models;

use App\Models\User;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\SportsData\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Version d'un résultat de match. Append-only (exception côté modèle, trigger côté PostgreSQL) :
 * une correction est une nouvelle version qui remplace la précédente (supersedes_id).
 * Le résultat en vigueur d'un match est sa version la plus haute (Result::currentFor).
 * Écrire uniquement via App\Modules\Settlement\Services\ResultRecorder.
 *
 * @property int $id
 * @property int $event_id
 * @property int $version
 * @property ResultStatus $status
 * @property string|null $home_score
 * @property string|null $away_score
 * @property string $source fournisseur ("fake", "theodds") ou "manual"
 * @property string|null $external_id
 * @property Carbon|null $provider_updated_at
 * @property int|null $supersedes_id
 * @property string|null $correction_reason
 * @property int|null $entered_by
 * @property Carbon $created_at
 */
class Result extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ResultStatus::class,
            'provider_updated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un résultat est en ajout seul : enregistrer une nouvelle version.'));
        static::deleting(fn () => throw new LogicException('Un résultat est en ajout seul : enregistrer une nouvelle version.'));
    }

    public static function currentFor(Event|int $event): ?self
    {
        return self::where('event_id', $event instanceof Event ? $event->id : $event)->orderByDesc('version')->first();
    }

    /** Même issue (statut et score) qu'une autre version : la recevoir à nouveau ne change rien. */
    public function sameOutcomeAs(ResultStatus $status, ?string $homeScore, ?string $awayScore): bool
    {
        return $this->status === $status && $this->home_score === $homeScore && $this->away_score === $awayScore;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Result, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasOne<Result, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /** @return BelongsTo<User, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
