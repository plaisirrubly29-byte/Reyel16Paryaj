<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Bilan de santé du 27/11/2026 : un refus métier (ApiException 4xx) est une réponse normale, pas une erreur à
 * journaliser ; une ApiException 5xx et toute autre exception restent journalisées.
 */
class ApiExceptionReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_refusals_are_not_reported_but_server_errors_are(): void
    {
        $handler = app(ExceptionHandler::class);

        foreach ([400, 403, 404, 409, 422, 428, 429] as $status) {
            $this->assertFalse($handler->shouldReport(new ApiException('refus', 'Refus métier.', $status)), "ApiException {$status}");
        }
        $this->assertTrue($handler->shouldReport(new ApiException('provider_down', 'Prestataire indisponible.', 503)));
        $this->assertTrue($handler->shouldReport(new \RuntimeException('Panne inattendue')));
    }

    public function test_a_refused_request_writes_nothing_to_the_log_and_keeps_its_error_format(): void
    {
        Log::spy();

        // Sans clé d'idempotence : 428 precondition_required (ApiException levée par le middleware).
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/bets', [])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'precondition_required');

        Log::shouldNotHaveReceived('error');
    }

    public function test_a_server_error_is_still_written_to_the_log(): void
    {
        Route::get('/api/v1/__test-panne', fn () => throw new ApiException('provider_down', 'Prestataire indisponible.', 503));
        Log::spy();

        $this->getJson('/api/v1/__test-panne')->assertStatus(503)->assertJsonPath('error.code', 'provider_down');

        Log::shouldHaveReceived('error')->once();
    }
}
