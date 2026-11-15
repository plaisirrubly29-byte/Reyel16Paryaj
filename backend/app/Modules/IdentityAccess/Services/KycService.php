<?php

namespace App\Modules\IdentityAccess\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\IdentityAccess\Enums\KycDocumentType;
use App\Modules\IdentityAccess\Enums\KycFileKind;
use App\Modules\IdentityAccess\Enums\KycStatus;
use App\Modules\IdentityAccess\Enums\KycSubmissionStatus;
use App\Modules\IdentityAccess\Models\KycFile;
use App\Modules\IdentityAccess\Models\KycSubmission;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * KYC (V2-C03) : dossier du joueur (pièce d'identité + selfie), décision d'un administrateur, et contrôles avant
 * l'argent réel. Seul chemin qui modifie users.kyc_status.
 *
 * - Fichiers chiffrés par l'application (Crypt, clé APP_KEY) avant écriture sur le disque privé « kyc » ; la base
 *   garde le chemin, le type, la taille et l'empreinte SHA-256 du fichier d'origine. Chaque ouverture par un
 *   administrateur est auditée (kyc.document_viewed).
 * - Décision : valider (kyc.approve : Super Admin, Conformité, Support) ou refuser avec motif (kyc.reject : Super Admin,
 *   Conformité). Après un refus, le joueur envoie un nouveau dossier.
 * - Contrôles : tout retrait exige un KYC validé ; les dépôts sont libres jusqu'à kyc.deposit_threshold_minor cumulés.
 */
class KycService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @throws ApiException kyc_not_for_admins, kyc_already_pending, kyc_already_approved, kyc_back_required
     */
    public function submit(User $player, KycDocumentType $type, UploadedFile $front, ?UploadedFile $back, UploadedFile $selfie): KycSubmission
    {
        if ($player->isAdmin()) {
            throw new ApiException('kyc_not_for_admins', __('La vérification d\'identité concerne les comptes joueurs.'), 422);
        }
        if ($type->requiresBack() && $back === null) {
            throw new ApiException('kyc_back_required', __('Envoyez aussi le verso de la pièce.'), 422, ['document_type' => $type->value]);
        }

        $files = array_filter([
            KycFileKind::Front->value => $front,
            KycFileKind::Back->value => $type->requiresBack() ? $back : null,
            KycFileKind::Selfie->value => $selfie,
        ]);
        $written = [];

        try {
            return DB::transaction(function () use ($player, $type, $files, &$written) {
                // Verrou sur le compte : deux envois simultanés ne créent pas deux dossiers en attente.
                $locked = User::whereKey($player->id)->lockForUpdate()->firstOrFail();
                match ($locked->kyc_status) {
                    KycStatus::Pending => throw new ApiException('kyc_already_pending', __('Votre dossier est en cours de vérification.'), 409),
                    KycStatus::Approved => throw new ApiException('kyc_already_approved', __('Votre identité est déjà vérifiée.'), 409),
                    default => null,
                };

                $submission = KycSubmission::create(['user_id' => $locked->id, 'document_type' => $type]);

                foreach ($files as $kind => $upload) {
                    $contents = (string) file_get_contents($upload->getRealPath());
                    $path = "{$locked->id}/{$submission->id}/{$kind}-".Str::uuid();
                    $this->disk()->put($path, Crypt::encryptString($contents));
                    $written[] = $path;

                    $submission->files()->create([
                        'kind' => $kind,
                        'path' => $path,
                        'mime_type' => (string) $upload->getMimeType(),
                        'size_bytes' => strlen($contents),
                        'sha256' => hash('sha256', $contents),
                    ]);
                }

                $locked->forceFill(['kyc_status' => KycStatus::Pending])->save();
                $this->audit->record(AuditAction::KycSubmitted, $submission,
                    after: ['document_type' => $type->value, 'files' => array_keys($files)], actor: $locked);

                return $submission->load('files');
            });
        } catch (Throwable $e) {
            // Transaction annulée : aucun fichier chiffré ne doit rester sans dossier.
            foreach ($written as $path) {
                $this->disk()->delete($path);
            }

            throw $e;
        }
    }

    /** @throws ApiException kyc_not_pending */
    public function approve(User $actor, KycSubmission $submission, string $reason): KycSubmission
    {
        return $this->decide($actor, $submission, KycSubmissionStatus::Approved, $reason);
    }

    /** @throws ApiException kyc_not_pending */
    public function reject(User $actor, KycSubmission $submission, string $reason): KycSubmission
    {
        return $this->decide($actor, $submission, KycSubmissionStatus::Rejected, $reason);
    }

    /**
     * Contenu déchiffré d'une pièce ou du selfie, pour un administrateur kyc.view ; ouverture auditée.
     */
    public function read(User $actor, KycFile $file): string
    {
        if (! $actor->hasPermission('kyc.view')) {
            throw new AuthorizationException;
        }

        $contents = Crypt::decryptString((string) $this->disk()->get($file->path));
        $this->audit->record(AuditAction::KycDocumentViewed, $file->submission,
            actor: $actor, metadata: ['file' => $file->kind->value, 'user_id' => $file->submission->user_id]);

        return $contents;
    }

    /** @throws ApiException kyc_required */
    public function assertCanWithdraw(User $player): void
    {
        if ($player->kyc_status !== KycStatus::Approved) {
            throw new ApiException('kyc_required', __('Un retrait exige une identité vérifiée.'), 422, [
                'kyc_status' => $player->kyc_status->value,
            ]);
        }
    }

    /** @throws ApiException kyc_required */
    public function assertCanDeposit(User $player, int $amountMinor): void
    {
        if ($player->kyc_status === KycStatus::Approved) {
            return;
        }

        $threshold = (int) config('kyc.deposit_threshold_minor');
        $deposited = (int) LedgerEntry::where('wallet_id', $this->ledger->walletFor($player)->id)
            ->where('type', LedgerEntryType::Deposit)
            ->sum('amount_minor');

        if ($deposited + $amountMinor > $threshold) {
            throw new ApiException('kyc_required', __('Au-delà de ce montant de dépôts, une identité vérifiée est exigée.'), 422, [
                'kyc_status' => $player->kyc_status->value,
                'threshold_minor' => $threshold,
                'deposited_minor' => $deposited,
            ]);
        }
    }

    private function decide(User $actor, KycSubmission $submission, KycSubmissionStatus $decision, string $reason): KycSubmission
    {
        $permission = $decision === KycSubmissionStatus::Approved ? 'kyc.approve' : 'kyc.reject';
        if (! $actor->hasPermission($permission) || $actor->id === $submission->user_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $submission, $decision, $reason) {
            $locked = KycSubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== KycSubmissionStatus::Pending) {
                throw new ApiException('kyc_not_pending', __('Ce dossier a déjà été traité.'), 409, ['status' => $locked->status->value]);
            }

            $locked->update([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            $approved = $decision === KycSubmissionStatus::Approved;
            $locked->user->forceFill([
                'kyc_status' => $approved ? KycStatus::Approved : KycStatus::Rejected,
                'kyc_verified_at' => $approved ? now() : null,
            ])->save();

            $this->audit->record($approved ? AuditAction::KycApproved : AuditAction::KycRejected, $locked,
                before: ['status' => KycSubmissionStatus::Pending->value],
                after: ['status' => $decision->value],
                reason: $reason,
                actor: $actor,
                metadata: ['user_id' => $locked->user_id],
            );

            return $locked;
        });
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('kyc.disk'));
    }
}
