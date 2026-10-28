<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Betting (P4-01) : tickets et sélections de ticket.
| Montants en unité mineure (bigInteger) + devise ; cotes en decimal (chaîne en PHP) — jamais de float.
| Le ticket fige ce qui a été accepté (cotes, versions de cote, règles) : le catalogue continue de bouger
| après le pari, le règlement ne s'appuie que sur cet instantané.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_tickets', function (Blueprint $table) {
            $table->id();
            // Référence publique du reçu (ex. "R16-7KQ2M9XAPB") : unique en base, communiquée au joueur.
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // App\Modules\Betting\Enums\BetType
            $table->string('status', 10)->default('pending'); // App\Modules\Betting\Enums\BetTicketStatus
            $table->bigInteger('stake_minor');
            $table->char('currency', 3);
            // Produit des cotes acceptées des sélections.
            $table->decimal('total_odds', 14, 3);
            $table->bigInteger('potential_payout_minor');
            $table->bigInteger('payout_minor')->nullable(); // fixé au règlement (0 si perdu)
            // Règles en vigueur à l'acceptation (limites, politique de cote…) : jamais relues dans la config du jour.
            $table->string('rules_version', 30);
            $table->json('rules_snapshot');
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index('status');
        });

        Schema::create('bet_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_id')->constrained()->restrictOnDelete();
            // La synchro supprime une sélection retirée par le fournisseur : le lien tombe, l'instantané reste
            // (le règlement retrouve la sélection par marché + clé).
            $table->foreignId('selection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name', 255); // ex. "Everton – Chelsea"
            $table->timestamp('event_starts_at');
            $table->string('market_key', 50);
            $table->string('market_line', 10)->nullable();
            $table->string('selection_key', 50);
            $table->string('selection_name', 150);
            $table->decimal('odds', 8, 3);
            $table->unsignedInteger('odds_version');
            $table->string('status', 10)->default('pending'); // App\Modules\Betting\Enums\BetSelectionStatus
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // Deux issues d'un même marché s'excluent : une seule sélection par marché dans un ticket.
            $table->unique(['bet_ticket_id', 'market_id']);
            $table->index(['market_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE bet_tickets ADD CONSTRAINT bet_tickets_stake_positive CHECK (stake_minor > 0);
                ALTER TABLE bet_tickets ADD CONSTRAINT bet_tickets_odds_above_one CHECK (total_odds > 1);
                ALTER TABLE bet_tickets ADD CONSTRAINT bet_tickets_payout_not_negative CHECK (payout_minor IS NULL OR payout_minor >= 0);
                ALTER TABLE bet_tickets ADD CONSTRAINT bet_tickets_currency_iso CHECK (currency ~ '^[A-Z]{3}$');
                ALTER TABLE bet_selections ADD CONSTRAINT bet_selections_odds_above_one CHECK (odds > 1);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_selections');
        Schema::dropIfExists('bet_tickets');
    }
};
