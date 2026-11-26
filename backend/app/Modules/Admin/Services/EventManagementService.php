<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\Settlement\Services\ResultCorrectionService;
use App\Modules\Settlement\Services\ResultRecorder;
use App\Modules\SportsData\Enums\EventStatus;
use App\Modules\SportsData\Enums\MarketStatus;
use App\Modules\SportsData\Models\Event;
use App\Modules\SportsData\Models\Market;
use App\Modules\SportsData\Services\MarketAvailabilityService;
use App\Support\Http\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestion d'un match par l'administration (audit du 2026-11-22, points A1 et A3), motif obligatoire et audité.
 *
 * - Heure et report : un match programmé ou reporté change d'heure ou passe « reporté » / « programmé ». La
 *   synchronisation ne remplace plus ce que l'administrateur a fixé (admin_locked_at). Les tickets gardent l'heure
 *   figée au moment du pari : un match qui ne commence pas dans les 48 h qui la suivent est annulé (D-05 §3).
 * - Annulation (D-05 §2) : sélections à la cote 1 puis nouveau règlement. Un match pas encore joué (à venir ou
 *   reporté) est annulé tout de suite : personne ne connaît son issue. Un match commencé passe par le double contrôle
 *   des résultats (un second administrateur valide) ; ses marchés sont suspendus en attendant. Un match qui a déjà un
 *   résultat se corrige par une demande de correction (D-05 §5).
 * - Masquage : le match disparaît du site et n'est plus pariable ; les tickets déjà pris ne changent pas.
 * - Suspension / réouverture de tous les marchés d'un match en une fois (chaque marché garde sa trace d'audit).
 */
class EventManagementService
{
    /** Source d'un résultat enregistré par l'administration (comme une saisie manuelle validée). */
    public const SOURCE = 'manual';

