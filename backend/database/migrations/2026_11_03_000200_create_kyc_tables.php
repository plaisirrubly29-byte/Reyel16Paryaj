<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-C03 : dossiers KYC (pièce d'identité + selfie) et statut KYC du compte.
 * Les fichiers sont sur le disque privé « kyc », chiffrés ; la base ne garde que leur chemin et leur empreinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_status', 10)->default('none'); // App\Modules\IdentityAccess\Enums\KycStatus
            $table->timestamp('kyc_verified_at')->nullable();
        });

        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 20); // App\Modules\IdentityAccess\Enums\KycDocumentType
            $table->string('status', 10)->default('pending'); // App\Modules\IdentityAccess\Enums\KycSubmissionStatus
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('kyc_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10); // App\Modules\IdentityAccess\Enums\KycFileKind
            $table->string('path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64); // empreinte du fichier d'origine (avant chiffrement)
            $table->timestamps();

            $table->unique(['kyc_submission_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_files');
        Schema::dropIfExists('kyc_submissions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_status', 'kyc_verified_at']);
        });
    }
};
