<?php

namespace App\Modules\Lottery\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Tirage de borlette (ex. « New York Midi ») : heure locale, fuseau et jours, tels que publiés par le fournisseur.
 *
 * @property int $id
 * @property string $external_key
 * @property string $name
 * @property string $draw_time
 * @property string $timezone
 * @property list<int>|null $days
 * @property bool $is_active
 * @property string $source
 * @property Carbon|null $synced_at
 */
class LotteryDraw extends Model
{
    protected $fillable = ['external_key', 'name', 'draw_time', 'timezone', 'days', 'is_active', 'source', 'synced_at'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<LotteryResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(LotteryResult::class);
    }

    /** Dernier résultat publié (par date de tirage). @return HasOne<LotteryResult, $this> */
    public function latestResult(): HasOne
    {
        return $this->hasOne(LotteryResult::class)->ofMany('draw_date', 'max');
    }

    /** Le tirage a-t-il lieu ce jour-là ($date : date locale "YYYY-MM-DD") ? */
    public function runsOn(string $date): bool
    {
        if ($this->days === null) {
            return true;
        }

        return in_array(CarbonImmutable::parse($date, $this->timezone)->dayOfWeekIso, $this->days, true);
    }

    /** Heure du tirage de la date locale $date, en UTC. */
    public function drawAt(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$this->draw_time}", $this->timezone)->utc();
    }

    /** Prochain tirage après $now (en UTC), ou null si aucun jour n'est prévu. */
    public function nextDrawAt(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $day = $now->setTimezone($this->timezone)->startOfDay();

        for ($i = 0; $i < 8; $i++) {
            $date = $day->addDays($i)->toDateString();
            if ($this->runsOn($date) && $this->drawAt($date)->greaterThan($now)) {
                return $this->drawAt($date);
            }
        }

        return null;
    }
}
