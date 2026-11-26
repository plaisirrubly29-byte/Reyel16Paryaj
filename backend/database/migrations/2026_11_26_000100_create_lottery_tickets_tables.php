<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Lottery, étape 2 (V2-D09) — fiches de borlette vendues, leurs lignes et les boules bloquées.
| Une fiche est un reçu : lignes, mises et barème figés à la vente ; seuls le statut et les gains changent au règlement.
| Les mouvements d'argent passent par le ledger (related_type "lottery_ticket").
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();      // « B16-7KQ2M9XAPB », à donner au support
            $table->string('placement_key', 64)->nullable(); // clé d'idempotence de la vente
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('lottery_draw_id')->constrained()->restrictOnDelete();
            $table->date('draw_date');                       // date locale du tirage
            $table->timestamp('draw_at');                    // heure du tirage (UTC)
            $table->string('status', 10)->default('pending'); // pending, won, lost, void
            $table->unsignedBigInteger('stake_minor');
            $table->unsignedBigInteger('max_payout_minor');
            $table->unsignedBigInteger('payout_minor')->nullable(); // total crédité au règlement (gains + remboursements)
            $table->char('currency', 3);
            $table->string('rules_version', 20);
            $table->json('rules_snapshot');
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'placement_key']);
            $table->index(['lottery_draw_id', 'draw_date', 'status']);
            $table->index(['user_id', 'placed_at']);
            $table->index(['status', 'draw_at']);
        });

        Schema::create('lottery_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_ticket_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('game', 10);                      // bolet, maryaj, loto3, loto4, loto5
            $table->unsignedTinyInteger('option')->nullable();
            $table->string('number1', 5);                    // chaînes : « 07 » garde son zéro
            $table->string('number2', 2)->nullable();        // seconde boule du maryaj
            $table->unsignedBigInteger('stake_minor');
            $table->json('multipliers');                     // barème figé : {"1":"50","2":"20","3":"10"} ou {"0":"1000"}
            $table->unsignedBigInteger('max_payout_minor');
            $table->string('status', 10)->default('pending'); // pending, won, lost, void
            $table->unsignedTinyInteger('winning_lot')->nullable();
            $table->unsignedBigInteger('payout_minor')->nullable();
            $table->timestamps();

            $table->index(['game', 'number1']);
            $table->index('number2');
        });

        Schema::create('lottery_blocked_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 5);
            $table->foreignId('lottery_draw_id')->nullable()->constrained()->restrictOnDelete(); // null = tous les tirages
            $table->date('draw_date')->nullable();                                              // null = tous les jours
            $table->string('reason', 500);
            $table->foreignId('blocked_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lift_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['number', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_blocked_numbers');
        Schema::dropIfExists('lottery_plays');
        Schema::dropIfExists('lottery_tickets');
    }
};
