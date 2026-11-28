<?php

namespace App\Modules\Complaints\Services;

use App\Models\User;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Complaints\Enums\ComplaintActorType;
use App\Modules\Complaints\Enums\ComplaintCategory;
use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintLinkType;
use App\Modules\Complaints\Enums\ComplaintMessageVisibility;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Models\ComplaintAttachment;
use App\Modules\Complaints\Models\ComplaintEvent;
use App\Modules\Complaints\Models\ComplaintMessage;
use App\Modules\Wallet\Enums\LedgerEntryType;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Support\Http\ApiException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Réclamations, côté joueur (V2-G04) : ouverture numérotée, messages, réouverture, lecture des pièces jointes.
 *
 * - Numéro REC-AAAA-NNNNNN : séquence par année, tenue dans complaint_counters sous verrou (aucun doublon quand
 *   deux joueurs ouvrent une réclamation au même moment).
 * - Lien facultatif vers un ticket ou une écriture du portefeuille **du joueur** ; sinon complaint_link_invalid, sans
 *   révéler si l'objet existe. Sa référence lisible est figée à l'ouverture.
 * - Pièces jointes chiffrées par l'application (Crypt, clé APP_KEY) avant écriture sur le disque privé
 *   « complaints » ; la base garde le chemin, le nom nettoyé, le type, la taille et l'empreinte SHA-256 d'origine.
 * - Historique (complaint_events) en ajout seul ; le joueur n'y voit ni l'attribution ni les notes internes.
 */
class ComplaintService
{
    /** Libellé lisible d'une écriture du portefeuille, pour le lien d'une réclamation. */
    private const LEDGER_LABELS = [
        'simulated_credit' => 'Crédit',
        'deposit' => 'Dépôt',
        'withdrawal' => 'Retrait',
        'stake' => 'Mise',
        'payout' => 'Gain',
        'refund' => 'Remboursement',
        'reversal' => 'Correction',
    ];

    /**
     * @param  array{category: string, subject: string, description: string, bet_id?: string|null, ledger_entry_id?: string|null}  $data
     * @param  list<UploadedFile>  $files
     *
     * @throws ApiException not_a_player, complaint_link_invalid
     */
    public function open(User $player, array $data, array $files): Complaint
    {
        $this->assertPlayer($player);
        [$linkType, $linkId, $linkLabel] = $this->resolveLink($player, $data['bet_id'] ?? null, $data['ledger_entry_id'] ?? null);

        return $this->withFiles(function (array &$written) use ($player, $data, $files, $linkType, $linkId, $linkLabel) {
            $now = now();
            $complaint = Complaint::create([
                'number' => $this->nextNumber($now->year),
                'user_id' => $player->id,
                'category' => ComplaintCategory::from($data['category']),
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'status' => ComplaintStatus::New,
                'link_type' => $linkType,
                'link_id' => $linkId,
                'link_label' => $linkLabel,
                'awaiting_staff_since' => $now,
                'last_activity_at' => $now,
            ]);

            $this->storeAttachments($complaint, null, $files, $written);
            $this->recordEvent($complaint, ComplaintEventType::Created, ComplaintActorType::Player, $player,
                to: ComplaintStatus::New);

            return $complaint;
        });
    }

