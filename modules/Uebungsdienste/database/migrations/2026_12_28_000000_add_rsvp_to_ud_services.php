<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rückmeldung for an Übungsdienst (in its Kalender Termin) and the Frist in days before it (null = until the start) */
    public function up(): void
    {
        Schema::table('ud_services', function (Blueprint $table) {
            $table->boolean('rsvp')->default(false);
            $table->unsignedSmallInteger('rsvp_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ud_services', fn (Blueprint $table) => $table->dropColumn(['rsvp', 'rsvp_days']));
    }
};
