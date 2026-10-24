<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The role "planer" is now called "schichtplaner", next to the Übungsdienstplaner */
    public function up(): void
    {
        DB::table('module_user')->where('module', 'schichtplan')->where('role', 'planer')->update(['role' => 'schichtplaner']);
    }

    public function down(): void
    {
        DB::table('module_user')->where('module', 'schichtplan')->where('role', 'schichtplaner')->update(['role' => 'planer']);
    }
};
