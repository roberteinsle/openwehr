<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Anwesenheit abschließen": the list goes to the Wehrführung by mail, by hand or 24 hours after the end
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kal_events', function (Blueprint $table) {
            $table->timestampTz('attendance_closed_at')->nullable();
            $table->foreignId('attendance_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('attendance_changed_at')->nullable();   // changed after closing: a Korrektur can be sent
            $table->timestampTz('attendance_reminded_at')->nullable();  // "Anwesenheit fehlt" sent once
        });

        // Events that are already over count as closed, so the first run doesn't mail old lists
        DB::table('kal_events')->where('ends_at', '<', now())->update(['attendance_closed_at' => DB::raw('ends_at')]);
    }

    public function down(): void
    {
        Schema::table('kal_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_closed_by');
            $table->dropColumn(['attendance_closed_at', 'attendance_changed_at', 'attendance_reminded_at']);
        });
    }
};
