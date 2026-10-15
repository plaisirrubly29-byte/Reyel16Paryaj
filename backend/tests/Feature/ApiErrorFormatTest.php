<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\Pagination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/_test')->middleware('api')->group(function () {
            Route::post('/validation', fn (Request $r) => $r->validate(['email' => ['required', 'email']]));
            Route::get('/domain', fn () => throw new ApiException('odds_changed', 'La cote a changé.', 409, ['odds' => '2.15']));
            Route::get('/forbidden', fn () => throw new AuthorizationException('secret interne'));
            Route::get('/crash', fn () => throw new RuntimeException('SQLSTATE secret'));
            Route::get('/users/{user}', fn (User $user) => $user);
            Route::get('/per-page', fn (Request $r) => ['per_page' => Pagination::perPage($r)]);
        });
    }

    public function test_validation_errors_use_the_unique_format(): void
    {
        $this->postJson('/api/v1/_test/validation', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email']]]);
    }

    public function test_domain_exception_exposes_code_and_details(): void
    {
        $this->getJson('/api/v1/_test/domain')
            ->assertStatus(409)
            ->assertExactJson(['error' => [
                'code' => 'odds_changed',
                'message' => 'La cote a changé.',
                'details' => ['odds' => '2.15'],
            ]]);
    }

    public function test_unknown_route_returns_not_found(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_missing_model_does_not_leak_model_name(): void
    {
        $response = $this->getJson('/api/v1/_test/users/999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertStringNotContainsString('Models', $response->json('error.message'));
    }

    public function test_authorization_errors_hide_internal_message(): void
    {
        $response = $this->getJson('/api/v1/_test/forbidden')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertStringNotContainsString('secret', $response->json('error.message'));
    }

    public function test_server_errors_hide_internal_message_outside_debug(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/crash')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_method_not_allowed_uses_the_unique_format(): void
    {
        $this->deleteJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_per_page_is_bounded(): void
    {
        $this->getJson('/api/v1/_test/per-page')->assertJson(['per_page' => Pagination::DEFAULT_PER_PAGE]);
        $this->getJson('/api/v1/_test/per-page?per_page=5')->assertJson(['per_page' => 5]);
        $this->getJson('/api/v1/_test/per-page?per_page=5000')->assertJson(['per_page' => Pagination::MAX_PER_PAGE]);
        $this->getJson('/api/v1/_test/per-page?per_page=abc')->assertJson(['per_page' => Pagination::DEFAULT_PER_PAGE]);
    }
}
