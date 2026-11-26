<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shown on the Wachen-Monitor too: chosen per Mitteilung, only for ones meant for everyone */
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->boolean('on_monitor')->default(false));
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('on_monitor'));
    }
};
