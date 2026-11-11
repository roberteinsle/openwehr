<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eltern-Login: Erziehungsberechtigte sign in with the address stored at the child (magic link or code,
 * guard "guardian") and see the Eltern-Bereich. A login token belongs to a Kamerad or to a guardian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
        });

        Schema::table('login_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardian_id');
        });
        Schema::table('guardians', function (Blueprint $table) {
            $table->dropColumn(['remember_token', 'last_login_at']);
        });
    }
};
