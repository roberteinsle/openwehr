<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Terminarten (Übung, Dienst, Veranstaltung …) that admins name, colour, sort and add; events keep the key */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kal_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 60);
            $table->string('color', 7);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = [
            ['uebung', 'Übung', '#B91C1C'], ['dienst', 'Dienst', '#1D4ED8'], ['veranstaltung', 'Veranstaltung', '#15803D'],
            ['wartung', 'Wartung', '#B45309'], ['arbeitsdienst', 'Arbeitsdienst', '#C2410C'], ['sonstiges', 'Sonstiges', '#4B5563'],
        ];
        foreach ($defaults as $i => [$key, $label, $color]) {
            DB::table('kal_types')->insertOrIgnore(['key' => $key, 'label' => $label, 'color' => $color, 'sort_order' => $i + 1,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kal_types');
    }
};
