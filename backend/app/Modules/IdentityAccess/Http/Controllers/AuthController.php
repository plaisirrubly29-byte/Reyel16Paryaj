<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\IdentityAccess\Http\Requests\LoginRequest;
use App\Modules\IdentityAccess\Http\Requests\RegisterRequest;
use App\Modules\IdentityAccess\Http\Resources\UserResource;
use App\Modules\IdentityAccess\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        $this->startSession($request, $user);

        return (new UserResource($user->load('roles', 'profile')))->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): UserResource
    {
        $user = $this->auth->verifyCredentials($request->validated('email'), $request->validated('password'));

        $this->startSession($request, $user);
        $this->auth->recordLogin($user);

        return new UserResource($user->load('roles', 'profile'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        // Statut explicite : JsonResource renverrait 201 si le modèle vient d'être créé dans le même cycle.
        return (new UserResource($request->user()->load('roles', 'profile')))->response()->setStatusCode(200);
    }

    private function startSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        // Nouvel identifiant de session après authentification (anti fixation de session).
        $request->session()->regenerate();
    }
}
