<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Paris en direct (V2-D06, décision D-20, option « Modèle + The Odds »).
| - events : force offensive de chaque équipe tirée des cotes (buts attendus sur un match entier) et coefficient des
|   nuls, pour le modèle de cotes en direct (LiveOddsModel) ; heure du dernier score reçu et du dernier changement de
|   score (suspension automatique des marchés).
| - bet_tickets : délai d'acceptation d'un pari en direct (statut « awaiting ») et motif d'un refus (« rejected »).
| - bet_selections : score du match au moment du pari, revérifié après le délai.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('live_home_rate', 6, 3)->nullable();
            $table->decimal('live_away_rate', 6, 3)->nullable();
            $table->decimal('live_draw_boost', 6, 3)->nullable();
            $table->timestamp('live_model_at')->nullable();
            $table->timestamp('score_checked_at')->nullable();
            $table->timestamp('score_changed_at')->nullable();
        });

        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->boolean('live')->default(false);
            $table->timestamp('acceptance_due_at')->nullable();
            $table->string('rejection_reason', 40)->nullable();
            $table->index(['status', 'acceptance_due_at']);
        });

        Schema::table('bet_selections', function (Blueprint $table) {
            $table->string('live_score', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bet_selections', function (Blueprint $table) {
            $table->dropColumn('live_score');
        });

        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'acceptance_due_at']);
            $table->dropColumn(['live', 'acceptance_due_at', 'rejection_reason']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['live_home_rate', 'live_away_rate', 'live_draw_boost', 'live_model_at', 'score_checked_at', 'score_changed_at']);
        });
    }
};
