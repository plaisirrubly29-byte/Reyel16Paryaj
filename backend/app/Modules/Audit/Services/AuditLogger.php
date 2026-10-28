<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Point d'entrée unique du journal d'audit, utilisable par tous les modules.
 *
 * Appeler dans la même transaction que l'action auditée : si l'action échoue, rien n'est journalisé ;
 * si la journalisation échoue, l'action est annulée.
 *
 *   $audit->record(AuditAction::UserSuspended, $user, before: [...], after: [...], reason: $reason, actor: $admin);
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidArgumentException si l'action exige un motif et qu'il est absent
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?User $actor = null,
        array $metadata = [],
    ): AuditLog {
        $reason = $reason !== null ? trim($reason) : null;

        if ($action->requiresReason() && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException("Motif obligatoire pour l'action {$action->value}.");
        }

        $actor ??= $this->request->user();

        return AuditLog::create([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'reason' => $reason ?: null,
            'metadata' => $metadata ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'request_id' => $this->request->headers->get('X-Request-Id'),
        ]);
    }
}
