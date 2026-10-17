<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Module Identity & Access (P2-01) : statut du compte, profil, rôles et permissions (RBAC).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // App\Modules\IdentityAccess\Enums\AccountStatus
            $table->string('status', 20)->default('active')->after('password')->index();
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            // Identifiant de connexion (numéro haïtien local, 8 chiffres, sans l'indicatif 509).
            $table->string('phone', 8)->unique()->after('email');
        });

        // L'email devient optionnel : le téléphone est l'identifiant de connexion.
        // `unique()` reste correct avec des valeurs NULL multiples : SQLite comme MySQL/PostgreSQL
        // n'indexent pas NULL comme une valeur égale à elle-même, donc plusieurs comptes sans email cohabitent.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->char('country', 2)->nullable(); // ISO 3166-1 alpha-2
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
            // Rôle administratif : MFA obligatoire (P2-05).
            $table->boolean('is_admin')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique(); // ex. "users.suspend"
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('user_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'status_changed_at', 'last_login_at', 'phone']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
