<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Sports Data : ligne des marchés à seuil (ex. "2.5" pour "Nombre de buts", marché totals).
| Chaîne décimale comme les cotes, jamais de float ; null pour le 1X2 (h2h).
| Aussi gardée dans l'historique : une version de cote d'un marché totals n'a de sens qu'avec sa ligne.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->string('line', 10)->nullable()->after('key');
        });

        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->string('line', 10)->nullable()->after('odds');
        });
    }

    public function down(): void
    {
        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->dropColumn('line');
        });

        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('line');
        });
    }
};
