<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Sports Data : journal des appels au fournisseur (suivi des crédits The Odds API).
| Une ligne par réponse reçue, payante ou gratuite. La clé d'API n'y figure jamais.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_data_api_calls', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('endpoint', 100); // ex. "/sports/{sport}/odds" (sans paramètres)
            $table->string('competition', 100)->nullable(); // ex. "soccer_epl"
            $table->unsignedInteger('cost')->default(0); // en-tête x-requests-last
            $table->unsignedInteger('remaining')->nullable(); // en-tête x-requests-remaining
            $table->unsignedInteger('used')->nullable(); // en-tête x-requests-used
            $table->unsignedSmallInteger('status');
            $table->timestamp('called_at');

            $table->index('called_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_data_api_calls');
    }
};
