<?php

namespace Tests\Feature\Modules\IdentityAccess;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\IdentityAccess\Enums\KycStatus;
use App\Modules\IdentityAccess\Enums\RoleSlug;
use App\Modules\IdentityAccess\Models\KycFile;
use App\Modules\IdentityAccess\Models\KycSubmission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V2-C03 : dossier KYC (pièce + selfie) chiffré, revue par un administrateur, retrait et gros dépôts bloqués sans KYC.
 */
class KycTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc');
        $this->seed(PermissionSeeder::class);
        $this->player = User::factory()->create();
    }

    /** Image PNG 1×1 réelle (le conteneur n'a pas GD pour UploadedFile::fake()->image()) ; $padding : grossir le fichier. */
    private function upload(string $name = 'photo.png', int $padding = 0): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $png.Str::random(8).str_repeat('x', $padding));
    }

    /** @param  array<string, mixed>  $overrides */
    private function submit(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player->refresh())->post('/api/v1/me/kyc', array_merge([
            'document_type' => 'cin',
            'front' => $this->upload('recto.png'),
            'back' => $this->upload('verso.png'),
            'selfie' => $this->upload('selfie.png'),
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function admin(RoleSlug $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    private function decide(User $admin, KycSubmission $submission, string $decision, string $reason = 'Pièce lisible, photo conforme'): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin)->postJson("/api/v1/admin/kyc/{$submission->id}/{$decision}", ['reason' => $reason],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function walletOperation(User $admin, string $type, int $amountMinor): TestResponse
    {
        return $this->actingAsVerifiedAdmin($admin)->postJson("/api/v1/admin/users/{$this->player->id}/wallet/adjustments",
            ['type' => $type, 'amount_minor' => $amountMinor, 'reason' => 'Versement reçu au guichet'],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_player_submits_an_id_and_a_selfie_stored_encrypted(): void
    {
        $this->actingAs($this->player)->getJson('/api/v1/me/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.latest_submission', null)
            ->assertJsonPath('data.deposit_threshold.minor', 10_000_00);

        $front = $this->upload('recto.png');
        $original = (string) file_get_contents($front->getRealPath());

        $this->submit(['front' => $front])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.latest_submission.document_type', 'cin')
            ->assertJsonPath('data.latest_submission.status', 'pending');

        $this->assertSame(KycStatus::Pending, $this->player->fresh()->kyc_status);
        $submission = KycSubmission::sole();
        $this->assertSame(['back', 'front', 'selfie'], $submission->files()->orderBy('kind')->pluck('kind')->map->value->all());

        // Sur le disque : jamais le fichier en clair, seulement son chiffrement.
        $file = $submission->files()->where('kind', 'front')->sole();
        $stored = Storage::disk('kyc')->get($file->path);
        $this->assertNotSame($original, $stored);
        $this->assertStringNotContainsString(substr($original, 0, 64), $stored);
        $this->assertSame($original, Crypt::decryptString($stored));
        $this->assertSame(hash('sha256', $original), $file->sha256);

        $this->assertSame(1, AuditLog::where('action', 'kyc.submitted')->where('actor_id', $this->player->id)->count());

        // Pas de second dossier tant que le premier est en attente.
        $this->submit()->assertStatus(409)->assertJsonPath('error.code', 'kyc_already_pending');
    }

    public function test_the_submission_is_validated(): void
    {
        $this->submit(['document_type' => 'cin', 'back' => null])->assertStatus(422)->assertJsonPath('error.code', 'kyc_back_required');
        $this->submit(['document_type' => 'carte_electorale'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->submit(['selfie' => UploadedFile::fake()->create('selfie.pdf', 100, 'application/pdf')])->assertStatus(422);
        $this->submit(['front' => $this->upload('recto.png', 6_000 * 1024)])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['front']]]);

        // Passeport : la page photo suffit, pas de verso.
        $this->submit(['document_type' => 'passport', 'back' => null])->assertCreated();
        $this->assertSame(['front', 'selfie'], KycSubmission::sole()->files()->orderBy('kind')->pluck('kind')->map->value->all());
        $this->assertCount(2, Storage::disk('kyc')->allFiles());
    }

    public function test_support_approves_and_the_reviewer_reads_the_documents_with_an_audit_trail(): void
    {
        $this->submit()->assertCreated();
        $submission = KycSubmission::sole();
        $support = $this->admin(RoleSlug::Support);

        $this->actingAsVerifiedAdmin($support)->getJson('/api/v1/admin/kyc?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $submission->id)
            ->assertJsonPath('data.0.user.id', (string) $this->player->id);

        $detail = $this->actingAsVerifiedAdmin($support)->getJson("/api/v1/admin/kyc/{$submission->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.files')
            ->assertJsonMissingPath('data.files.0.path');

        $fileId = collect($detail->json('data.files'))->firstWhere('kind', 'selfie')['id'];
        $response = $this->actingAsVerifiedAdmin($support)->get("/api/v1/admin/kyc/{$submission->id}/files/{$fileId}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(KycFile::find($fileId)->sha256, hash('sha256', $response->getContent()));
        $this->assertSame(1, AuditLog::where('action', 'kyc.document_viewed')->count());

        // Fichier d'un autre dossier : introuvable par ce chemin.
        $this->actingAsVerifiedAdmin($support)->get("/api/v1/admin/kyc/999/files/{$fileId}")->assertNotFound();

        $this->decide($support, $submission, 'approve', 'abc')->assertStatus(422);
        $this->decide($support, $submission, 'approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', (string) $support->id);

        $player = $this->player->fresh();
        $this->assertSame(KycStatus::Approved, $player->kyc_status);
        $this->assertNotNull($player->kyc_verified_at);
        $audit = AuditLog::where('action', 'kyc.approved')->sole();
        $this->assertSame('Pièce lisible, photo conforme', $audit->reason);
        $this->assertSame($support->id, $audit->actor_id);

        $this->decide($this->admin(RoleSlug::Compliance), $submission, 'reject', 'Trop tard pour refuser')
            ->assertStatus(409)->assertJsonPath('error.code', 'kyc_not_pending');
        $this->submit()->assertStatus(409)->assertJsonPath('error.code', 'kyc_already_approved');
    }

    public function test_only_compliance_rejects_and_the_player_sees_the_reason_and_can_resubmit(): void
    {
        $this->submit()->assertCreated();
        $submission = KycSubmission::sole();

        $this->decide($this->admin(RoleSlug::Support), $submission, 'reject', 'Photo floue')->assertForbidden();
        $this->decide($this->admin(RoleSlug::Operations), $submission, 'approve')->assertForbidden();
        $this->actingAsVerifiedAdmin($this->admin(RoleSlug::Finance))->getJson('/api/v1/admin/kyc')->assertForbidden();

        $this->decide($this->admin(RoleSlug::Compliance), $submission, 'reject', 'Photo de la pièce floue, nom illisible')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($this->player->refresh())->getJson('/api/v1/me/kyc')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.latest_submission.rejection_reason', 'Photo de la pièce floue, nom illisible');

        $this->submit()->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame(2, KycSubmission::count());
    }

    public function test_withdrawals_need_a_verified_identity_and_deposits_are_free_up_to_the_threshold(): void
    {
        $super = $this->admin(RoleSlug::SuperAdmin);

        // Dépôts libres jusqu'à 10 000 HTG cumulés.
        $this->walletOperation($super, 'deposit', 6_000_00)->assertCreated();
        $this->walletOperation($super, 'deposit', 4_000_00)->assertCreated();
        $this->walletOperation($super, 'deposit', 1_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'kyc_required')
            ->assertJsonPath('error.details.deposited_minor', 10_000_00);

        $this->walletOperation($super, 'withdrawal', 100_00)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'kyc_required')
            ->assertJsonPath('error.details.kyc_status', 'none');

        $this->submit()->assertCreated();
        $this->walletOperation($super, 'withdrawal', 100_00)->assertStatus(422)->assertJsonPath('error.details.kyc_status', 'pending');

        $this->decide($this->admin(RoleSlug::Compliance), KycSubmission::sole(), 'approve')->assertOk();

        $this->walletOperation($super, 'withdrawal', 100_00)->assertCreated();
        $this->walletOperation($super, 'deposit', 50_000_00)->assertCreated();
    }

    public function test_admins_do_not_submit_a_kyc_file(): void
    {
        $this->submit(as: $this->admin(RoleSlug::Support))->assertStatus(422)->assertJsonPath('error.code', 'kyc_not_for_admins');
        $this->assertSame([], Storage::disk('kyc')->allFiles());
    }
}
