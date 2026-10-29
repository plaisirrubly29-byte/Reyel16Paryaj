<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Http\Resources\AdminUserResource;
use App\Modules\Admin\Support\UserSearch;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Enums\AccountStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Services\AccountStatusService;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'role' => ['nullable', Rule::enum(RoleSlug::class)],
        ]);

        $users = User::query()
            ->with(['roles', 'profile'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => UserSearch::apply($query, $search))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('slug', $role)))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function show(Request $request, User $user, AuditLogger $audit): AdminUserResource
    {
        // Consultation de données personnelles complètes : journalisée.
        if ($request->user()->hasPermission('users.view_personal_data')) {
            $audit->record(AuditAction::UserPersonalDataViewed, $user);
        }

        return new AdminUserResource($user->load(['roles', 'profile']));
    }

    public function update(Request $request, User $user, AccountStatusService $accounts): AdminUserResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([AccountStatus::Active->value, AccountStatus::Suspended->value])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $user = $data['status'] === AccountStatus::Suspended->value
            ? $accounts->suspend($request->user(), $user, $data['reason'])
            : $accounts->reactivate($request->user(), $user, $data['reason']);

        return new AdminUserResource($user->load(['roles', 'profile']));
    }
}
