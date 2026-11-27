<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-G04 : réclamations des joueurs, messages (réponses visibles et notes internes), pièces jointes chiffrées,
 * historique en ajout seul et compteur annuel des numéros (REC-AAAA-NNNNNN).
 * Les fichiers sont sur le disque privé « complaints », chiffrés ; la base ne garde que leur chemin et leur empreinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dernier numéro attribué par année : ligne verrouillée à chaque ouverture (aucun doublon en concurrence).
        Schema::create('complaint_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique(); // REC-AAAA-NNNNNN
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 20); // App\Modules\Complaints\Enums\ComplaintCategory
            $table->string('subject', 120);
            $table->text('description');
            $table->string('status', 20)->default('new'); // App\Modules\Complaints\Enums\ComplaintStatus
            // Objet concerné (ticket ou écriture du joueur), avec sa référence lisible figée à l'ouverture.
            $table->string('link_type', 20)->nullable(); // App\Modules\Complaints\Enums\ComplaintLinkType
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('link_label', 120)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution', 1000)->nullable(); // motif du dernier passage en resolved, montré au joueur
            // Dernier message du joueur sans réponse de l'équipe : depuis quand (null : rien en attente).
            $table->timestamp('awaiting_staff_since')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->string('author_type', 10); // player | staff
            $table->foreignId('author_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('visibility', 10)->default('player'); // player | internal (note de l'équipe)
            $table->text('body');
            $table->timestamps();

            $table->index(['complaint_id', 'id']);
        });

        Schema::create('complaint_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            // null : pièce de l'ouverture ; sinon pièce d'un message (et de sa visibilité).
            $table->foreignId('complaint_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('path', 255);
            $table->string('filename', 150); // nom d'origine, nettoyé
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64); // empreinte du fichier d'origine (avant chiffrement)
            $table->timestamps();

            $table->index(['complaint_id', 'id']);
        });

        // Historique en ajout seul : ni modification ni suppression (modèle ComplaintEvent).
        Schema::create('complaint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // App\Modules\Complaints\Enums\ComplaintEventType
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('actor_type', 10); // player | staff | system
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['complaint_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_events');
        Schema::dropIfExists('complaint_attachments');
        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('complaint_counters');
    }
};
