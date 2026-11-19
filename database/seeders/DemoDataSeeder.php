<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds demo data for local development and testing instances.
 * Creates sample vehicles and groups — do NOT run on real customer databases.
 * Called by DatabaseSeeder in non-production environments.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $vehicles = [
            ['name' => 'MTW alt', 'short_name' => 'MTW-A', 'color' => '#ef4444', 'description' => 'Mannschaftstransportwagen (alt)', 'sort_order' => 1],
            ['name' => 'MTW neu', 'short_name' => 'MTW-N', 'color' => '#3b82f6', 'description' => 'Mannschaftstransportwagen (neu)', 'sort_order' => 2],
            ['name' => 'JF-Anhänger', 'short_name' => 'JF-Anh', 'color' => '#22c55e', 'description' => 'Anhänger Jugendfeuerwehr', 'sort_order' => 3],
        ];
        foreach ($vehicles as $vehicle) {
            // updateOrInsert, not upsert: vehicles.name has no unique index
            DB::table('vehicles')->updateOrInsert(
                ['name' => $vehicle['name']],
                [...$vehicle, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $groups = ['Aktiver Dienst', 'Jugendfeuerwehr', 'Musikzug', 'Wehrführer'];
        foreach ($groups as $index => $name) {
            DB::table('groups')->updateOrInsert(
                ['name' => $name],
                ['sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
