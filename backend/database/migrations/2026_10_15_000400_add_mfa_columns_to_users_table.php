<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| MFA TOTP des comptes administratifs (P2-05). Secret et codes de secours chiffrés (APP_KEY).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable(); // empreintes SHA-256, jamais les codes en clair
            $table->unsignedBigInteger('mfa_last_used_step')->nullable(); // anti-rejeu d'un même code
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_used_step']);
        });
    }
};
