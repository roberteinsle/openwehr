<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Übungsdienstplaner with roles and Abteilungen:
 * - owner_group_id: the Abteilung a Dienst is planned for (null = the whole Wehr), organizer: who runs it
 *   ("Ausführender" in the Dienstplan)
 * - ud_scopes: the Abteilungen an Übungsdienstplaner plans for (none = all)
 * - ud_service_vehicles: vehicles a Dienst needs, with the booking made for them (null = not booked)
 * - ud_swaps: a responsible person asks another to swap Dienste; the other one agrees or declines
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ud_services', function (Blueprint $table) {
            $table->foreignId('owner_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('organizer', 100)->nullable();
        });

        Schema::create('ud_scopes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->primary(['user_id', 'group_id']);
        });

        Schema::create('ud_service_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('ud_services')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            // Why it could not be booked (e.g. already booked), shown to the planner
            $table->string('note', 255)->nullable();
            $table->unique(['service_id', 'vehicle_id']);
        });

        Schema::create('ud_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('ud_services')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_service_id')->constrained('ud_services')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');   // pending | accepted | declined | withdrawn
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'target_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ud_swaps');
        Schema::dropIfExists('ud_service_vehicles');
        Schema::dropIfExists('ud_scopes');
        Schema::table('ud_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_group_id');
            $table->dropColumn('organizer');
        });
    }
};
