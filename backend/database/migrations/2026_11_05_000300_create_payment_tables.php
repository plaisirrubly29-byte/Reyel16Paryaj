<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Payments Adapter (V2-B01) — journal des transactions chez les prestataires et des webhooks reçus.
| Conservés avec le compte (preuve financière) : pas de cascade.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('direction', 10); // deposit | payout
            $table->string('status', 20); // pending | succeeded | failed | expired | review
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reference', 40)->unique(); // notre référence, transmise au prestataire
            $table->string('provider_reference', 100)->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('review_reason', 50)->nullable(); // ex. amount_mismatch
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Chaque webhook reçu, en ajout seul. event_id null : signature invalide ou contenu illisible (l'identifiant
        // n'est pas fiable, il ne doit pas bloquer le vrai événement du même identifiant).
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 100)->nullable();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('signature_valid');
            $table->string('outcome', 30);
            $table->text('payload');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            $table->unique(['provider', 'event_id']);
            $table->index(['payment_transaction_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_transactions');
    }
};
