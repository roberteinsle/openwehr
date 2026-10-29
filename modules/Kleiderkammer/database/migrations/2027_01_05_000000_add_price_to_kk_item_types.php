<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Net price per piece of an Artikeltyp, in cent; the gross price is computed (Inventar)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kk_item_types', function (Blueprint $table) {
            $table->unsignedInteger('price_net_cents')->nullable()->after('code_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('kk_item_types', function (Blueprint $table) {
            $table->dropColumn('price_net_cents');
        });
    }
};
