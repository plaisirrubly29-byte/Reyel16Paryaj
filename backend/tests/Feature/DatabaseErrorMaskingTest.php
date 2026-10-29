<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\QueryErrorSanitizer;
use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Revue P8-02, M5 : un message d'erreur SQL (écrit dans laravel.log, renvoyé si APP_DEBUG=true)
 * ne contient pas les valeurs de la requête.
 */
class DatabaseErrorMaskingTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_connection_masks_query_values(): void
    {
        $this->assertTrue(config('database.connections.pgsql.mask_bindings_in_exception_messages'));
    }

    public function test_a_refused_insert_does_not_expose_its_values(): void
    {
        $existing = User::factory()->create(['phone' => '34123456']);

        try {
            // Doublon de téléphone, comme deux inscriptions simultanées avec le même numéro.
            DB::table('users')->insert([
                'name' => 'Nom Confidentiel',
                'phone' => $existing->phone,
                'email' => 'secret@example.com',
                'password' => 'hash-du-mot-de-passe',
            ]);
            $this->fail('L\'insertion aurait dû être refusée.');
        } catch (QueryException $e) {
            foreach (['Nom Confidentiel', 'secret@example.com', 'hash-du-mot-de-passe'] as $value) {
                $this->assertStringNotContainsString($value, $e->getMessage());
            }
        }
    }

    /** Erreur telle que PostgreSQL la renvoie : la ligne DETAIL cite toute la ligne refusée. */
    private function postgresError(string $class = QueryException::class): QueryException
    {
        $pdo = new PDOException("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains (7, null, 34123456, secret@example.com, \$2y\$12\$hash-du-mot-de-passe).");
        $pdo->errorInfo = ['23502', 7, "ERROR:  null value in column \"name\"\nDETAIL:  Failing row contains (7, null, 34123456, secret@example.com)."];
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23502');

        return new $class('pgsql', 'insert into "users" ("name", "phone") values (?, ?)', [null, '34123456'], $pdo, [], null, true);
    }

    public function test_the_postgresql_detail_line_is_removed(): void
    {
        $clean = QueryErrorSanitizer::sanitize($this->postgresError());

        foreach (['34123456', 'secret@example.com', 'hash-du-mot-de-passe', 'DETAIL'] as $value) {
            $this->assertStringNotContainsString($value, $clean->getMessage());
            $this->assertStringNotContainsString($value, (string) $clean->getPrevious()->errorInfo[2]);
        }
        // Le diagnostic reste utile : SQLSTATE, contrainte et requête (sans valeurs).
        $this->assertStringContainsString('SQLSTATE[23502]: Not null violation', $clean->getMessage());
        $this->assertStringContainsString('insert into "users"', $clean->getMessage());
        $this->assertSame('23502', $clean->getCode());
    }

    public function test_the_exception_type_is_kept(): void
    {
        $clean = QueryErrorSanitizer::sanitize($this->postgresError(UniqueConstraintViolationException::class));

        $this->assertInstanceOf(UniqueConstraintViolationException::class, $clean);
    }

    public function test_reported_sql_errors_never_reach_the_logs_with_their_values(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report($this->postgresError());

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => ! str_contains($message, 'secret@example.com')
            && ! str_contains($message, 'DETAIL') && str_contains($message, 'Not null violation'));
    }
}
