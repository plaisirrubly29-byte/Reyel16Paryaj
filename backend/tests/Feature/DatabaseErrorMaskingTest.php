<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
