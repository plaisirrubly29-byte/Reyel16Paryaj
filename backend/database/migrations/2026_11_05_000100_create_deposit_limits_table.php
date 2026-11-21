<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Compliance (V2-B06) — limites de dépôt choisies par le joueur, mêmes colonnes et même règle que
| betting_limits : baisse immédiate, hausse ou suppression après le délai de réflexion.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10); // day | week | month
            $table->unsignedBigInteger('amount_minor')->nullable(); // null : aucune limite
            $table->unsignedBigInteger('pending_amount_minor')->nullable();
            $table->timestamp('pending_effective_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_limits');
    }
};
