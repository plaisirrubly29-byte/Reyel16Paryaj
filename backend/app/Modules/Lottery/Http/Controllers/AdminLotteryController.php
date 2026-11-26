<?php

namespace App\Modules\Lottery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Lottery\Enums\LotteryTicketStatus;
use App\Modules\Lottery\Http\Resources\LotteryTicketResource;
use App\Modules\Lottery\Models\LotteryBlockedNumber;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryPlay;
use App\Modules\Lottery\Models\LotteryTicket;
use App\Modules\Lottery\Services\LotteryRules;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Administration de la borlette (V2-D09) : fiches, engagement par boule, boules bloquées. */
class AdminLotteryController extends Controller
{
    public function tickets(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'reference' => ['nullable', 'string', 'max:30'],
            'draw' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['awaiting', 'pending', 'won', 'lost', 'void', 'rejected'])],
            'user_id' => ['nullable', 'integer'],
        ]);

        $tickets = LotteryTicket::with('draw', 'plays', 'user')
            ->when($filters['reference'] ?? null, fn ($q, $ref) => $q->where('reference', strtoupper(str_replace(' ', '', $ref))))
            ->when($filters['draw'] ?? null, fn ($q, $key) => $q->whereHas('draw', fn ($d) => $d->where('external_key', $key)))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('draw_date', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $tickets->getCollection()->transform(function (LotteryTicket $ticket) {
            $resource = new LotteryTicketResource($ticket);
            $resource->withUser = true;

            return $resource;
        });

        return response()->json([
            'data' => $tickets->getCollection()->map(fn ($r) => $r->resolve(request()))->all(),
            'links' => ['first' => $tickets->url(1), 'last' => $tickets->url($tickets->lastPage()), 'prev' => $tickets->previousPageUrl(), 'next' => $tickets->nextPageUrl()],
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'per_page' => $tickets->perPage(), 'total' => $tickets->total()],
        ]);
    }

    public function exposure(Request $request, LotteryRules $rules): JsonResponse
    {
        $data = $request->validate([
            'draw' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date_format:Y-m-d'],
            'game' => ['nullable', Rule::in(LotteryRules::GAMES)],
        ]);

        $draw = LotteryDraw::where('external_key', $data['draw'])->firstOrFail();
        $currency = (string) config('app.currency', 'HTG');
        $money = fn (int $minor) => ['minor' => $minor, 'currency' => $currency];

        $pending = LotteryTicket::where('lottery_draw_id', $draw->id)->where('draw_date', $data['date'])
            ->where('status', LotteryTicketStatus::Pending->value);

        $blocked = LotteryBlockedNumber::whereNull('lifted_at')
            ->where(fn ($q) => $q->whereNull('lottery_draw_id')->orWhere('lottery_draw_id', $draw->id))
            ->where(fn ($q) => $q->whereNull('draw_date')->orWhere('draw_date', $data['date']))
            ->pluck('number')->all();
        $limit = $rules->maxExposurePerNumberMinor();

        $rows = LotteryPlay::query()
            ->whereIn('lottery_ticket_id', (clone $pending)->select('id'))
            ->when($data['game'] ?? null, fn ($q, $game) => $q->where('game', $game))
            ->groupBy('game', 'option', 'number1', 'number2')
            ->select('game', 'option', 'number1', 'number2')
            ->selectRaw('SUM(stake_minor) AS stake_minor, SUM(max_payout_minor) AS max_payout_minor')
            ->orderByDesc('max_payout_minor')
            ->limit(200)
            ->get()
            ->map(function ($row) use ($money, $limit, $blocked) {
                $numbers = $row->number2 === null ? [$row->number1] : [$row->number1, $row->number2];

                return [
                    'game' => $row->game,
                    'option' => $row->option === null ? null : (int) $row->option,
                    'numbers' => $numbers,
                    'stake' => $money((int) $row->stake_minor),
                    'max_payout' => $money((int) $row->max_payout_minor),
                    'limit' => $limit === null ? null : $money($limit),
                    'blocked' => array_intersect(LotteryRules::blockableNumbers($row->game, $numbers), $blocked) !== [],
                ];
            })->all();

        return response()->json(['data' => [
            'draw' => ['key' => $draw->external_key, 'name' => $draw->name],
            'date' => $data['date'],
            'closes_at' => $rules->closesAt($draw, $data['date'])->toIso8601ZuluString(),
            'tickets_count' => (clone $pending)->count(),
            'total_stake' => $money((int) (clone $pending)->sum('stake_minor')),
            'numbers' => $rows,
        ]]);
    }

    public function blockedNumbers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draw' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $blocks = LotteryBlockedNumber::whereNull('lifted_at')
            ->with('draw', 'blocker', 'lifter')
            ->when($data['draw'] ?? null, fn ($q, $key) => $q->where(fn ($w) => $w->whereNull('lottery_draw_id')
                ->orWhereHas('draw', fn ($d) => $d->where('external_key', $key))))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->where(fn ($w) => $w->whereNull('draw_date')->orWhere('draw_date', $date)))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $blocks->map(fn ($b) => $this->blockData($b))->all()]);
    }

    public function block(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'regex:/^[0-9]{2,5}$/'],
            'draw' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $draw = null;
        if (($data['draw'] ?? null) !== null) {
            $draw = LotteryDraw::where('external_key', $data['draw'])->first();
            if ($draw === null) {
                throw new ApiException('lottery_draw_unknown', __('Ce tirage n\'existe pas.'), 422);
            }
        }

        $block = DB::transaction(function () use ($data, $draw, $request, $audit) {
            $exists = LotteryBlockedNumber::whereNull('lifted_at')->where('number', $data['number'])
                ->where('lottery_draw_id', $draw?->id)->where('draw_date', $data['date'] ?? null)->lockForUpdate()->exists();
            if ($exists) {
                throw new ApiException('lottery_number_already_blocked', __('Cette boule est déjà bloquée.'), 409);
            }

            $block = LotteryBlockedNumber::create([
                'number' => $data['number'],
                'lottery_draw_id' => $draw?->id,
                'draw_date' => $data['date'] ?? null,
                'reason' => $data['reason'],
                'blocked_by' => $request->user()->id,
            ]);

            $audit->record(AuditAction::LotteryNumberBlocked, $block, [], [
                'number' => $block->number, 'draw' => $draw?->external_key, 'date' => $block->draw_date,
            ], reason: $data['reason'], actor: $request->user());

            return $block;
        });

        return response()->json(['data' => $this->blockData($block->load('draw', 'blocker', 'lifter'))], 201);
    }

    public function unblock(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $block = DB::transaction(function () use ($id, $data, $request, $audit) {
            $block = LotteryBlockedNumber::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($block->lifted_at !== null) {
                throw new ApiException('lottery_block_already_lifted', __('Ce blocage est déjà levé.'), 409);
            }

            $block->update(['lifted_by' => $request->user()->id, 'lifted_at' => now(), 'lift_reason' => $data['reason']]);
            $audit->record(AuditAction::LotteryNumberUnblocked, $block, ['lifted_at' => null], ['lifted_at' => $block->lifted_at->toIso8601ZuluString()],
                reason: $data['reason'], actor: $request->user());

            return $block;
        });

        return response()->json(['data' => $this->blockData($block->load('draw', 'blocker', 'lifter'))]);
    }

    /** @return array<string, mixed> schéma LotteryBlockedNumber */
    private function blockData(LotteryBlockedNumber $block): array
    {
        return [
            'id' => (string) $block->id,
            'number' => $block->number,
            'draw' => $block->draw === null ? null : ['key' => $block->draw->external_key, 'name' => $block->draw->name],
            'date' => $block->draw_date,
            'reason' => $block->reason,
            'blocked_by' => ['id' => (string) $block->blocker->id, 'name' => $block->blocker->name],
            'blocked_at' => $block->created_at->toIso8601ZuluString(),
            'lifted_by' => $block->lifter === null ? null : ['id' => (string) $block->lifter->id, 'name' => $block->lifter->name],
            'lifted_at' => $block->lifted_at?->toIso8601ZuluString(),
            'lift_reason' => $block->lift_reason,
        ];
    }
}
