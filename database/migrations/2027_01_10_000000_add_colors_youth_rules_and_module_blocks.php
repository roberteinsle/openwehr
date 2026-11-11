<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abteilungen get a colour (and a second one) for lists and badges, and rules for a Jugendabteilung:
 * modules not given automatically to its members, from which age they join the Übungsdienste of another
 * Abteilung as guests (group_guests) and at which age they move over. module_blocks lets an admin take
 * an automatically given module away from one Kamerad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('color', 7)->nullable();
            $table->string('color_secondary', 7)->nullable();
            $table->boolean('is_youth')->default(false);
            $table->jsonb('excluded_modules')->nullable();
            $table->foreignId('guest_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedTinyInteger('guest_from_age')->nullable();
            $table->foreignId('transfer_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedTinyInteger('transfer_age')->nullable();
        });

        Schema::create('group_guests', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['group_id', 'user_id']);
        });

        Schema::create('module_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 50);
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });

        // Colours by name for existing installs: the active Wehr red with dark blue, the Jugendfeuerwehr orange and blue
        DB::table('groups')->whereRaw("lower(name) like '%jugend%'")
            ->update(['color' => '#F28C28', 'color_secondary' => '#1D4ED8', 'is_youth' => true]);
        DB::table('groups')->whereNull('color')->where(fn ($q) => $q->whereRaw("lower(name) like '%aktiv%'")->orWhereRaw("lower(name) like '%einsatz%'"))
            ->update(['color' => '#ED2F12', 'color_secondary' => '#1B2645']);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_blocks');
        Schema::dropIfExists('group_guests');
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_group_id');
            $table->dropConstrainedForeignId('transfer_group_id');
            $table->dropColumn(['color', 'color_secondary', 'is_youth', 'excluded_modules', 'guest_from_age', 'transfer_age']);
        });
    }
};
