<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ausgang (Ausmusterung): what left the Kleiderkammer for good, from the Lager or from a Kamerad, and why
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_type_id')->constrained('kk_item_types')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('kk_items')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('kk_sizes')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('kk_item_groups')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 300);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();  // the Kamerad it was issued to
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();       // who booked it
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_disposals');
    }
};
