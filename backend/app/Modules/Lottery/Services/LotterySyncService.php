<?php

namespace App\Modules\Lottery\Services;

use App\Modules\Lottery\Contracts\LotteryProvider;
use App\Modules\Lottery\Data\DrawResultData;
use App\Modules\Lottery\Exceptions\LotteryException;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Synchronisation des tirages et des résultats publiés par le fournisseur.
 *
 * Règles de sûreté (un résultat servira à payer les tickets à l'étape 2) :
 * - un résultat n'est accepté que pour un tirage connu, un jour où il a lieu, et après l'heure du tirage ;
 * - chaque lot fait exactement deux chiffres (pick 3 : trois, pick 4 : quatre) ;
 * - un résultat importé n'est jamais écrasé : des numéros différents pour le même tirage sont un conflit,
 *   journalisé (lottery.result_conflict) et laissé à l'administration.
 */
class LotterySyncService
{
    public function __construct(private readonly LotteryProvider $provider) {}

    /** @throws LotteryException */
    public function sync(?int $daysBack = null): LotterySyncReport
    {
        $draws = $this->syncDraws();

        $daysBack ??= (int) config('lottery.results_days_back', 1);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $report = $this->importResults($today->subDays(max(0, $daysBack)), $today);

        return new LotterySyncReport($draws, $report['created'], $report['unchanged'], $report['rejected'], $report['conflicts']);
    }

    /**
     * Tirages actifs = ceux de la dernière liste du fournisseur. Une liste vide (panne partielle) ne désactive rien.
     *
     * @throws LotteryException
     */
    public function syncDraws(): int
    {
        $draws = $this->provider->draws();
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($draws, $now) {
            foreach ($draws as $data) {
                LotteryDraw::updateOrCreate(['external_key' => $data->key], [
                    'name' => $data->name,
                    'draw_time' => $data->drawTime,
                    'timezone' => $data->timezone,
                    'days' => $data->days,
                    'is_active' => true,
                    'source' => $this->provider->name(),
                    'synced_at' => $now,
                ]);
            }

            if ($draws !== []) {
                LotteryDraw::whereNotIn('external_key', array_map(fn ($d) => $d->key, $draws))
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
        });

        return count($draws);
    }

    /**
     * @return array{created: int, unchanged: int, rejected: int, conflicts: int}
     *
     * @throws LotteryException
     */
    public function importResults(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = ['created' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflicts' => 0];
        $draws = LotteryDraw::all()->keyBy('external_key');

        foreach ($this->provider->results($from, $to) as $data) {
            $draw = $draws->get($data->drawKey);
            $error = $this->validate($data, $draw);
            if ($error !== null) {
                Log::warning('lottery.result_rejected', ['draw' => $data->drawKey, 'date' => $data->drawDate, 'error' => $error]);
                $counts['rejected']++;

                continue;
            }

            $existing = LotteryResult::where('lottery_draw_id', $draw->id)->where('draw_date', $data->drawDate)->first();
            if ($existing !== null) {
                if ($this->sameNumbers($existing, $data)) {
                    $counts['unchanged']++;
                } else {
                    Log::error('lottery.result_conflict', [
                        'draw' => $data->drawKey,
                        'date' => $data->drawDate,
                        'stored' => $existing->lots(),
                        'received' => $data->lots(),
                    ]);
                    $counts['conflicts']++;
                }

                continue;
            }

            LotteryResult::create([
                'lottery_draw_id' => $draw->id,
                'draw_date' => $data->drawDate,
                'lot1' => $data->lot1,
                'lot2' => $data->lot2,
                'lot3' => $data->lot3,
                'pick3' => $data->pick3,
                'pick4' => $data->pick4,
                'source' => $this->provider->name(),
                'external_id' => $data->externalId,
                'published_at' => $data->publishedAt,
            ]);
            Log::info('lottery.result_imported', ['draw' => $data->drawKey, 'date' => $data->drawDate, 'lots' => $data->lots()]);
            $counts['created']++;
        }

        return $counts;
    }

    private function validate(DrawResultData $data, ?LotteryDraw $draw): ?string
    {
        if ($draw === null) {
            return 'tirage inconnu';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data->drawDate) || ! checkdate((int) substr($data->drawDate, 5, 2), (int) substr($data->drawDate, 8, 2), (int) substr($data->drawDate, 0, 4))) {
            return 'date invalide';
        }
        foreach ($data->lots() as $lot) {
            if (! preg_match('/^\d{2}$/D', $lot)) {
                return 'lot invalide (deux chiffres attendus)';
            }
        }
        if ($data->pick3 !== null && ! preg_match('/^\d{3}$/D', $data->pick3)) {
            return 'pick 3 invalide';
        }
        if ($data->pick4 !== null && ! preg_match('/^\d{4}$/D', $data->pick4)) {
            return 'pick 4 invalide';
        }
        if (! $draw->runsOn($data->drawDate)) {
            return 'pas de tirage ce jour-là';
        }
        if ($draw->drawAt($data->drawDate)->greaterThan(CarbonImmutable::now())) {
            return "résultat publié avant l'heure du tirage";
        }

        return null;
    }

    private function sameNumbers(LotteryResult $stored, DrawResultData $data): bool
    {
        return $stored->lots() === $data->lots()
            && ($data->pick3 === null || $stored->pick3 === $data->pick3)
            && ($data->pick4 === null || $stored->pick4 === $data->pick4);
    }
}
