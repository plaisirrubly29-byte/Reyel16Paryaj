<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Settlement (P5-04) : résultats des matchs et règlements des tickets.
| Les deux tables sont en ajout seul : un résultat corrigé est une nouvelle version (supersedes_id),
| un ticket réévalué un nouveau règlement (revision) — l'historique complet reste consultable (P5-06).
| Montants en unité mineure + devise ; scores en chaîne, comme dans events.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            // 1 pour le premier résultat reçu, +1 à chaque correction.
            $table->unsignedInteger('version');
            // App\Modules\Settlement\Enums\ResultStatus : final (score) | cancelled (match annulé, sans score)
            $table->string('status', 20);
            $table->string('home_score', 10)->nullable();
            $table->string('away_score', 10)->nullable();
            // Source : nom du fournisseur ("fake", "theodds") ou "manual" (saisie admin, P6-06).
            $table->string('source', 30);
            $table->string('external_id', 100)->nullable(); // référence du match chez le fournisseur
            $table->timestamp('provider_updated_at')->nullable();
            // Version précédente remplacée par celle-ci (null pour la première).
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('results')->restrictOnDelete();
            $table->string('correction_reason')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->restrictOnDelete(); // null = système
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_id', 'version']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_ticket_id')->constrained()->restrictOnDelete();
            // 1 au premier règlement ; une réévaluation après correction de résultat (P5-06) ajoute une révision.
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('status', 10); // App\Modules\Betting\Enums\BetTicketStatus (won | lost | void)
            $table->bigInteger('payout_minor'); // gain versé (mise remboursée si void, 0 si perdu)
            $table->char('currency', 3);
            // Écriture du ledger (gain ou remboursement) ; null pour un ticket perdu.
            $table->foreignId('ledger_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            // Résultats appliqués : { "<event_id>": <result_id> } — la version exacte qui a servi au calcul.
            $table->json('results');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['bet_ticket_id', 'revision']);
        });

        // Version de résultat qui a réglé chaque sélection de ticket (base de la réévaluation, P5-06).
        Schema::table('bet_selections', function (Blueprint $table) {
            $table->foreignId('result_id')->nullable()->after('status')->constrained('results')->restrictOnDelete();
        });

        // OR REPLACE : migrate:fresh supprime les tables (et donc les déclencheurs) mais pas les fonctions.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION settlement_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% est en ajout seul : ajouter une nouvelle version', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER results_no_update_delete
                    BEFORE UPDATE OR DELETE ON results
                    FOR EACH ROW EXECUTE FUNCTION settlement_append_only();

                CREATE TRIGGER settlements_no_update_delete
                    BEFORE UPDATE OR DELETE ON settlements
                    FOR EACH ROW EXECUTE FUNCTION settlement_append_only();

                ALTER TABLE results ADD CONSTRAINT results_version_positive CHECK (version > 0);
                ALTER TABLE results ADD CONSTRAINT results_final_has_score
                    CHECK (status <> 'final' OR (home_score IS NOT NULL AND away_score IS NOT NULL));
                ALTER TABLE settlements ADD CONSTRAINT settlements_payout_not_negative CHECK (payout_minor >= 0);
                ALTER TABLE settlements ADD CONSTRAINT settlements_currency_iso CHECK (currency ~ '^[A-Z]{3}$');
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('bet_selections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('result_id');
        });

        Schema::dropIfExists('settlements');
        Schema::dropIfExists('results');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS settlement_append_only();');
        }
    }
};
