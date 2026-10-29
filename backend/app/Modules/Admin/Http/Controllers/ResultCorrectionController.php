<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\ResultCorrectionResource;
use App\Modules\Settlement\Enums\ResultCorrectionStatus;
use App\Modules\Settlement\Enums\ResultStatus;
use App\Modules\Settlement\Models\Result;
use App\Modules\Settlement\Models\ResultCorrection;
use App\Modules\Settlement\Services\ResultCorrectionService;
use App\Modules\SportsData\Models\Event;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Correction de résultat à double contrôle (P5-06) : demander (results.enter), valider (results.approve,
 * jamais le demandeur), refuser ou retirer ; historique complet d'un match (events.view).
 */
class ResultCorrectionController extends Controller
{
    private const RELATIONS = ['event.homeTeam', 'event.awayTeam', 'basedOn', 'requester', 'decider'];

    public function __construct(private readonly ResultCorrectionService $corrections) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ResultCorrectionStatus::class)],
            'event' => ['nullable', 'integer', 'min:1'],
        ]);

        $corrections = ResultCorrection::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event_id', $event))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ResultCorrectionResource::collection($corrections);
    }

    public function show(ResultCorrection $correction): ResultCorrectionResource
    {
        $resource = new ResultCorrectionResource($correction->load(self::RELATIONS));

        // En attente : ce que la validation changerait maintenant (tickets, montants), sans rien écrire.
        return $correction->status === ResultCorrectionStatus::Pending
            ? $resource->withPreview($this->corrections->preview($correction))
            : $resource;
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ResultStatus::class)],
            'home_score' => ['nullable', 'required_if:status,final', 'string', 'max:10'],
            'away_score' => ['nullable', 'required_if:status,final', 'string', 'max:10'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $correction = $this->corrections->request($request->user(), $event, ResultStatus::from($data['status']),
            $data['home_score'] ?? null, $data['away_score'] ?? null, trim($data['reason']));

        return $this->show($correction)->response()->setStatusCode(201);
    }

    public function approve(Request $request, ResultCorrection $correction): ResultCorrectionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new ResultCorrectionResource($this->corrections->approve($request->user(), $correction, trim($data['reason']))->load(self::RELATIONS));
    }

    public function reject(Request $request, ResultCorrection $correction): ResultCorrectionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new ResultCorrectionResource($this->corrections->reject($request->user(), $correction, trim($data['reason']))->load(self::RELATIONS));
    }

    /** Historique complet d'un match : versions du résultat (source, auteur, motif) et demandes de correction. */
    public function history(Event $event): JsonResponse
    {
        $results = Result::where('event_id', $event->id)->orderBy('version')->with('enteredBy')->get();
        $corrections = ResultCorrection::where('event_id', $event->id)->orderBy('id')->with(self::RELATIONS)->get();

        return response()->json(['data' => [
            'event_id' => (string) $event->id,
            'results' => $results->map(fn (Result $r) => ResultCorrectionResource::outcome($r) + [
                'id' => (string) $r->id,
                'source' => $r->source,
                'external_id' => $r->external_id,
                'entered_by' => $r->enteredBy === null ? null : ['id' => (string) $r->enteredBy->id, 'name' => $r->enteredBy->name],
                'correction_reason' => $r->correction_reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'corrections' => ResultCorrectionResource::collection($corrections),
        ]]);
    }
}
