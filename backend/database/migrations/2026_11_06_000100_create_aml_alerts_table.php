<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Compliance (V2-C04) : alertes AML. Chaque dépôt ou retrait passé au ledger est contrôlé par des règles
| (gros dépôt, cumul des dépôts, retrait sans jeu) ; une règle déclenchée crée une alerte, sans rien bloquer.
| La Conformité la classe ou la signale, avec un motif (aml.review, audité).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('rule', 40); // App\Modules\Compliance\Enums\AmlRule
            $table->string('status', 20)->default('open'); // App\Modules\Compliance\Enums\AmlAlertStatus
            // Écriture du ledger qui a déclenché la règle : une seule alerte par règle et par écriture (job rejoué).
            $table->foreignId('ledger_entry_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor'); // montant du dépôt ou du retrait
            $table->string('currency', 3);
            $table->json('details'); // seuil, fenêtre et totaux constatés
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['rule', 'ledger_entry_id']);
            $table->index(['status', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_alerts');
    }
};
