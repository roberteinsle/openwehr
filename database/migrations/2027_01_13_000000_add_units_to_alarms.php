<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The alarmed RICs, vehicles and persons of a DIVERA alarm (lists of names), for the alarm view */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alarms', function (Blueprint $table) {
            $table->jsonb('rics')->nullable();
            $table->jsonb('vehicles')->nullable();
            $table->jsonb('persons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alarms', fn (Blueprint $table) => $table->dropColumn(['rics', 'vehicles', 'persons']));
    }
};
