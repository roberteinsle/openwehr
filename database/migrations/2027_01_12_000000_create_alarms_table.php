<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alarms from the DIVERA webhook (Admin → DIVERA-Webhook), shown on the Wachen-Monitor */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarms', function (Blueprint $table) {
            $table->id();
            // divera | test (simulated in the admin)
            $table->string('source', 20)->default('divera');
            // DIVERA's alarm id: a second request for the same alarm updates it
            $table->string('external_id', 100)->nullable()->index();
            $table->string('keyword', 150)->nullable();
            $table->text('message')->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // divera | google | osm: where the coordinates came from
            $table->string('located_by', 20)->nullable();
            $table->boolean('priority')->default(false);
            // The request as it came
            $table->jsonb('payload')->nullable();
            $table->timestampTz('received_at')->index();
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_by', 150)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarms');
    }
};
