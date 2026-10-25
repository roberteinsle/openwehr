<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Freely defined groups of Ansprechpartner (e.g. "Extern" for Leitstelle, Gemeinde, Kreisbrandmeister) next to the Abteilungen
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('on_roster')->default(true); // listed under the printed Kalender Dienstplan
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('contact_group_id')->nullable()->after('group_id')->constrained('contact_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropConstrainedForeignId('contact_group_id'));
        Schema::dropIfExists('contact_groups');
    }
};
