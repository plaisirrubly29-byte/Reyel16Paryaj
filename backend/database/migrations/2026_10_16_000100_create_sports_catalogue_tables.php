<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Sports Data (P3-01) : sports, compétitions, équipes, événements, marchés, sélections, historique des cotes.
| Chaque entité venant du fournisseur garde sa référence externe, unique par fournisseur.
| Cotes : decimal(8,3) en base, chaîne en PHP (cast decimal:3) — jamais de float.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique(); // ex. "soccer", "basketball"
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('external_key', 100); // ex. "soccer_epl" chez The Odds API
            $table->string('name', 150);
            $table->string('country', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'external_key']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->timestamps();

            // The Odds API identifie les équipes par leur nom, propre à chaque sport.
            $table->unique(['sport_id', 'name']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('external_id', 100);
            $table->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $table->timestamp('starts_at');
            // scheduled | live | finished | postponed | cancelled
            $table->string('status', 20)->default('scheduled');
            $table->string('home_score', 10)->nullable();
            $table->string('away_score', 10)->nullable();
            $table->timestamp('result_received_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'starts_at']);
            $table->index(['competition_id', 'starts_at']);
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50); // ex. "h2h" (1X2)
            // open | suspended | closed | settled
            $table->string('status', 20)->default('open');
            $table->string('suspension_reason')->nullable();
            // Incrémentée à chaque changement de cote : le ticket fige la version acceptée.
            $table->unsignedInteger('odds_version')->default(1);
            $table->string('source_bookmaker', 50)->nullable();
            // Horodatage de la cote chez le fournisseur : base de la règle de fraîcheur (P3-04).
            $table->timestamp('odds_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'key']);
            $table->index('status');
        });

        Schema::create('selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50); // ex. "home", "draw", "away"
            $table->string('name', 150);
            $table->decimal('odds', 8, 3);
            // won | lost | void (règlement, P5-04/P5-05)
            $table->string('result', 10)->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'key']);
        });

        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('odds_version');
            $table->decimal('odds', 8, 3);
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->unique(['selection_id', 'odds_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_snapshots');
        Schema::dropIfExists('selections');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('events');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('sports');
    }
};
