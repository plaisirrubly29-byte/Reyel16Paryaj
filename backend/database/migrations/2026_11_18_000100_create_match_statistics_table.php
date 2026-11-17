<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Settlement (V2-D09) : statistiques d'un match terminé, pour régler les paris sur la 1re mi-temps,
| les corners et les cartons. Une ligne par match, en ajout seul (enregistrée une fois, à la fin du match).
| Chaque valeur peut manquer (null) si le fournisseur ne la donne pas : les paris concernés attendent, puis
| sont annulés passé le délai (sports_data.statistics.void_after_hours).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('source', 30); // fournisseur ("apifootball", "fake")
            $table->string('external_id', 100)->nullable(); // référence du match chez le fournisseur
            // Score à la mi-temps.
            $table->unsignedSmallInteger('first_half_home')->nullable();
            $table->unsignedSmallInteger('first_half_away')->nullable();
            $table->unsignedSmallInteger('corners_home')->nullable();
            $table->unsignedSmallInteger('corners_away')->nullable();
            // Cartons montrés : jaunes + rouges.
            $table->unsignedSmallInteger('cards_home')->nullable();
            $table->unsignedSmallInteger('cards_away')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_statistics');
    }
};
