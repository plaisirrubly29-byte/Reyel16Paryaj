<?php

namespace App\Modules\Settlement\Services;

use App\Models\User;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Jobs\SettleEventJob;
use App\Modules\Settlement\Models\Result;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Point d'entrée unique de l'enregistrement d'un résultat (import du fournisseur, plus tard saisie admin).
 *
 * - Idempotent : recevoir la même issue (statut + score) ne crée rien.
 * - Une issue différente crée une nouvelle version qui remplace la précédente (historique conservé) ;
 *   la réévaluation des tickets déjà réglés relève de la correction auditée (P5-06).
 * - Le match passe à "finished" (ou "cancelled") avec le score en vigueur ; ses marchés encore ouverts
 *   ou suspendus sont fermés.
 * - Chaque nouvelle version lance le règlement des tickets du match (SettleEventJob).
 */
class ResultRecorder
{
    /** Score accepté : entier positif ou nul, sans signe ni décimale. */
    private const SCORE_PATTERN = '/^\d{1,3}$/';

    /**
     * @return Result|null la nouvelle version, ou null si l'issue était déjà enregistrée
     *
     * @throws InvalidArgumentException résultat invalide (score, match pas encore commencé)
     */
    public function record(
        Event $event,
        ResultStatus $status,
        ?string $homeScore,
        ?string $awayScore,
        string $source,
        ?string $externalId = null,
        ?CarbonImmutable $providerUpdatedAt = null,
        ?string $reason = null,
        ?User $enteredBy = null,
    ): ?Result {
        [$homeScore, $awayScore] = $this->validate($status, $homeScore, $awayScore);

        if ($event->starts_at->isFuture()) {
            throw new InvalidArgumentException('Résultat reçu pour un match qui n\'a pas commencé.');
        }

        return DB::transaction(function () use ($event, $status, $homeScore, $awayScore, $source, $externalId, $providerUpdatedAt, $reason, $enteredBy) {
            // Verrou sur le match : deux imports simultanés ne créent jamais deux fois la même version.
            $locked = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();

            $current = Result::currentFor($locked);
            if ($current?->sameOutcomeAs($status, $homeScore, $awayScore)) {
                return null;
            }

            $result = Result::create([
                'event_id' => $locked->id,
                'version' => ($current->version ?? 0) + 1,
                'status' => $status,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'source' => $source,
                'external_id' => $externalId,
                'provider_updated_at' => $providerUpdatedAt,
                'supersedes_id' => $current?->id,
                'correction_reason' => $current === null ? null : ($reason ?? 'Résultat mis à jour par la source'),
                'entered_by' => $enteredBy?->id,
            ]);

            $locked->update([
                'status' => $status === ResultStatus::Final ? EventStatus::Finished : EventStatus::Cancelled,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'result_received_at' => now(),
            ]);

            $locked->markets()
                ->whereIn('status', [MarketStatus::Open, MarketStatus::Suspended])
                ->update(['status' => MarketStatus::Closed]);

            if ($current !== null) {
                Log::warning('settlement.result_corrected', [
                    'event_id' => $locked->id,
                    'from' => ['version' => $current->version, 'status' => $current->status->value, 'score' => "{$current->home_score}-{$current->away_score}"],
                    'to' => ['version' => $result->version, 'status' => $status->value, 'score' => "{$homeScore}-{$awayScore}"],
                    'source' => $source,
                ]);
            }

            // Après validation de la transaction : le job ne voit jamais un résultat annulé par un rollback.
            SettleEventJob::dispatch($locked->id)->afterCommit();

            return $result;
        });
    }

    /**
     * Scores normalisés d'une issue, sans rien enregistrer (demande de correction, P5-06).
     *
     * @return array{0: ?string, 1: ?string}
     *
     * @throws InvalidArgumentException résultat invalide
     */
    public function normalized(ResultStatus $status, ?string $homeScore, ?string $awayScore): array
    {
        return $this->validate($status, $homeScore, $awayScore);
    }

    /** @return array{0: ?string, 1: ?string} scores normalisés ("02" → "2") */
    private function validate(ResultStatus $status, ?string $homeScore, ?string $awayScore): array
    {
        if ($status === ResultStatus::Cancelled) {
            if ($homeScore !== null || $awayScore !== null) {
                throw new InvalidArgumentException('Un match annulé n\'a pas de score.');
            }

            return [null, null];
        }

        $scores = [];
        foreach ([$homeScore, $awayScore] as $score) {
            $score = $score === null ? null : trim($score);
            if ($score === null || ! preg_match(self::SCORE_PATTERN, $score)) {
                throw new InvalidArgumentException('Score final absent ou invalide.');
            }
            $scores[] = (string) (int) $score;
        }

        return $scores;
    }
}
