<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Lottery — tirages de borlette (New York midi, Floride soir…) et résultats publiés par le fournisseur.
| Un résultat importé n'est jamais écrasé : si le fournisseur publie d'autres numéros pour le même tirage, la synchro
| garde le premier et signale le conflit (lottery.result_conflict). Les corrections passeront par l'administration.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_draws', function (Blueprint $table) {
            $table->id();
            $table->string('external_key', 50)->unique(); // clé du tirage chez le fournisseur, ex. "ny_midday"
            $table->string('name', 100);                   // nom affiché, ex. « New York Midi »
            $table->string('draw_time', 5);                // heure locale du tirage, "HH:MM"
            $table->string('timezone', 50);                // fuseau de l'heure du tirage, ex. "America/New_York"
            $table->json('days')->nullable();              // jours ISO (1 = lundi … 7 = dimanche) ; null = tous les jours
            $table->boolean('is_active')->default(true);   // false : absent de la dernière liste du fournisseur
            $table->string('source', 30);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lottery_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_draw_id')->constrained()->restrictOnDelete();
            $table->date('draw_date');                     // date locale du tirage (fuseau du tirage)
            // Trois lots de deux chiffres, dans l'ordre (1er, 2e, 3e lot).
            $table->char('lot1', 2);
            $table->char('lot2', 2);
            $table->char('lot3', 2);
            // Numéros bruts du tirage, quand le fournisseur les donne (loto 3, loto 4).
            $table->char('pick3', 3)->nullable();
            $table->char('pick4', 4)->nullable();
            $table->string('source', 30);
            $table->string('external_id', 100)->nullable();
            $table->timestamp('published_at')->nullable(); // heure de publication chez le fournisseur
            $table->timestamps();

            $table->unique(['lottery_draw_id', 'draw_date']);
            $table->index('draw_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_results');
        Schema::dropIfExists('lottery_draws');
    }
};
