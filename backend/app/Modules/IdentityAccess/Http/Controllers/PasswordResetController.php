<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\IdentityAccess\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwords) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $this->passwords->sendResetLink($data['email']);

        // Réponse identique que le compte existe ou non.
        return response()->json(['data' => [
            'message' => __('Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.'),
        ]], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $this->passwords->reset($data['email'], $data['token'], $data['password']);

        return response()->json(['data' => [
            'message' => __('Votre mot de passe a été modifié. Vous pouvez vous connecter.'),
        ]]);
    }
}
