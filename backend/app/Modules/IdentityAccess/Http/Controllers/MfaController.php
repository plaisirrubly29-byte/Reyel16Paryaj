<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\IdentityAccess\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mfa->setup($request->user())]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $codes = $this->mfa->confirm($request->user(), $data['code'], $request->session());

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function verify(Request $request): Response
    {
        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ]);

        $this->mfa->verify($request->user(), $data['code'] ?? null, $data['recovery_code'] ?? null, $request->session());

        return response()->noContent();
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $codes = $this->mfa->regenerateRecoveryCodes($request->user(), $data['code'], $request->session());

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function reset(Request $request, User $user): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $this->mfa->reset($request->user(), $user, $data['reason']);

        return response()->noContent();
    }
}
