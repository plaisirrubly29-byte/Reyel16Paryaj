<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Revue P8-02, F9 : TRUNCATE refusé sur les tables en ajout seul. Déclencheurs PostgreSQL : test ignoré sur SQLite
 * (suite par défaut) ; à lancer sur une base PostgreSQL jetable, jamais sur une base de travail :
 *   DB_CONNECTION=pgsql DB_DATABASE=<base_jetable> php artisan test --filter=AppendOnlyTruncateTest
 */
class AppendOnlyTruncateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function appendOnlyTables(): array
    {
        return array_combine(
            $tables = ['ledger_entries', 'audit_logs', 'results', 'settlements', 'setting_versions'],
            array_map(fn (string $table) => [$table], $tables),
        );
    }

    #[DataProvider('appendOnlyTables')]
    public function test_truncate_is_refused(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Déclencheurs PostgreSQL : base PostgreSQL requise.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ajout seul');

        DB::statement("TRUNCATE {$table} CASCADE");
    }
}
