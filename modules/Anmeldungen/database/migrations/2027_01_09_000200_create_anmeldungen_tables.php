<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anmeldungen & Einverständnisse: a form per Fahrt, Zeltlager or Veranstaltung with fields the organiser
 * defines and the declarations to accept, and one registration per Kamerad – signed on screen by the
 * Erziehungsberechtigten (or the Kamerad). Answers are encrypted; health answers are wiped some days
 * after the end (an_forms.purge_days, anmeldungen:purge-health).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('an_forms', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();               // Markdown
            $table->string('location', 200)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestampTz('deadline')->nullable();           // Anmeldeschluss
            $table->jsonb('fields');                               // [{key, label, type, required, health, options, help}]
            $table->jsonb('consents');                             // [{key, text}] – all must be accepted
            $table->jsonb('group_ids');                            // audience: Abteilungen, [] = everyone with access
            $table->boolean('require_signature')->default(true);
            $table->boolean('guardian_required')->default(true);   // members with Erziehungsberechtigten are registered by them
            $table->boolean('member_signature')->default(false);   // the young person signs too
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->unsignedSmallInteger('purge_days')->default(14);
            $table->string('status', 10)->default('draft');        // draft | open | closed
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('health_purged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('an_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('an_forms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('registered');  // registered | cancelled
            $table->text('answers')->nullable();                   // encrypted JSON
            $table->text('health')->nullable();                    // encrypted JSON, wiped after the event
            $table->jsonb('consents')->nullable();                 // accepted keys
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name', 150);
            $table->text('signature')->nullable();                 // encrypted PNG base64
            $table->text('member_signature')->nullable();          // encrypted PNG base64
            $table->timestampTz('signed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['form_id', 'user_id']);
        });

        DB::table('modules')->upsert(
            [['key' => 'anmeldungen', 'is_active' => false, 'auto_assign' => true, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'anmeldungen')->delete();
        Schema::dropIfExists('an_registrations');
        Schema::dropIfExists('an_forms');
    }
};
