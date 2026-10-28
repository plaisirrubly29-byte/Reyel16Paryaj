<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Wallet & Ledger (P5-01).
| Montants en unité mineure (bigInteger signé) + devise ISO 4217. Le ledger est append-only :
| toute correction passe par une écriture compensatoire (reversal_of_id).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('status', 20)->default('active'); // active | frozen
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 30); // App\Modules\Wallet\Enums\LedgerEntryType
            $table->bigInteger('amount_minor'); // > 0 crédit, < 0 débit
            $table->char('currency', 3);
            $table->bigInteger('balance_after_minor'); // solde après écriture (contrôle d'intégrité)
            // Référence métier unique : une même opération ne peut jamais être écrite deux fois.
            $table->string('reference', 150)->unique();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('related_type', 50)->nullable(); // ex. "bet"
            $table->string('related_id', 64)->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // acteur admin éventuel (null = système)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
            $table->index(['related_type', 'related_id']);
        });

        // OR REPLACE : migrate:fresh supprime les tables (et donc le déclencheur) mais pas les fonctions.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ledger_entries_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'ledger_entries est en ajout seul : utiliser une écriture compensatoire';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER ledger_entries_no_update_delete
                    BEFORE UPDATE OR DELETE ON ledger_entries
                    FOR EACH ROW EXECUTE FUNCTION ledger_entries_append_only();

                ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_not_zero CHECK (amount_minor <> 0);
                ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_currency_iso CHECK (currency ~ '^[A-Z]{3}$');
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update_delete ON ledger_entries; DROP FUNCTION IF EXISTS ledger_entries_append_only();');
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');
    }
};
