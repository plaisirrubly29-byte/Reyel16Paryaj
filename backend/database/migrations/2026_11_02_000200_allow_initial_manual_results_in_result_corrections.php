<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| V2-D04 : le fournisseur n'a publié aucun résultat, un administrateur saisit le premier, validé par un second
| (même circuit à double contrôle que les corrections, P5-06). La demande ne repose alors sur aucune version :
| based_on_result_id devient facultatif (null = premier résultat saisi à la main).
*/
return new class extends Migration
{
    public function up(): void
    {
        $this->alterBasedOn(nullable: true);
    }

    public function down(): void
    {
        // Refusé s'il existe des demandes de premier résultat : les retirer d'abord serait une perte d'historique.
        $this->alterBasedOn(nullable: false);
    }

    private function alterBasedOn(bool $nullable): void
    {
        Schema::table('result_corrections', function (Blueprint $table) use ($nullable) {
            $table->foreignId('based_on_result_id')->nullable($nullable)->change();
        });

        // SQLite reconstruit la table pour change() et perd la condition de l'index partiel (une seule demande en
        // attente par match) : il est recréé à l'identique. PostgreSQL modifie la colonne sur place.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS result_corrections_one_pending_per_event');
            DB::statement("CREATE UNIQUE INDEX result_corrections_one_pending_per_event ON result_corrections (event_id) WHERE status = 'pending'");
        }
    }
};
