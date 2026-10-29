<?php

use App\Models\Module;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\CreateItem;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\EditItemType;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\ListItemTypes;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers\SizesRelationManager;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
});

test('item form offers tracked item types', function () {
    ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);

    Livewire::test(CreateItem::class)
        ->assertFormFieldExists('item_type_id', function ($field) {
            return array_values($field->getOptions()) === ['Einsatzjacke'];
        });
});

test('item form explains when no tracked item type exists', function () {
    ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);

    Livewire::test(CreateItem::class)
        ->assertSee('Es gibt noch keinen Artikel mit „Einzeln erfasst“');
});

test('item type list can be filtered by category and washing', function () {
    $schutz = ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);
    $dienst = ItemCategory::create(['name' => 'Dienstkleidung', 'sort_order' => 2]);
    $jacke = ItemType::create(['name' => 'Einsatzjacke', 'category_id' => $schutz->id, 'is_washable' => true]);
    $hemd = ItemType::create(['name' => 'Diensthemd', 'category_id' => $dienst->id, 'is_washable' => false]);

    Livewire::test(ListItemTypes::class)
        ->filterTable('category_id', $schutz->id)
        ->assertCanSeeTableRecords([$jacke])
        ->assertCanNotSeeTableRecords([$hemd])
        ->resetTableFilters()
        ->filterTable('is_washable', false)
        ->assertCanSeeTableRecords([$hemd])
        ->assertCanNotSeeTableRecords([$jacke]);
});

test('sizes can be added to an item type', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'has_size' => true]);
    Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 1]);

    Livewire::test(SizesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditItemType::class,
    ])
        ->callAction(TestAction::make('create')->table(), ['name' => 'XL'])
        ->assertHasNoFormErrors();

    expect($type->sizes()->pluck('name')->all())->toBe(['M', 'XL']);
});

test('a size cannot be added twice to the same item type', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'has_size' => true]);
    Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 1]);

    Livewire::test(SizesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditItemType::class,
    ])
        ->callAction(TestAction::make('create')->table(), ['name' => 'M'])
        ->assertHasFormErrors(['name' => 'unique']);
});

test('a size in use cannot be deleted', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'has_size' => true, 'is_tracked' => true]);
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 1]);
    Item::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    Livewire::test(SizesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditItemType::class,
    ])
        ->callAction(TestAction::make('delete')->table($size));

    expect($size->fresh())->not->toBeNull();
});

test('size management is hidden for item types without sizes', function () {
    $type = ItemType::create(['name' => 'Handschuhe', 'has_size' => false]);

    expect(SizesRelationManager::canViewForRecord(
        $type, EditItemType::class,
    ))->toBeFalse();
});

test('scanner page loads the scanner script in the head and shows a start button', function () {
    // TestCase disables Vite; this stub just echoes the requested entry point.
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('<script type="module" data-entry="'.implode(',', (array) $entrypoints).'"></script>');
        }
    });

    $html = $this->get('/admin/kleiderkammer/scanner')->assertOk()->getContent();

    $head = substr($html, 0, strpos($html, '</head>'));
    expect($head)->toContain('data-entry="modules/Kleiderkammer/resources/js/scanner.js"');
    expect($html)->toContain('Kamera starten');
});
