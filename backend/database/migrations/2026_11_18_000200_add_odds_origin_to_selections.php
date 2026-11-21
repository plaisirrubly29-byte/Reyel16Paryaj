<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| V2-D03 (décision D-18) : origine de la cote proposée, pour les cotes fixées à la main.
| - selections : cote calculée par la synchronisation (marge de l'opérateur avec l'argent réel, OddsMargin), cote du
|   bookmaker de référence, meilleure cote suivie (repère contre l'arbitrage), et adjusted (cote fixée à la main en
|   vigueur, que la synchronisation n'écrase pas) ;
| - odds_snapshots : cote calculée, cote du bookmaker de référence et adjusted à chaque version.
| Cotes en chaînes décimales, jamais de float. Null pour un marché maison (V2-D10, sans bookmaker).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->decimal('computed_odds', 8, 3)->nullable()->after('odds');
            $table->decimal('provider_odds', 8, 3)->nullable()->after('computed_odds');
            $table->decimal('best_odds', 8, 3)->nullable()->after('provider_odds');
            $table->boolean('adjusted')->default(false)->after('best_odds');
        });

        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->decimal('computed_odds', 8, 3)->nullable()->after('odds');
            $table->decimal('provider_odds', 8, 3)->nullable()->after('computed_odds');
            $table->boolean('adjusted')->default(false)->after('provider_odds');
        });
    }

    public function down(): void
    {
        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->dropColumn(['computed_odds', 'provider_odds', 'adjusted']);
        });

        Schema::table('selections', function (Blueprint $table) {
            $table->dropColumn(['computed_odds', 'provider_odds', 'best_odds', 'adjusted']);
        });
    }
};
