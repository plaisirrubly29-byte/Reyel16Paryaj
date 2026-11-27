<?php

namespace Tests\Feature\Modules\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V2-F05 : les requêtes du tableau de bord (filtrées par période, rafraîchies chaque minute) ont leurs index. Sans
 * eux, chaque affichage relit le ledger, les tickets, les fiches et les comptes en entier.
 */
class DashboardIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_queries_have_their_indexes(): void
    {
        $expected = [
            'ledger_entries' => [['type', 'created_at']],
            'bet_tickets' => [['placed_at'], ['settled_at']],
            'lottery_tickets' => [['placed_at'], ['settled_at']],
            'users' => [['created_at']],
        ];

        foreach ($expected as $table => $indexes) {
            foreach ($indexes as $columns) {
                $this->assertTrue(Schema::hasIndex($table, $columns), "Index manquant : {$table} (".implode(', ', $columns).')');
            }
        }
    }
}
