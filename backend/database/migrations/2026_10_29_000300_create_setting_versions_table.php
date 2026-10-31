<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Admin (P6-08) : paramètres de la plateforme versionnés (mises, plafond de gain, combinés, direct,
| crédit de bienvenue, fournisseur). Chaque modification crée une version complète, append-only, avec son
| auteur et son motif ; la dernière version s'applique par-dessus config/ (App\Modules\Admin\Services\PlatformSettings).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique(); // 1, 2, 3… : deux modifications simultanées ne prennent pas le même numéro
            $table->json('values'); // instantané complet des paramètres modifiables
            $table->json('changes'); // { clé: { from, to } } par rapport à la version précédente
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // OR REPLACE : migrate:fresh supprime les tables (et donc le déclencheur) mais pas les fonctions.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION setting_versions_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'setting_versions est en ajout seul (append-only)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER setting_versions_no_update_delete
                    BEFORE UPDATE OR DELETE ON setting_versions
                    FOR EACH ROW EXECUTE FUNCTION setting_versions_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS setting_versions_no_update_delete ON setting_versions; DROP FUNCTION IF EXISTS setting_versions_append_only();');
        }

        Schema::dropIfExists('setting_versions');
    }
};
