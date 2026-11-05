<?php

namespace App\Modules\IdentityAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\IdentityAccess\Enums\KycDocumentType;
use App\Modules\IdentityAccess\Http\Resources\KycStatusResource;
use App\Modules\IdentityAccess\Models\KycSubmission;
use App\Modules\IdentityAccess\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vérification d'identité du joueur connecté (V2-C03) : statut, envoi d'un dossier. */
class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function show(Request $request): KycStatusResource
    {
        $user = $request->user();

        return new KycStatusResource($user, KycSubmission::where('user_id', $user->id)->latest('id')->first());
    }

    public function store(Request $request): JsonResponse
    {
        $max = (int) config('kyc.max_file_kilobytes');
        $document = ['file', 'mimes:'.implode(',', config('kyc.document_mimes')), "max:{$max}"];

        $data = $request->validate([
            'document_type' => ['required', Rule::enum(KycDocumentType::class)],
            'front' => ['required', ...$document],
            'back' => ['nullable', ...$document],
            'selfie' => ['required', 'file', 'mimes:'.implode(',', config('kyc.selfie_mimes')), "max:{$max}"],
        ]);

        $submission = $this->kyc->submit(
            $request->user(),
            KycDocumentType::from($data['document_type']),
            $request->file('front'),
            $request->file('back'),
            $request->file('selfie'),
        );

        return (new KycStatusResource($request->user()->refresh(), $submission))->response()->setStatusCode(201);
    }
}
