<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Revue P8-02, F9 : les déclencheurs « ajout seul » ne bloquent que UPDATE et DELETE ligne par ligne ; TRUNCATE les
| contournait et vidait la table d'un coup. Un déclencheur BEFORE TRUNCATE (par instruction) le refuse sur chaque table
| en ajout seul, avec la fonction qui la protège déjà. Seconde moitié du point, côté hébergement : en production, un rôle
| de migration distinct, et REVOKE UPDATE, DELETE, TRUNCATE au rôle de l'application (sinon il peut retirer le déclencheur).
*/
return new class extends Migration
{
    /** Table en ajout seul => fonction de déclencheur créée avec elle. */
    private const TABLES = [
        'ledger_entries' => 'ledger_entries_append_only',
        'audit_logs' => 'audit_logs_append_only',
        'results' => 'settlement_append_only',
        'settlements' => 'settlement_append_only',
        'setting_versions' => 'setting_versions_append_only',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $function) {
            DB::unprepared("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION {$function}();");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table};");
        }
    }
};
