<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Compliance (P7-02) — jeu responsable : limites de mise, pause, auto-exclusion, journal.
| Restrictions et journal conservés avec le compte (hypothèse de conservation, D-09 §3) : pas de cascade.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Une limite par joueur et par période. Changement en attente : hausse ou suppression (montant null)
        // qui ne s'applique qu'à pending_effective_at (délai de réflexion).
        Schema::create('betting_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10); // day | week | month
            $table->unsignedBigInteger('amount_minor')->nullable(); // null : aucune limite
            $table->unsignedBigInteger('pending_amount_minor')->nullable();
            $table->timestamp('pending_effective_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });

        // Pauses et auto-exclusions, jamais modifiées sauf pour leur levée (PR Conformité).
        Schema::create('responsible_gaming_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // pause | self_exclusion
            $table->string('duration', 10); // ex. 24h, 7d, 6m
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lift_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'ends_at']);
        });

        // Journal des événements de jeu responsable, en ajout seul.
        Schema::create('responsible_play_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 50);
            $table->json('data')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsible_play_events');
        Schema::dropIfExists('responsible_gaming_restrictions');
        Schema::dropIfExists('betting_limits');
    }
};
