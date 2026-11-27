<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-F05 : index des requêtes du tableau de bord (DashboardService), rafraîchi chaque minute. Sans eux, chaque
 * affichage relisait le ledger, les tickets, les fiches et les comptes en entier (une vingtaine de requêtes filtrées
 * par période). Ajout seul : aucune donnée n'est modifiée.
 *
 * Créés tant que les tables sont petites : la construction d'un index bloque les écritures de sa table le temps
 * qu'elle dure (une fraction de seconde aujourd'hui).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dépôts, retraits, mises (joueurs actifs), gains, compensations : filtrés par type puis par période.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['type', 'created_at']);
        });

        // Mises (date de placement), gains et produit brut (date de règlement).
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->index('placed_at');
            $table->index('settled_at');
        });

        // Borlette : l'index existant (user_id, placed_at) exige le joueur, inutile pour une période.
        Schema::table('lottery_tickets', function (Blueprint $table) {
            $table->index('placed_at');
            $table->index('settled_at');
        });

        // Inscriptions du jour et de la période.
        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('lottery_tickets', function (Blueprint $table) {
            $table->dropIndex(['settled_at']);
            $table->dropIndex(['placed_at']);
        });
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->dropIndex(['settled_at']);
            $table->dropIndex(['placed_at']);
        });
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropIndex(['type', 'created_at']));
    }
};
