<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who runs a Termin ("Ausführend" in the Dienstplan), e.g. Vorstand, Wehrführung, Festausschuss */
    public function up(): void
    {
        Schema::table('kal_events', fn (Blueprint $table) => $table->string('organizer', 100)->nullable());
    }

    public function down(): void
    {
        Schema::table('kal_events', fn (Blueprint $table) => $table->dropColumn('organizer'));
    }
};
