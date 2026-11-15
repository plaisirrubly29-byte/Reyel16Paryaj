<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Resources\AdminKycSubmissionResource;
use App\Modules\IdentityAccess\Enums\KycSubmissionStatus;
use App\Modules\IdentityAccess\Models\KycFile;
use App\Modules\IdentityAccess\Models\KycSubmission;
use App\Modules\IdentityAccess\Services\KycService;
use App\Support\Http\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revue des dossiers KYC (V2-C03) : file d'attente et détail (kyc.view), pièces déchiffrées à la demande (auditées),
 * validation (kyc.approve) ou refus avec motif (kyc.reject).
 */
class AdminKycController extends Controller
{
    private const RELATIONS = ['user.profile', 'decider'];

    public function __construct(private readonly KycService $kyc) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(KycSubmissionStatus::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $submissions = KycSubmission::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            // File d'attente : le plus ancien dossier en attente d'abord.
            ->when(($filters['status'] ?? null) === KycSubmissionStatus::Pending->value, fn ($q) => $q->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AdminKycSubmissionResource::collection($submissions);
    }

    public function show(KycSubmission $submission): AdminKycSubmissionResource
    {
        return new AdminKycSubmissionResource($submission->load([...self::RELATIONS, 'files']));
    }

    /** Pièce ou selfie déchiffré ; jamais mis en cache par le navigateur. */
    public function file(Request $request, KycSubmission $submission, KycFile $file): Response
    {
        abort_unless($file->kyc_submission_id === $submission->id, 404);

        return response($this->kyc->read($request->user(), $file), 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'inline; filename="kyc-'.$submission->id.'-'.$file->kind->value.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, KycSubmission $submission): AdminKycSubmissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new AdminKycSubmissionResource($this->kyc->approve($request->user(), $submission, $data['reason'])->load([...self::RELATIONS, 'files']));
    }

    public function reject(Request $request, KycSubmission $submission): AdminKycSubmissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new AdminKycSubmissionResource($this->kyc->reject($request->user(), $submission, $data['reason'])->load([...self::RELATIONS, 'files']));
    }
}
