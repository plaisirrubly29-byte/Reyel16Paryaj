<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AmlAlertResource;
use App\Modules\Compliance\Enums\AmlAlertStatus;
use App\Modules\Compliance\Enums\AmlRule;
use App\Modules\Compliance\Models\AmlAlert;
use App\Modules\Compliance\Services\AmlMonitor;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** File de revue des alertes AML (V2-C04) : consulter (aml.view), classer ou signaler avec motif (aml.review). */
class AmlAlertController extends Controller
{
    private const RELATIONS = ['user:id,name', 'reviewer:id,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AmlAlertStatus::class)],
            'rule' => ['nullable', Rule::enum(AmlRule::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $alerts = AmlAlert::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['rule'] ?? null, fn ($q, string $rule) => $q->where('rule', $rule))
            ->when($filters['user_id'] ?? null, fn ($q, int $userId) => $q->where('user_id', $userId))
            ->when($filters['from'] ?? null, fn ($q, string $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, string $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AmlAlertResource::collection($alerts);
    }

    public function show(AmlAlert $alert): AmlAlertResource
    {
        return new AmlAlertResource($alert->load(self::RELATIONS));
    }

    public function review(Request $request, AmlAlert $alert, AmlMonitor $monitor): AmlAlertResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([AmlAlertStatus::Dismissed->value, AmlAlertStatus::Reported->value])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $reviewed = $monitor->review($request->user(), $alert, AmlAlertStatus::from($data['decision']), $data['reason']);

        return new AmlAlertResource($reviewed->load(self::RELATIONS));
    }
}
