<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login link also carries a short code to type into the app: on iOS a link from the mail always opens
 * Safari, never the app on the home screen (which has its own cookies). Stored as HMAC, never in clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('login_tokens', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'code_attempts']);
        });
    }
};
