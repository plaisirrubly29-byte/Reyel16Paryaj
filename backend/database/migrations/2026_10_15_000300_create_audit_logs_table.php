<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Module Audit (P6-01) : journal append-only des actions sensibles.
| Pas de clé étrangère vers users : aucune suppression en cascade ne doit modifier l'historique.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 100)->index(); // App\Modules\Audit\Enums\AuditAction
            $table->unsignedBigInteger('actor_id')->nullable()->index(); // null = système
            $table->string('actor_email')->nullable(); // figé au moment de l'action
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs est en ajout seul (append-only)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_no_update_delete
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_append_only();');
        }

        Schema::dropIfExists('audit_logs');
    }
};
