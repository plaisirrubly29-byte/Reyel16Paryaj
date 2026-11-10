<?php

namespace App\Modules\Lottery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lottery\Http\Resources\LotteryResultResource;
use App\Modules\Lottery\Models\LotteryDraw;
use App\Modules\Lottery\Models\LotteryResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Tirages de borlette et résultats publiés : public, sans authentification. 404 tant que LOTTERY_ENABLED=false. */
class LotteryController extends Controller
{
    /** Tirages actifs, par heure, avec le prochain tirage et le dernier résultat. */
    public function draws(): JsonResponse
    {
        $this->ensureEnabled();

        $draws = LotteryDraw::where('is_active', true)
            ->with('latestResult.draw')
            ->orderBy('draw_time')
            ->orderBy('name')
            ->get()
            ->map(fn (LotteryDraw $draw) => [
                'key' => $draw->external_key,
                'name' => $draw->name,
                'draw_time' => $draw->draw_time,
                'timezone' => $draw->timezone,
                'days' => $draw->days,
                'next_draw_at' => $draw->nextDrawAt()?->toIso8601ZuluString(),
                'latest_result' => $draw->latestResult ? new LotteryResultResource($draw->latestResult) : null,
            ]);

        return response()->json(['data' => $draws]);
    }

    /** Résultats publiés, du plus récent au plus ancien (filtrables par tirage et par date). */
    public function results(Request $request): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'draw' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $results = LotteryResult::query()
            ->select('lottery_results.*')
            ->join('lottery_draws', 'lottery_draws.id', '=', 'lottery_results.lottery_draw_id')
            ->with('draw')
            ->when($data['draw'] ?? null, fn ($q, $key) => $q->where('lottery_draws.external_key', $key))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->where('lottery_results.draw_date', $date))
            ->orderByDesc('lottery_results.draw_date')
            ->orderByDesc('lottery_draws.draw_time')
            ->orderBy('lottery_draws.name')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->withQueryString();

        return LotteryResultResource::collection($results);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('lottery.enabled'), 404);
    }
}
