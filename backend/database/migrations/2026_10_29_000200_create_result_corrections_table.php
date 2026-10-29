<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Settlement (P5-06) — correction de résultat à double contrôle.
| Une demande propose une nouvelle issue pour un match déjà réglé ; rien ne change avant sa validation par un
| second administrateur, qui crée la nouvelle version du résultat et réévalue les tickets (écritures compensatoires).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            // Version du résultat en vigueur au moment de la demande : la validation est refusée si elle a changé.
            $table->foreignId('based_on_result_id')->constrained('results')->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // App\Modules\Settlement\Enums\ResultCorrectionStatus

            // Issue proposée (App\Modules\Settlement\Enums\ResultStatus) et son origine.
            $table->string('result_status', 20);
            $table->string('home_score', 10)->nullable();
            $table->string('away_score', 10)->nullable();
            $table->string('source', 30); // "manual" (administrateur) ou nom du fournisseur
            $table->string('external_id', 100)->nullable();
            $table->timestamp('provider_updated_at')->nullable();

            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete(); // null : fournisseur
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            // Version créée à la validation, et résumé de la réévaluation (tickets, montants).
            $table->foreignId('applied_result_id')->nullable()->unique()->constrained('results')->restrictOnDelete();
            $table->json('impact')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        // Une seule demande en attente par match (index partiel : PostgreSQL et SQLite).
        DB::statement("CREATE UNIQUE INDEX result_corrections_one_pending_per_event ON result_corrections (event_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('result_corrections');
    }
};