    /**
     * Message du joueur, avec ou sans pièces. Une réclamation resolved reste resolved (la rouvrir : reopen()).
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ApiException complaint_closed
     */
    public function addPlayerMessage(User $player, Complaint $complaint, string $body, array $files): Complaint
    {
        return $this->withFiles(function (array &$written) use ($player, $complaint, $body, $files) {
            $locked = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ComplaintStatus::Closed) {
                throw new ApiException('complaint_closed', __('Cette réclamation est fermée : rouvrez-la pour écrire.'), 409);
            }

            $message = ComplaintMessage::create([
                'complaint_id' => $locked->id,
                'author_type' => ComplaintActorType::Player,
                'author_id' => $player->id,
                'visibility' => ComplaintMessageVisibility::Player,
                'body' => trim($body),
            ]);
            $this->storeAttachments($locked, $message, $files, $written);

            $locked->update([
                'awaiting_staff_since' => $locked->awaiting_staff_since ?? now(),
                'last_activity_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Réouverture par le joueur, avec motif : resolved ou closed → in_progress, dans le délai de réouverture.
     *
     * @throws ApiException complaint_not_reopenable, complaint_reopen_expired
     */
    public function reopen(User $player, Complaint $complaint, string $reason): Complaint
    {
        return DB::transaction(function () use ($player, $complaint, $reason) {
            $locked = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isOpen()) {
                throw new ApiException('complaint_not_reopenable', __('Seule une réclamation résolue ou fermée peut être rouverte.'), 409, [
                    'status' => $locked->status->value,
                ]);
            }
            if ($locked->reopenWindowExpired()) {
                throw new ApiException('complaint_reopen_expired', __('Le délai de réouverture est passé : ouvrez une nouvelle réclamation.'), 422, [
                    'reopen_window_days' => (int) config('complaints.reopen_window_days'),
                ]);
            }

            $from = $locked->status;
            $now = now();
            $locked->update([
                'status' => ComplaintStatus::InProgress,
                'resolved_at' => null,
                'closed_at' => null,
                'awaiting_staff_since' => $now,
                'last_activity_at' => $now,
            ]);
            $this->recordEvent($locked, ComplaintEventType::Reopened, ComplaintActorType::Player, $player,
                from: $from, to: ComplaintStatus::InProgress, reason: trim($reason));

            return $locked;
        });
    }

    /** Contenu déchiffré d'une pièce jointe. Le contrôle d'accès est fait par l'appelant. */
    public function readAttachment(ComplaintAttachment $attachment): string
    {
        return Crypt::decryptString((string) $this->disk()->get($attachment->path));
    }

    /** @throws ApiException not_a_player */
    private function assertPlayer(User $user): void
    {
        if ($user->isAdmin()) {
            throw new ApiException('not_a_player', __('Les réclamations sont ouvertes par les comptes joueurs.'), 422);
        }
    }

    /**
     * @return array{0: ComplaintLinkType|null, 1: int|null, 2: string|null}
     *
     * @throws ApiException complaint_link_invalid
     */
    private function resolveLink(User $player, ?string $betId, ?string $ledgerEntryId): array
    {
        if ($betId === null && $ledgerEntryId === null) {
            return [null, null, null];
        }

        $id = $this->numericId($betId ?? $ledgerEntryId);

        if ($betId !== null) {
            $ticket = $id === null ? null : BetTicket::whereKey($id)->where('user_id', $player->id)->first();
            if ($ticket !== null) {
                return [ComplaintLinkType::Bet, $ticket->id, $ticket->reference];
            }
        } else {
            $entry = $id === null ? null : LedgerEntry::whereKey($id)
                ->whereHas('wallet', fn ($q) => $q->where('user_id', $player->id))
                ->first();
            if ($entry !== null) {
                return [ComplaintLinkType::LedgerEntry, $entry->id, $this->ledgerLabel($entry)];
            }
        }

        // Même réponse pour un objet inconnu ou celui d'un autre joueur : rien n'est révélé.
        throw new ApiException('complaint_link_invalid', __('Ce ticket ou cette transaction ne fait pas partie de votre compte.'), 422);
    }

    private function numericId(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,17}$/', $value) === 1 ? (int) $value : null;
    }

    private function ledgerLabel(LedgerEntry $entry): string
    {
        $type = $entry->type instanceof LedgerEntryType ? $entry->type->value : (string) $entry->type;

        return (self::LEDGER_LABELS[$type] ?? 'Écriture').' du '.$entry->created_at->format('d/m/Y');
    }

    /** Numéro suivant de l'année, sous verrou de la ligne du compteur (appelé dans une transaction). */
    private function nextNumber(int $year): string
    {
        DB::table('complaint_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $last = (int) DB::table('complaint_counters')->where('year', $year)->lockForUpdate()->value('last_number');
        DB::table('complaint_counters')->where('year', $year)->update(['last_number' => $last + 1]);

        return sprintf('REC-%04d-%06d', $year, $last + 1);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $written
     */
    private function storeAttachments(Complaint $complaint, ?ComplaintMessage $message, array $files, array &$written): void
    {
        foreach ($files as $upload) {
            $contents = (string) file_get_contents($upload->getRealPath());
            $path = "{$complaint->user_id}/{$complaint->id}/".Str::uuid();
            $this->disk()->put($path, Crypt::encryptString($contents));
            $written[] = $path;

            ComplaintAttachment::create([
                'complaint_id' => $complaint->id,
                'complaint_message_id' => $message?->id,
                'path' => $path,
                'filename' => $this->cleanFilename($upload->getClientOriginalName()),
                'mime_type' => (string) $upload->getMimeType(),
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ]);
        }
    }

    /** Nom affiché seulement : sans chemin, sans caractères de contrôle ni guillemets, 150 caractères au plus. */
    private function cleanFilename(string $name): string
    {
        $name = (string) preg_replace('/[^\pL\pN._ -]+/u', '_', basename(str_replace('\\', '/', $name)));
        $name = trim($name, ' ._');

        return Str::limit($name === '' ? 'piece-jointe' : $name, 150, '');
    }

    private function recordEvent(
        Complaint $complaint,
        ComplaintEventType $type,
        ComplaintActorType $actorType,
        ?User $actor,
        ?ComplaintStatus $from = null,
        ?ComplaintStatus $to = null,
        ?string $reason = null,
    ): ComplaintEvent {
        return ComplaintEvent::create([
            'complaint_id' => $complaint->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * Transaction qui écrit des fichiers chiffrés : si elle est annulée, aucun fichier ne reste sans réclamation.
     *
     * @template T
     *
     * @param  callable(list<string>&): T  $callback
     * @return T
     */
    private function withFiles(callable $callback): mixed
    {
        $written = [];

        try {
            return DB::transaction(function () use ($callback, &$written) {
                return $callback($written);
            });
        } catch (Throwable $e) {
            foreach ($written as $path) {
                $this->disk()->delete($path);
            }

            throw $e;
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('complaints.disk'));
    }
}
