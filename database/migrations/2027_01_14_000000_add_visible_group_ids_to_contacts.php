<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which Abteilungen see an Ansprechpartner (null/empty = everyone); parents see what their child sees */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->jsonb('visible_group_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('visible_group_ids'));
    }
};
