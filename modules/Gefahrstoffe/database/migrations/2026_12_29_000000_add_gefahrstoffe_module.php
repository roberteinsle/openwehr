<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Gefahrstoffe nachschlagen: no tables of its own (the data is a file in the module), only the module row.
// Off until an admin switches it on; then every Kamerad may use it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->upsert(
            [['key' => 'gefahrstoffe', 'is_active' => false, 'auto_assign' => true, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'gefahrstoffe')->delete();
    }
};
