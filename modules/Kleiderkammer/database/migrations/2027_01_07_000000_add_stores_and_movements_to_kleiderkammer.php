<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several Lager (kk_stores, starting with "Hauptlager") and the Protokoll of every movement
 * (kk_movements: Zugang, Abgang, Ausgabe, Rücknahme, Korrektur), which replaces kk_disposals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('notes', 300)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        $main = DB::table('kk_stores')->insertGetId(['name' => 'Hauptlager', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // The Lager an Artikel is kept in by default
        Schema::table('kk_item_types', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('category_id')->constrained('kk_stores')->nullOnDelete();
        });
        Schema::table('kk_items', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('group_id')->constrained('kk_stores')->restrictOnDelete();
        });
        Schema::table('kk_stock', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('group_id')->constrained('kk_stores')->restrictOnDelete();
        });
        // Where an issue from stock came from, so the Rücknahme goes back there
        Schema::table('kk_issued_items', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('group_id')->constrained('kk_stores')->nullOnDelete();
        });
        DB::table('kk_items')->update(['store_id' => $main]);
        DB::table('kk_stock')->update(['store_id' => $main]);
        DB::table('kk_issued_items')->whereNull('item_id')->update(['store_id' => $main]);

        Schema::table('kk_stock', function (Blueprint $table) {
            $table->dropUnique(['item_type_id', 'size_id', 'group_id']);
            $table->unique(['item_type_id', 'size_id', 'group_id', 'store_id'])->nullsNotDistinct();
        });

        Schema::create('kk_movements', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                 // zugang, abgang, ausgabe, ruecknahme, korrektur
            $table->foreignId('item_type_id')->nullable()->constrained('kk_item_types')->nullOnDelete();
            $table->string('item_name', 255);           // kept when the Artikel is deleted
            $table->foreignId('item_id')->nullable()->constrained('kk_items')->nullOnDelete();
            $table->string('code', 50)->nullable();
            $table->foreignId('size_id')->nullable()->constrained('kk_sizes')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('kk_item_groups')->nullOnDelete();
            $table->string('variant', 200)->nullable(); // group and size as text
            $table->foreignId('store_id')->nullable()->constrained('kk_stores')->nullOnDelete();
            $table->integer('quantity');                // negative only for a Korrektur downwards
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();  // the Kamerad
            $table->string('user_name', 255)->nullable();
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();    // who booked it
            $table->string('by_name', 255)->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestampTz('created_at')->index();
            $table->index(['kind', 'created_at']);
        });

        if (Schema::hasTable('kk_disposals')) {
            foreach (DB::table('kk_disposals')->orderBy('id')->get() as $d) {
                $item = $d->item_id ? DB::table('kk_items')->where('id', $d->item_id)->first() : null;
                DB::table('kk_movements')->insert([
                    'kind' => 'abgang', 'item_type_id' => $d->item_type_id,
                    'item_name' => (string) DB::table('kk_item_types')->where('id', $d->item_type_id)->value('name'),
                    'item_id' => $d->item_id, 'code' => $item?->code, 'size_id' => $d->size_id, 'group_id' => $d->group_id,
                    'variant' => collect([DB::table('kk_item_groups')->where('id', $d->group_id)->value('name'), DB::table('kk_sizes')->where('id', $d->size_id)->value('name')])->filter()->implode(' · ') ?: null,
                    'store_id' => $item?->store_id ?? $main, 'quantity' => $d->quantity,
                    'user_id' => $d->from_user_id, 'user_name' => $d->from_user_id ? DB::table('users')->where('id', $d->from_user_id)->value('name') : null,
                    'by_id' => $d->user_id, 'by_name' => $d->user_id ? DB::table('users')->where('id', $d->user_id)->value('name') : null,
                    'reason' => $d->reason, 'created_at' => $d->created_at,
                ]);
            }
            Schema::drop('kk_disposals');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_movements');
        Schema::table('kk_stock', function (Blueprint $table) {
            $table->dropUnique(['item_type_id', 'size_id', 'group_id', 'store_id']);
            $table->unique(['item_type_id', 'size_id', 'group_id'])->nullsNotDistinct();
        });
        foreach (['kk_issued_items', 'kk_stock', 'kk_items', 'kk_item_types'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('store_id'));
        }
        Schema::dropIfExists('kk_stores');
    }
};
