<?php

namespace Tests\Feature\Modules\Complaints;

use App\Models\User;
use App\Modules\Complaints\Enums\ComplaintActorType;
use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintMessageVisibility;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Models\ComplaintAttachment;
use App\Modules\Complaints\Models\ComplaintEvent;
use App\Modules\Complaints\Models\ComplaintMessage;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\Wallet\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Feature\Modules\Betting\BuildsBetTickets;
use Tests\TestCase;

/**
 * V2-G04, côté joueur : ouverture numérotée, lien vers ses propres tickets ou écritures, pièces chiffrées, vue sans
 * notes internes ni identité de l'équipe, messages, réouverture avec motif dans le délai, limites.
 */
class PlayerComplaintTest extends TestCase
{
    use BuildsBetTickets;
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('complaints');
        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create(['name' => 'Marie Joseph']);
    }

    /** Image PNG 1×1 réelle (le conteneur n'a pas GD) ; $padding : grossir le fichier. */
    private function png(string $name = 'capture.png', int $padding = 0): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $png.Str::random(8).str_repeat('x', $padding));
    }

    private function pdf(string $name = 'releve.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /** @param  array<string, mixed>  $overrides */
    private function open(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->post('/api/v1/complaints', array_merge([
            'category' => 'bet',
            'subject' => 'Ticket non payé',
            'description' => 'Mon ticket gagnant n’a pas été payé alors que le match est terminé.',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function complaint(?User $as = null): Complaint
    {
        $id = $this->open(as: $as)->assertCreated()->json('data.id');

        return Complaint::findOrFail($id);
    }

    private function reopen(Complaint $complaint, string $reason = 'Le gain n’est toujours pas arrivé', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson("/api/v1/complaints/{$complaint->id}/reopen", ['reason' => $reason],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** Réponse de l'équipe, note interne et attribution écrites en base (l'API admin vient avec la suite de V2-G04). */
    private function staffActivity(Complaint $complaint, User $staff): ComplaintMessage
    {
        ComplaintMessage::create([
            'complaint_id' => $complaint->id, 'author_type' => ComplaintActorType::Staff, 'author_id' => $staff->id,
            'visibility' => ComplaintMessageVisibility::Player, 'body' => 'Nous vérifions votre ticket.',
        ]);
        $note = ComplaintMessage::create([
            'complaint_id' => $complaint->id, 'author_type' => ComplaintActorType::Staff, 'author_id' => $staff->id,
            'visibility' => ComplaintMessageVisibility::Internal, 'body' => 'Note interne : joueur déjà signalé.',
        ]);
        ComplaintAttachment::create([
            'complaint_id' => $complaint->id, 'complaint_message_id' => $note->id, 'path' => 'interne/piece',
            'filename' => 'interne.png', 'mime_type' => 'image/png', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64),
        ]);
        foreach ([ComplaintEventType::Assigned, ComplaintEventType::NoteAdded, ComplaintEventType::Replied] as $type) {
            ComplaintEvent::create([
                'complaint_id' => $complaint->id, 'type' => $type, 'actor_type' => ComplaintActorType::Staff,
                'actor_id' => $staff->id, 'reason' => $type === ComplaintEventType::Assigned ? 'Attribution interne' : null,
            ]);
        }

        return $note;
    }

    /** Passage en resolved (ou closed) écrit en base, comme le fera l'API admin. */
    private function resolve(Complaint $complaint, User $staff, ComplaintStatus $to = ComplaintStatus::Resolved): void
    {
        $complaint->update([
            'status' => $to, 'resolution' => 'Gain versé sur votre portefeuille.',
            'resolved_at' => now(), 'closed_at' => $to === ComplaintStatus::Closed ? now() : null,
        ]);
        ComplaintEvent::create([
            'complaint_id' => $complaint->id, 'type' => ComplaintEventType::StatusChanged, 'from_status' => ComplaintStatus::New,
            'to_status' => ComplaintStatus::Resolved, 'reason' => 'Gain versé sur votre portefeuille.',
            'actor_type' => ComplaintActorType::Staff, 'actor_id' => $staff->id,
        ]);
    }

    public function test_a_player_opens_a_numbered_complaint_linked_to_a_ticket_with_encrypted_attachments(): void
    {
        $ticket = $this->ticket($this->player);
        $year = now()->year;

        $response = $this->open([
            'bet_id' => (string) $ticket->id,
            'attachments' => [$this->png('capture écran<1>.png'), $this->pdf()],
        ])->assertCreated()
            ->assertJsonPath('data.number', sprintf('REC-%d-000001', $year))
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category', 'bet')
            ->assertJsonPath('data.link.type', 'bet')
            ->assertJsonPath('data.link.id', (string) $ticket->id)
            ->assertJsonPath('data.link.label', $ticket->reference)
            ->assertJsonPath('data.can_reopen', false)
            ->assertJsonPath('data.resolution', null)
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonPath('data.attachments.0.mime_type', 'image/png')
            ->assertJsonPath('data.attachments.0.filename', 'capture écran_1_.png')
            ->assertJsonPath('data.attachments.1.mime_type', 'application/pdf')
            ->assertJsonPath('data.messages', [])
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.type', 'created')
            ->assertJsonPath('data.history.0.to_status', 'new')
            ->assertJsonPath('data.history.0.actor.type', 'player')
            ->assertJsonPath('data.history.0.actor.name', 'Marie Joseph');

        // Fichier chiffré sur le disque privé, jamais en clair ; empreinte du fichier d'origine.
        $attachment = ComplaintAttachment::findOrFail($response->json('data.attachments.1.id'));
        $stored = (string) Storage::disk('complaints')->get($attachment->path);
        $this->assertStringNotContainsString('%PDF', $stored);
        $this->assertStringStartsWith('%PDF-1.4', Crypt::decryptString($stored));
        $this->assertSame(hash('sha256', Crypt::decryptString($stored)), $attachment->sha256);
        $this->assertArrayNotHasKey('path', $response->json('data.attachments.1'));
    }

    public function test_numbers_follow_a_sequence_per_year(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(10, 0));
        $this->open()->assertCreated()->assertJsonPath('data.number', 'REC-2026-000001');
        $this->open()->assertCreated()->assertJsonPath('data.number', 'REC-2026-000002');

        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(9, 0));
        $this->open(as: User::factory()->create())->assertCreated()->assertJsonPath('data.number', 'REC-2027-000001');
    }

    public function test_a_complaint_links_to_a_wallet_entry_of_the_player_with_a_readable_label(): void
    {
        $ledger = app(LedgerService::class);
        $entry = $ledger->creditSimulated($ledger->walletFor($this->player), Money::ofMinor(500_00, 'HTG'), 'test-credit-1');

        $this->open(['category' => 'transaction', 'ledger_entry_id' => (string) $entry->id])
            ->assertCreated()
            ->assertJsonPath('data.link.type', 'ledger_entry')
            ->assertJsonPath('data.link.id', (string) $entry->id)
            ->assertJsonPath('data.link.label', 'Crédit du '.$entry->created_at->format('d/m/Y'));
    }

    public function test_a_link_to_another_players_object_or_an_unknown_one_is_refused_the_same_way(): void
    {
        $other = User::factory()->create();
        $ledger = app(LedgerService::class);
        $otherEntry = $ledger->creditSimulated($ledger->walletFor($other), Money::ofMinor(500_00, 'HTG'), 'test-credit-2');

        foreach ([
            ['bet_id' => (string) $this->ticket($other)->id],
            ['bet_id' => '999999'],
            ['bet_id' => 'abc'],
            ['ledger_entry_id' => (string) $otherEntry->id],
            ['ledger_entry_id' => '0'],
        ] as $link) {
            $this->open($link)->assertUnprocessable()->assertJsonPath('error.code', 'complaint_link_invalid');
        }

        $this->open(['bet_id' => (string) $this->ticket($this->player)->id, 'ledger_entry_id' => '1'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, Complaint::count());
    }

    public function test_an_administrator_cannot_open_a_complaint(): void
    {
        $admin = User::factory()->withRole(RoleSlug::Support)->create();

        $this->open(as: $admin)->assertUnprocessable()->assertJsonPath('error.code', 'not_a_player');
        $this->assertSame(0, Complaint::count());
    }

    public function test_attachments_are_limited_in_number_type_and_size(): void
    {
        $this->open(['attachments' => array_map(fn ($i) => $this->png("p{$i}.png"), range(1, 6))])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments', 'error.details');

        // Le type est lu dans le contenu : une page HTML nommée .png est refusée, comme un .exe. Vrai fichier
        // temporaire : un fichier « fake » de Laravel déduirait son type de son nom.
        $html = tempnam(sys_get_temp_dir(), 'rec');
        file_put_contents($html, "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n");
        $this->open(['attachments' => [new UploadedFile($html, 'photo.png', null, null, true)]])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0', 'error.details');
        $this->open(['attachments' => [UploadedFile::fake()->createWithContent('outil.exe', 'MZ'.str_repeat("\0", 64))]])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0', 'error.details');

        $this->open(['attachments' => [$this->png('lourde.png', 5 * 1024 * 1024 + 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0', 'error.details');

        $this->open(['subject' => 'abc', 'description' => 'trop court'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'description'], 'error.details');

        $this->assertSame(0, Complaint::count());
        $this->assertSame([], Storage::disk('complaints')->allFiles());
    }

    public function test_the_stored_type_is_read_from_the_file_content(): void
    {
        // Vraie image PNG envoyée avec un nom en .jpg : acceptée (extension autorisée), enregistrée comme image/png.
        $png = tempnam(sys_get_temp_dir(), 'rec');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $this->open(['attachments' => [new UploadedFile($png, 'photo.jpg', null, null, true)]])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.mime_type', 'image/png')
            ->assertJsonPath('data.attachments.0.filename', 'photo.jpg');
    }

    public function test_a_player_lists_only_their_own_complaints_newest_first(): void
    {
        $first = $this->complaint();
        $second = $this->complaint();
        $this->complaint(User::factory()->create());
        $this->resolve($first, User::factory()->withRole(RoleSlug::Support)->create());

        $this->actingAs($this->player)->getJson('/api/v1/complaints')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $second->id)
            ->assertJsonPath('data.1.id', (string) $first->id)
            ->assertJsonMissingPath('data.0.description')
            ->assertJsonMissingPath('data.0.messages');

        $this->actingAs($this->player)->getJson('/api/v1/complaints?status=resolved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $first->id)
            ->assertJsonPath('data.0.can_reopen', true)
            ->assertJsonPath('data.0.resolution', 'Gain versé sur votre portefeuille.');
    }

    public function test_another_players_complaint_does_not_exist_for_the_player(): void
    {
        $complaint = $this->complaint(User::factory()->create());
        $attachmentId = ComplaintAttachment::create([
            'complaint_id' => $complaint->id, 'path' => 'x', 'filename' => 'x.png', 'mime_type' => 'image/png',
            'size_bytes' => 1, 'sha256' => str_repeat('0', 64),
        ])->id;

        $this->actingAs($this->player)->getJson("/api/v1/complaints/{$complaint->id}")->assertNotFound();
        $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/messages", ['body' => 'Bonjour'])->assertNotFound();
        $this->reopen($complaint)->assertNotFound();
        $this->actingAs($this->player)->get("/api/v1/complaints/{$complaint->id}/attachments/{$attachmentId}")->assertNotFound();
        $this->assertSame(0, ComplaintMessage::count());
    }

    public function test_the_player_view_hides_internal_notes_assignment_and_staff_identity(): void
    {
        $staff = User::factory()->withRole(RoleSlug::Support)->create(['name' => 'Jean Agent Support']);
        $complaint = $this->complaint();
        $note = $this->staffActivity($complaint, $staff);
        $this->resolve($complaint, $staff);

        $response = $this->actingAs($this->player)->getJson("/api/v1/complaints/{$complaint->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.author.type', 'staff')
            ->assertJsonPath('data.messages.0.author.name', 'Service client')
            ->assertJsonPath('data.messages.0.body', 'Nous vérifions votre ticket.')
            ->assertJsonCount(3, 'data.history') // created, replied, status_changed
            ->assertJsonPath('data.history.1.type', 'replied')
            ->assertJsonPath('data.history.1.actor.name', 'Service client')
            ->assertJsonPath('data.history.2.to_status', 'resolved')
            ->assertJsonPath('data.history.2.reason', 'Gain versé sur votre portefeuille.');

        $json = $response->getContent();
        $this->assertStringNotContainsString('Note interne', $json);
        $this->assertStringNotContainsString('Jean Agent Support', $json);
        $this->assertStringNotContainsString('Attribution interne', $json);
        $this->assertStringNotContainsString((string) $staff->email, $json);

        $internal = ComplaintAttachment::where('complaint_message_id', $note->id)->firstOrFail();
        $this->actingAs($this->player)->get("/api/v1/complaints/{$complaint->id}/attachments/{$internal->id}")->assertNotFound();
    }

    public function test_a_player_downloads_their_attachment_decrypted_and_never_cached(): void
    {
        $id = $this->open(['attachments' => [$this->pdf('relevé de compte.pdf')]])->assertCreated()->json('data.id');
        $attachmentId = $this->actingAs($this->player)->getJson("/api/v1/complaints/{$id}")->json('data.attachments.0.id');

        $response = $this->actingAs($this->player)->get("/api/v1/complaints/{$id}/attachments/{$attachmentId}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            // Nom avec accent encodé (RFC 6266), version ASCII à côté.
            ->assertHeader('Content-Disposition', "inline; filename=\"releve de compte.pdf\"; filename*=utf-8''relev%C3%A9%20de%20compte.pdf");
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-1.4', (string) $response->getContent());
    }

    public function test_a_player_adds_a_message_but_not_on_a_closed_complaint(): void
    {
        $staff = User::factory()->withRole(RoleSlug::Support)->create();
        $complaint = $this->complaint();

        $this->actingAs($this->player)->post("/api/v1/complaints/{$complaint->id}/messages", [
            'body' => 'Voici la capture du ticket.',
            'attachments' => [$this->png()],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.author.type', 'player')
            ->assertJsonPath('data.messages.0.author.name', 'Marie Joseph')
            ->assertJsonCount(1, 'data.messages.0.attachments')
            ->assertJsonCount(0, 'data.attachments'); // pièces de l'ouverture seulement

        // Un message sur une réclamation résolue ne la rouvre pas.
        $this->resolve($complaint, $staff);
        $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/messages", ['body' => 'Merci.'])
            ->assertCreated()->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($complaint->refresh()->awaiting_staff_since);

        $complaint->update(['status' => ComplaintStatus::Closed, 'closed_at' => now()]);
        $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/messages", ['body' => 'Encore moi.'])
            ->assertConflict()->assertJsonPath('error.code', 'complaint_closed');
        $this->assertSame(2, ComplaintMessage::count());
    }

    public function test_a_player_reopens_a_resolved_or_closed_complaint_with_a_reason_within_the_window(): void
    {
        $staff = User::factory()->withRole(RoleSlug::Support)->create();
        $complaint = $this->complaint();

        $this->reopen($complaint)->assertConflict()
            ->assertJsonPath('error.code', 'complaint_not_reopenable')
            ->assertJsonPath('error.details.status', 'new');

        $this->resolve($complaint, $staff, ComplaintStatus::Closed);
        $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/reopen", ['reason' => 'Toujours rien'])
            ->assertStatus(428);
        $this->reopen($complaint, 'abc')->assertUnprocessable()->assertJsonValidationErrors('reason', 'error.details');

        $this->travel(29)->days();
        $this->reopen($complaint)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.history.2.type', 'reopened')
            ->assertJsonPath('data.history.2.from_status', 'closed')
            ->assertJsonPath('data.history.2.to_status', 'in_progress')
            ->assertJsonPath('data.history.2.reason', 'Le gain n’est toujours pas arrivé');
    }

    public function test_reopening_is_refused_after_the_window(): void
    {
        $complaint = $this->complaint();
        $this->resolve($complaint, User::factory()->withRole(RoleSlug::Support)->create());

        $this->travel(31)->days();
        $this->actingAs($this->player)->getJson("/api/v1/complaints/{$complaint->id}")->assertJsonPath('data.can_reopen', false);
        $this->reopen($complaint)->assertUnprocessable()->assertJsonPath('error.code', 'complaint_reopen_expired');
        $this->assertSame(ComplaintStatus::Resolved, $complaint->refresh()->status);
    }

    public function test_opening_is_limited_per_player_and_per_hour_counting_only_accepted_complaints(): void
    {
        // Les envois refusés (saisie trop courte) ne consomment pas le quota.
        foreach (range(1, 3) as $i) {
            $this->open(['subject' => 'abc'])->assertUnprocessable();
        }
        foreach (range(1, 5) as $i) {
            $this->open()->assertCreated();
        }
        $this->open()->assertTooManyRequests()
            ->assertJsonPath('error.code', 'too_many_requests')
            ->assertHeader('Retry-After');
        $this->assertSame(5, Complaint::count());

        // La limite est propre à chaque joueur, et repart après une heure.
        $this->open(as: User::factory()->create())->assertCreated();
        $this->travel(61)->minutes();
        $this->open()->assertCreated();
    }

    public function test_messages_are_limited_per_player_and_per_hour(): void
    {
        $complaint = $this->complaint();

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/messages", ['body' => "Message {$i}"])
                ->assertCreated();
        }
        $this->actingAs($this->player)->postJson("/api/v1/complaints/{$complaint->id}/messages", ['body' => 'Un de trop'])
            ->assertTooManyRequests();
        $this->assertSame(20, ComplaintMessage::count());
    }

    public function test_the_history_is_append_only(): void
    {
        $event = $this->complaint()->events()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['reason' => 'réécrit']);
    }

    public function test_a_guest_has_no_access(): void
    {
        $this->getJson('/api/v1/complaints')->assertUnauthorized();
        $this->postJson('/api/v1/complaints', [])->assertUnauthorized();
    }
}
