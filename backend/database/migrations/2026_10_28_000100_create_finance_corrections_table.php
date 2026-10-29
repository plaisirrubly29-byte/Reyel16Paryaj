<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Admin (P6-04) : corrections financières à double validation.
| Une correction est demandée (finance.correct), puis validée ou refusée par un AUTRE administrateur
| (finance.approve). La validation passe l'écriture compensatoire par LedgerService::reverse.
| V1 : seules les écritures sans ticket sont corrigeables ; celles d'un ticket relèvent de P5-06.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained()->restrictOnDelete(); // écriture à compenser
            $table->string('status', 20)->default('pending'); // App\Modules\Admin\Enums\FinanceCorrectionStatus
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            // Second administrateur (validation ou refus) : jamais le demandeur pour une validation.
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            // Écriture compensatoire créée à la validation.
            $table->foreignId('reversal_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        // Une seule demande en attente par écriture (index partiel : PostgreSQL et SQLite).
        DB::statement("CREATE UNIQUE INDEX finance_corrections_one_pending_per_entry ON finance_corrections (ledger_entry_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_corrections');
    }
};
