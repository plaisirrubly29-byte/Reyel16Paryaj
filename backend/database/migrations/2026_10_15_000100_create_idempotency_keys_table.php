<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // "user:<id>" ou "guest:<ip>" : une même clé n'entre jamais en collision entre deux utilisateurs.
            $table->string('scope', 100);
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path');
            $table->char('fingerprint', 64);
            $table->string('status', 20); // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('response_content_type')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
