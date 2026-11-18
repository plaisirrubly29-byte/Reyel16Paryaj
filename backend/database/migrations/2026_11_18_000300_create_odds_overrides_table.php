<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| V2-D03 (décision D-18) : cotes fixées à la main par l'administration (Opérations, Super Admin), avec motif ; double
| validation au-delà de 20 % d'écart avec la cote calculée. Statuts : pending → active → ended / expired ; rejected.
| Une seule demande en attente ou en vigueur par sélection (contrôlé sous verrou par OddsOverrideService).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odds_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selection_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10); // App\Modules\SportsData\Enums\OddsOverrideStatus
            $table->decimal('odds', 8, 3);
            $table->decimal('computed_odds', 8, 3);
            $table->boolean('requires_approval');
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['selection_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_overrides');
    }
};