    /** Colonne markets.suspension_reason. */
    private const MARKET_REASON_MAX = 255;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResultRecorder $recorder,
        private readonly ResultCorrectionService $corrections,
        private readonly MarketAvailabilityService $availability,
    ) {}

    /**
     * @param  array{starts_at?: CarbonInterface, status?: EventStatus, hidden?: bool}  $changes
     * @return array{event: Event, correction: ?ResultCorrection} correction : demande à valider (annulation d'un match commencé)
     *
     * @throws ApiException event_not_editable | event_unchanged | result_exists | invalid_change
     */
    public function update(User $actor, Event $event, array $changes, string $reason): array
    {
        if (($changes['status'] ?? null) === EventStatus::Cancelled) {
            if (count($changes) > 1) {
                throw new ApiException('invalid_change', __('Annulez le match sans changer en même temps son heure ni sa visibilité.'), 422);
            }

            return $this->cancel($actor, $event, $reason);
        }

        $event = DB::transaction(function () use ($actor, $event, $changes, $reason) {
            $locked = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            if (array_key_exists('starts_at', $changes) || array_key_exists('status', $changes)) {
                $this->assertSchedulable($locked);
                $locked->forceFill(array_filter([
                    'starts_at' => $changes['starts_at'] ?? null,
                    'status' => $changes['status'] ?? null,
                ]));
                if ($locked->isDirty(['starts_at', 'status'])) {
                    $locked->admin_locked_at = now();
                }
            }

            if (array_key_exists('hidden', $changes) && $changes['hidden'] !== ($locked->hidden_at !== null)) {
                $locked->forceFill($changes['hidden']
                    ? ['hidden_at' => now(), 'hidden_reason' => $reason]
                    : ['hidden_at' => null, 'hidden_reason' => null]);
            }

            if (! $locked->isDirty()) {
                throw new ApiException('event_unchanged', __('Rien à changer : le match est déjà dans cet état.'), 409);
            }

            $locked->save();
            $this->audit->record(AuditAction::EventUpdated, $locked, $before, $this->snapshot($locked), $reason, $actor);

            return $locked;
        });

        return ['event' => $event, 'correction' => null];
    }

    /**
     * Suspend tous les marchés ouverts du match (et reprend la main sur les suspensions automatiques).
     *
     * @return int nombre de marchés suspendus
     */
    public function suspend(User $actor, Event $event, string $reason): int
    {
        $this->assertNotOver($event);

        $markets = $event->markets()->whereIn('status', [MarketStatus::Open, MarketStatus::Suspended])->orderBy('id')->get()
            ->reject(fn (Market $m) => $m->status === MarketStatus::Suspended && ! $this->availability->isAutomatic($m));

        return $this->changeMarkets($actor, $event, $markets->all(), $reason, AuditAction::EventSuspended,
            fn (Market $m) => $this->availability->suspend($actor, $m, Str::limit($reason, self::MARKET_REASON_MAX, '')));
    }

    /**
     * Rouvre les marchés du match suspendus par l'administration. Les suspensions automatiques (cotes trop
     * anciennes) restent : la synchronisation les lève quand la cote redevient fraîche.
     *
     * @return int nombre de marchés rouverts
     */
    public function reopen(User $actor, Event $event, string $reason): int
    {
        $this->assertNotOver($event);

        $markets = $event->markets()->where('status', MarketStatus::Suspended)->orderBy('id')->get()
            ->reject(fn (Market $m) => $this->availability->isAutomatic($m));

        return $this->changeMarkets($actor, $event, $markets->all(), $reason, AuditAction::EventReopened,
            fn (Market $m) => $this->availability->reopen($actor, $m, Str::limit($reason, self::MARKET_REASON_MAX, '')));
    }

    /** @return array{event: Event, correction: ?ResultCorrection} */
    private function cancel(User $actor, Event $event, string $reason): array
    {
        $this->assertNotOver($event);
        if (Result::currentFor($event) !== null) {
            throw new ApiException('result_exists', __('Ce match a déjà un résultat : demandez une correction du résultat, validée par un second administrateur.'), 409);
        }

        $notPlayed = $event->status !== EventStatus::Live
            && ($event->starts_at->isFuture() || $event->status === EventStatus::Postponed);

        if (! $notPlayed) {
            // Match commencé : son issue peut être connue, l'annulation est validée par un second administrateur.
            $correction = $this->corrections->request($actor, $event, ResultStatus::Cancelled, null, null, $reason);
            try {
                $this->suspend($actor, $event, 'Annulation du match demandée : '.$reason);
            } catch (ApiException $e) {
                if ($e->errorCode !== 'event_unchanged') {
                    throw $e;
                }
                // Aucun marché encore ouvert : rien à suspendre.
            }

            return ['event' => $event->fresh(), 'correction' => $correction];
        }

        $before = $this->snapshot($event);
        // Résultat « annulé » : le match passe « annulé », ses marchés sont fermés et le règlement (SettleEventJob)
        // annule ses sélections en attente (cote 1) puis règle les tickets devenus complets.
        $result = $this->recorder->record($event, ResultStatus::Cancelled, null, null, source: self::SOURCE,
            reason: $reason, enteredBy: $actor, cancelBeforeStart: true);

        $event = $event->fresh();
        $this->audit->record(AuditAction::EventUpdated, $event, $before, $this->snapshot($event), $reason, $actor,
            ['result_id' => $result === null ? null : (string) $result->id]);

        return ['event' => $event, 'correction' => null];
    }

    /**
     * @param  list<Market>  $markets
     * @param  callable(Market): Market  $change
     */
    private function changeMarkets(User $actor, Event $event, array $markets, string $reason, AuditAction $action, callable $change): int
    {
        if ($markets === []) {
            throw new ApiException('event_unchanged', $action === AuditAction::EventSuspended
                ? __('Aucun marché ouvert à suspendre pour ce match.')
                : __('Aucun marché suspendu par l\'administration à rouvrir pour ce match.'), 409);
        }

        foreach ($markets as $market) {
            $change($market);
        }

        $this->audit->record($action, $event, after: ['markets' => array_map(fn (Market $m) => (string) $m->id, $markets)],
            reason: $reason, actor: $actor, metadata: ['markets_count' => count($markets)]);

        return count($markets);
    }

    /** Heure et report : match programmé ou reporté, sans résultat. */
    private function assertSchedulable(Event $event): void
    {
        if (! in_array($event->status, [EventStatus::Scheduled, EventStatus::Postponed], true) || Result::currentFor($event) !== null) {
            throw new ApiException('event_not_editable', __('Seul un match programmé ou reporté, sans résultat, peut changer d\'heure ou de statut.'), 409,
                ['status' => $event->status->value]);
        }
    }

    private function assertNotOver(Event $event): void
    {
        if (in_array($event->status, [EventStatus::Finished, EventStatus::Cancelled], true)) {
            throw new ApiException('event_not_editable', __('Ce match est terminé ou annulé.'), 409, ['status' => $event->status->value]);
        }
    }

    /** @return array{starts_at: string, status: string, hidden: bool} */
    private function snapshot(Event $event): array
    {
        return [
            'starts_at' => $event->starts_at->toIso8601String(),
            'status' => $event->status->value,
            'hidden' => $event->hidden_at !== null,
        ];
    }
}
