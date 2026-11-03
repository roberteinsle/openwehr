<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Pieces with code whose status didn't match the issues (e.g. imported as "ausgegeben" without the issue,
// because its Kamerad wasn't found): the status follows the open issues now, each change is a Korrektur in
// the Protokoll. Plain queries, so the migration doesn't depend on the services of a later version.
return new class extends Migration
{
    public function up(): void
    {
        $open = fn ($q) => $q->select(DB::raw(1))->from('kk_issued_items')->whereColumn('kk_issued_items.item_id', 'kk_items.id')
            ->whereNull('kk_issued_items.returned_at')->whereNull('kk_issued_items.deleted_at');
        $fixes = [
            ['issued', 'available', 'whereNotExists', 'als ausgegeben geführt, aber bei keinem Kameraden – jetzt verfügbar'],
            ['available', 'issued', 'whereExists', 'offene Ausgabe an einen Kameraden – jetzt ausgegeben'],
        ];

        foreach ($fixes as [$from, $to, $where, $why]) {
            foreach (DB::table('kk_items')->where('status', $from)->{$where}($open)->get() as $item) {
                DB::table('kk_items')->where('id', $item->id)->update(['status' => $to, 'updated_at' => now()]);
                DB::table('kk_movements')->insert([
                    'kind' => 'korrektur', 'item_type_id' => $item->item_type_id,
                    'item_name' => (string) DB::table('kk_item_types')->where('id', $item->item_type_id)->value('name'),
                    'item_id' => $item->id, 'code' => $item->code, 'size_id' => $item->size_id, 'group_id' => $item->group_id,
                    'variant' => collect([DB::table('kk_item_groups')->where('id', $item->group_id)->value('name'), DB::table('kk_sizes')->where('id', $item->size_id)->value('name')])->filter()->implode(' · ') ?: null,
                    'store_id' => $item->store_id, 'quantity' => 0, 'reason' => 'Status bereinigt (Update): '.$why, 'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data correction only
    }
};
