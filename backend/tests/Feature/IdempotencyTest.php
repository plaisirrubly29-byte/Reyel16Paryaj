<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('_test_orders', function ($table) {
            $table->id();
            $table->integer('amount');
        });

        Route::prefix('api/v1/_test')->middleware(['api', 'idempotent'])->group(function () {
            Route::post('/orders', function (Request $request) {
                $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);
                $id = DB::table('_test_orders')->insertGetId($data);

                return response()->json(['data' => ['id' => $id, 'amount' => $data['amount']]], 201);
            });
            Route::post('/crash', fn () => throw new RuntimeException('boom'));
        });
    }

    private function send(string $key, array $body = ['amount' => 100], string $uri = '/api/v1/_test/orders')
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => $key]);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->postJson('/api/v1/_test/orders', ['amount' => 100])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'precondition_required');

        $this->assertSame(0, DB::table('_test_orders')->count());
    }

    public function test_key_must_be_a_uuid(): void
    {
        $this->send('not-a-uuid')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_invalid');
    }

    public function test_replayed_request_returns_same_response_without_creating_anything(): void
    {
        $key = (string) Str::uuid();

        $first = $this->send($key)->assertCreated();
        $second = $this->send($key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, DB::table('_test_orders')->count());
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $this->send($key, ['amount' => 100])->assertCreated();
        $this->send($key, ['amount' => 999])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertSame(1, DB::table('_test_orders')->count());
    }

    public function test_payload_key_order_does_not_change_the_fingerprint(): void
    {
        $key = (string) Str::uuid();

        $this->send($key, ['amount' => 100, 'note' => 'x'])->assertCreated();
        $this->send($key, ['note' => 'x', 'amount' => 100])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_concurrent_request_with_same_key_is_rejected_while_first_is_processing(): void
    {
        $key = (string) Str::uuid();

        // Simule une première requête encore en cours (ligne réservée, pas encore terminée).
        $this->send($key)->assertCreated();
        IdempotencyKey::query()->update(['status' => IdempotencyKey::STATUS_PROCESSING]);

        $this->send($key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_request_in_progress')
            ->assertHeader('Retry-After');

        $this->assertSame(1, DB::table('_test_orders')->count());
    }

    public function test_stale_processing_key_can_be_taken_over(): void
    {
        $key = (string) Str::uuid();

        $this->send($key)->assertCreated();
        IdempotencyKey::query()->update([
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'created_at' => now()->subHour(),
        ]);

        $this->send($key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_failed_request_releases_the_key(): void
    {
        $key = (string) Str::uuid();

        $this->send($key, ['amount' => 0])->assertStatus(422);
        $this->assertSame(0, IdempotencyKey::count());

        // Le client corrige sa requête et réessaie avec la même clé.
        $this->send($key, ['amount' => 100])->assertCreated();
        $this->assertSame(1, DB::table('_test_orders')->count());
    }

    public function test_exception_releases_the_key(): void
    {
        $key = (string) Str::uuid();

        $this->send($key, [], '/api/v1/_test/crash')->assertStatus(500);

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $key = (string) Str::uuid();
        [$alice, $bob] = User::factory()->count(2)->create();

        $this->actingAs($alice)->send($key)->assertCreated();
        $this->actingAs($bob)->send($key)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, DB::table('_test_orders')->count());
    }

    public function test_expired_keys_are_pruned(): void
    {
        $this->send((string) Str::uuid())->assertCreated();
        IdempotencyKey::query()->update(['created_at' => now()->subHours(IdempotencyKey::RETENTION_HOURS + 1)]);
        $this->send((string) Str::uuid())->assertCreated();

        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

        $this->assertSame(1, IdempotencyKey::count());
    }
}
