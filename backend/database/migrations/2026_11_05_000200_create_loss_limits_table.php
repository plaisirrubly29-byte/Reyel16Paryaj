<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Compliance (V2-E06) — limites de pertes nettes (mêmes colonnes et même règle que betting_limits)
| et intervalle du rappel de durée de session choisi par le joueur.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loss_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10); // day | week | month
            $table->unsignedBigInteger('amount_minor')->nullable(); // null : aucune limite
            $table->unsignedBigInteger('pending_amount_minor')->nullable();
            $table->timestamp('pending_effective_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_reminder_minutes')->nullable(); // null : rappel désactivé
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_reminder_minutes');
        });

        Schema::dropIfExists('loss_limits');
    }
};
