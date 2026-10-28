<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Services\RoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleAssignmentController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $roles) {}

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::enum(RoleSlug::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $change = $this->roles->sync($request->user(), $user, $data['roles'], $data['reason']);

        return response()->json(['data' => [
            'id' => (string) $user->id,
            'roles' => $change['after'],
        ]]);
    }
}
