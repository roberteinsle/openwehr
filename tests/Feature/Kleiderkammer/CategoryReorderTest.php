<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages\ListItemCategories;
use Modules\Kleiderkammer\src\Models\ItemCategory;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
});

test('categories are listed by name A–Z and sorted by their columns, without reordering', function () {
    $a = ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);
    $b = ItemCategory::create(['name' => 'Dienstkleidung', 'sort_order' => 2]);
    $c = ItemCategory::create(['name' => 'Sonstiges', 'sort_order' => 3]);

    Livewire::test(ListItemCategories::class)
        ->assertCanSeeTableRecords([$b, $a, $c], inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords([$c, $a, $b], inOrder: true)
        ->assertTableColumnExists('item_types_count')
        ->assertDontSee('Reihenfolge ändern');
});
