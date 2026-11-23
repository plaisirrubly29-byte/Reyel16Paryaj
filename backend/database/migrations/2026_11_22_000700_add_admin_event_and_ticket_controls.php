<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Gestion des matchs et des tickets par l'administration (audit du 2026-11-22, points A1 à A3).
| - events : match masqué (retiré du site, motif), et heure ou statut fixés par un administrateur, que la
|   synchronisation des cotes ne remplace plus (admin_locked_at).
| - ticket_voids : annulation d'un ticket à double validation (un administrateur demande, un autre valide) ;
|   à la validation, toutes les sélections passent à la cote 1 et la mise est rendue.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 500)->nullable();
            $table->timestamp('admin_locked_at')->nullable();
        });

        Schema::create('ticket_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_ticket_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // App\Modules\Settlement\Enums\TicketVoidStatus
            // Statut du ticket au moment de la demande : la validation est refusée s'il a changé entre-temps.
            $table->string('ticket_status', 20);
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            // Effet de la validation : statut et gain avant / après, écart au portefeuille.
            $table->json('impact')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        // Une seule demande en attente par ticket (index partiel : PostgreSQL et SQLite).
        DB::statement("CREATE UNIQUE INDEX ticket_voids_one_pending_per_ticket ON ticket_voids (bet_ticket_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_voids');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['hidden_at', 'hidden_reason', 'admin_locked_at']);
        });
    }
};
