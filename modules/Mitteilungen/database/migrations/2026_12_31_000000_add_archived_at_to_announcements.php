<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Archived by hand (besides a period that is over); the planned period stays as it was */
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->timestampTz('archived_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
