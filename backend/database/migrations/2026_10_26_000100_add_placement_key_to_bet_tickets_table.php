<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Betting (P4-03, revue de sécurité P8-02 — M4) : clé d'idempotence du pari figée sur le ticket.
| Le middleware "idempotent" libère sa clé si la requête échoue après le commit (ou si le processus meurt) :
| la contrainte unique (joueur, clé) garantit qu'un rejeu ne crée jamais un second ticket ni une seconde mise.
| Null pour les tickets créés sans passer par POST /bets.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->string('placement_key', 36)->nullable()->after('reference');
            $table->unique(['user_id', 'placement_key']);
        });
    }

    public function down(): void
    {
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'placement_key']);
            $table->dropColumn('placement_key');
        });
    }
};
