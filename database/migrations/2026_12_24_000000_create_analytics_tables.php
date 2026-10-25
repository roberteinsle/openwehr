<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical figures for the admin "Analyse", all aggregated (no person in them):
 * - app_errors: exceptions grouped by place, with count and first/last time
 * - request_metrics: page requests per hour and area with time and server errors
 * - heartbeats: one row per minute the scheduler ran (uptime)
 * - schedule_runs: last run, failure and error of every scheduled command
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('class', 255);
            $table->text('message');
            $table->string('location', 255)->nullable();
            $table->string('url', 255)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestampTz('first_at');
            $table->timestampTz('last_at')->index();
            $table->timestampTz('notified_at')->nullable();
        });

        Schema::create('request_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('hour');
            $table->string('area', 40);
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('server_errors')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unique(['hour', 'area']);
        });

        Schema::create('heartbeats', function (Blueprint $table) {
            $table->timestampTz('minute')->primary();
        });

        Schema::create('schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 255)->unique();
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->timestampTz('last_started_at')->nullable();
            $table->timestampTz('last_finished_at')->nullable();
            $table->timestampTz('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['schedule_runs', 'heartbeats', 'request_metrics', 'app_errors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
