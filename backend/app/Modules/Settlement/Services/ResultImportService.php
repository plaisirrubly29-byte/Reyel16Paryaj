<?php

namespace App\Modules\Settlement\Services;

use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\SportsData\Contracts\SportsDataProvider;
use App\Modules\SportsData\Data\ResultData;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Exceptions\SportsDataException;
use App\Modules\SportsData\Models\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Importe les résultats d'une compétition depuis le fournisseur (P5-04).
 *
 * - Un seul appel au fournisseur par compétition, seulement pour les compétitions qui ont des matchs
 *   commencés depuis assez longtemps et sans résultat (pendingCompetitions) : pas de crédit dépensé pour rien.
 * - Idempotent : rejouer l'import ne crée aucune version en double (ResultRecorder).
 * - Un résultat invalide est ignoré et journalisé, les autres sont enregistrés.
 */
class ResultImportService
{
    /** Le fournisseur ne remonte les scores que sur 3 jours. */
    public const MAX_DAYS_FROM = 3;

    public function __construct(
        private readonly SportsDataProvider $provider,
        private readonly ResultRecorder $recorder,
    ) {}

    public function import(string $competitionKey, int $daysFrom = 1): ResultImportReport
    {
        $report = new ResultImportReport($competitionKey);

        try {
            $results = $this->provider->results($competitionKey, $daysFrom);
        } catch (SportsDataException $e) {
            $report->error = "{$e->reason}: {$e->getMessage()}";
            Log::warning('settlement.results_import_failed', $report->toArray());

            return $report;
        }

        foreach ($results as $data) {
            $this->importOne($data, $report);
        }

        Log::info('settlement.results_imported', $report->toArray());

        return $report;
    }

    /**
     * Compétitions à interroger : un match commencé depuis au moins results_delay_minutes, il y a moins
     * de 3 jours, toujours sans résultat. Valeur : nombre de jours à demander au fournisseur.
     *
     * @return array<string, int> clé de compétition => daysFrom
     */
    public function pendingCompetitions(): array
    {
        $now = now();

        return Event::query()
            ->join('competitions', 'competitions.id', '=', 'events.competition_id')
            ->where('events.provider', $this->provider->name())
            ->whereIn('events.status', [EventStatus::Scheduled, EventStatus::Live])
            ->where('events.starts_at', '<=', $now->copy()->subMinutes((int) config('sports_data.results_delay_minutes')))
            ->where('events.starts_at', '>', $now->copy()->subDays(self::MAX_DAYS_FROM))
            ->groupBy('competitions.external_key')
            ->orderBy('competitions.external_key')
            ->selectRaw('competitions.external_key as competition_key, min(events.starts_at) as oldest')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->competition_key => max(1, min(self::MAX_DAYS_FROM, (int) ceil($now->diffInHours($row->oldest, true) / 24))),
            ])
            ->all();
    }

    private function importOne(ResultData $data, ResultImportReport $report): void
    {
        $event = Event::where('provider', $this->provider->name())->where('external_id', $data->externalId)->first();

        if ($event === null) {
            $report->unknown++;

            return;
        }

        if (! $data->completed) {
            $report->inProgress++;
            if ($event->status === EventStatus::Scheduled && $event->starts_at->isPast()) {
                $event->update(['status' => EventStatus::Live]);
            }

            return;
        }

        try {
            $result = $this->recorder->record(
                $event,
                ResultStatus::Final,
                $data->homeScore,
                $data->awayScore,
                source: $this->provider->name(),
                externalId: $data->externalId,
                providerUpdatedAt: $data->updatedAt,
            );
        } catch (InvalidArgumentException $e) {
            $report->rejected++;
            Log::warning('settlement.result_rejected', ['competition' => $report->competitionKey, 'id' => $data->externalId, 'error' => $e->getMessage()]);

            return;
        }

        if ($result === null) {
            $report->unchanged++;

            return;
        }

        $result->version === 1 ? $report->recorded++ : $report->corrected++;
        $report->results[] = $result;
    }
}
