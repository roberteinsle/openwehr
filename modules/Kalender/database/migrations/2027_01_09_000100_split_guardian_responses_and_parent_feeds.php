<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rückmeldung of a child and of its Erziehungsberechtigten are kept apart: own_status is the child's
 * answer, guardian_* the parents' one. status stays the answer that counts (the parents' when there is
 * one), so lists, counts and attendance read it as before. Kalender-Abo links can belong to a parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kal_responses', function (Blueprint $table) {
            $table->string('own_status', 10)->nullable()->after('status');
            $table->string('guardian_status', 10)->nullable()->after('own_status');
            $table->string('guardian_comment', 500)->nullable()->after('guardian_status');
            $table->foreignId('guardian_id')->nullable()->after('guardian_comment')->constrained('guardians')->nullOnDelete();
            $table->string('guardian_name', 150)->nullable()->after('guardian_id');
            $table->timestampTz('guardian_at')->nullable()->after('guardian_name');
        });
        // Until now every answer was the Kamerad's own (or set by the Terminverwaltung)
        DB::table('kal_responses')->whereNotNull('status')->whereNull('set_by')->update(['own_status' => DB::raw('status')]);

        Schema::table('kal_feed_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('parent_hash', 64)->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        DB::table('kal_feed_tokens')->whereNull('user_id')->delete();
        Schema::table('kal_feed_tokens', function (Blueprint $table) {
            $table->dropColumn('parent_hash');
        });
        Schema::table('kal_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
            $table->dropColumn(['own_status', 'guardian_status', 'guardian_comment', 'guardian_name', 'guardian_at']);
        });
    }
};
