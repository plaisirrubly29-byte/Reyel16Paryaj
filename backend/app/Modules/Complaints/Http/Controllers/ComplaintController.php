<?php

namespace App\Modules\Complaints\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Complaints\Enums\ComplaintCategory;
use App\Modules\Complaints\Enums\ComplaintMessageVisibility;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Http\Resources\ComplaintDetailResource;
use App\Modules\Complaints\Http\Resources\ComplaintResource;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Models\ComplaintAttachment;
use App\Modules\Complaints\Services\ComplaintService;
use App\Support\Http\Pagination;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Réclamations du joueur connecté (V2-G04) : liste, ouverture, détail, messages, réouverture, pièces jointes.
 * La réclamation d'un autre joueur répond 404, comme une réclamation inexistante.
 */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(ComplaintStatus::class)]]);

        $complaints = Complaint::where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ComplaintResource::collection($complaints);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::enum(ComplaintCategory::class)],
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'bet_id' => ['nullable', 'string', 'max:20', 'prohibits:ledger_entry_id'],
            'ledger_entry_id' => ['nullable', 'string', 'max:20'],
            ...$this->attachmentRules(),
        ]);

        $complaint = $this->limited('complaints', (int) config('complaints.max_per_hour'), $request->user(),
            fn () => $this->complaints->open($request->user(), $data, $this->uploads($request)));

        return $this->detail($complaint)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $complaint): ComplaintDetailResource
    {
        return $this->detail($this->owned($request->user(), $complaint));
    }

    public function storeMessage(Request $request, string $complaint): JsonResponse
    {
        $owned = $this->owned($request->user(), $complaint);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            ...$this->attachmentRules(),
        ]);

        $updated = $this->limited('complaint-messages', (int) config('complaints.max_messages_per_hour'), $request->user(),
            fn () => $this->complaints->addPlayerMessage($request->user(), $owned, $data['body'], $this->uploads($request)));

        return $this->detail($updated)->response()->setStatusCode(201);
    }

    public function reopen(Request $request, string $complaint): ComplaintDetailResource
    {
        $owned = $this->owned($request->user(), $complaint);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->detail($this->complaints->reopen($request->user(), $owned, $data['reason']));
    }

    /** Pièce déchiffrée ; celles des notes internes de l'équipe n'existent pas pour le joueur (404). */
    public function attachment(Request $request, string $complaint, string $attachment): Response
    {
        $owned = $this->owned($request->user(), $complaint);
        $file = ComplaintAttachment::where('complaint_id', $owned->id)->with('message')->findOrFail($attachment);
        abort_if($file->message !== null && $file->message->visibility !== ComplaintMessageVisibility::Player, 404);

        return response($this->complaints->readAttachment($file), 200, [
            'Content-Type' => $file->mime_type,
            // Nom avec accents encodé (RFC 6266), avec une version ASCII pour les anciens navigateurs.
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $file->filename, Str::ascii($file->filename)),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Limite par joueur et par heure (contrat : 5 réclamations, 20 messages). Seuls les envois acceptés comptent : une
     * erreur de saisie ou un fichier refusé ne consomme pas le quota du joueur.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     *
     * @throws ThrottleRequestsException
     */
    private function limited(string $name, int $maxPerHour, User $player, Closure $action): mixed
    {
        $key = "{$name}:{$player->id}";
        if (RateLimiter::tooManyAttempts($key, $maxPerHour)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(headers: ['Retry-After' => $retryAfter, 'X-RateLimit-Limit' => $maxPerHour]);
        }

        $result = $action();
        RateLimiter::hit($key, 3600);

        return $result;
    }

    private function owned(User $player, string $id): Complaint
    {
        return Complaint::where('user_id', $player->id)->findOrFail($id);
    }

    private function detail(Complaint $complaint): ComplaintDetailResource
    {
        return new ComplaintDetailResource($complaint->fresh(ComplaintDetailResource::RELATIONS));
    }

    /** @return array<string, list<mixed>> */
    private function attachmentRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.(int) config('complaints.max_attachments')],
            'attachments.*' => [
                'file',
                'extensions:'.implode(',', config('complaints.attachment_extensions')),
                // Type lu dans le contenu du fichier : une page HTML renommée en .png est refusée.
                'mimetypes:'.implode(',', config('complaints.attachment_mime_types')),
                'max:'.(int) config('complaints.max_file_kilobytes'),
            ],
        ];
    }

    /** @return list<UploadedFile> */
    private function uploads(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments', []), fn ($f) => $f instanceof UploadedFile));
    }
}
