<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\EditItemType;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers\SizesRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->warden = User::factory()->create(['name' => 'Karla Kleiderwart']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->jacket = ItemType::create(['name' => 'PSA-Jacke', 'is_tracked' => true, 'has_size' => true]);
    $this->gloves = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false, 'has_size' => false]);
    $this->size = Size::create(['item_type_id' => $this->jacket->id, 'name' => 'L']);
    $this->store = app(StoreService::class);
    $this->actingAs($this->warden);
});

test('Abgang from the Lager: a piece by its code or a quantity, always with a reason', function () {
    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');
    $this->store->receive($this->gloves, null, null, null, 5);

    Livewire::test(StorePage::class)
        ->callAction('dispose', ['item_type_id' => $this->jacket->id, 'code' => 'WEHRPJA0001', 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(StorePage::class)
        ->callAction('dispose', ['item_type_id' => $this->jacket->id, 'code' => 'WEHRPJA0001', 'reason' => 'Verschlissen'])
        ->assertHasNoActionErrors()
        ->callAction('dispose', ['item_type_id' => $this->gloves->id, 'quantity' => 2, 'reason' => 'Defekt / beschädigt'])
        ->assertHasNoActionErrors();

    expect($item->refresh()->status)->toBe(ItemStatus::Retired)->and($item->notes)->toContain('Ausgemustert: Verschlissen')
        ->and(Stock::where('item_type_id', $this->gloves->id)->value('quantity'))->toBe(3)
        ->and(Movement::where('kind', 'abgang')->count())->toBe(2)
        ->and(Movement::where('kind', 'abgang')->where('item_id', $item->id)->sole())->by_id->toBe($this->warden->id)->user_id->toBeNull()->reason->toBe('Verschlissen');

    // Not more than there is, not a piece twice, not an unknown code
    expect(fn () => $this->store->dispose($this->gloves, null, null, null, 4, 'Verloren', $this->warden))->toThrow(ValidationException::class)
        ->and(fn () => $this->store->dispose($this->jacket, null, null, 'WEHRPJA0001', 1, 'Verloren', $this->warden))->toThrow(ValidationException::class)
        ->and(fn () => $this->store->dispose($this->jacket, null, null, 'GIBTESNICHT', 1, 'Verloren', $this->warden))->toThrow(ValidationException::class);

    // A piece a Kamerad has is retired with him, not from the Lager
    $issued = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0002');
    app(IssuingService::class)->issueTracked($this->hanna, $issued);
    expect(fn () => $this->store->dispose($this->jacket, null, null, 'WEHRPJA0002', 1, 'Verloren', $this->warden))->toThrow(ValidationException::class);

    // The Artikel page lists its Buchungen and books an Abgang for its Artikel
    Livewire::test(StoreTypePage::class, ['type' => $this->gloves->id])
        ->assertSee('Protokoll')->assertSee('Abgang')->assertSee('Defekt / beschädigt')->assertSee('gebucht von Karla Kleiderwart')
        ->callAction('dispose', ['quantity' => 1, 'reason' => 'Verloren'])->assertHasNoActionErrors();
    expect(Stock::where('item_type_id', $this->gloves->id)->value('quantity'))->toBe(2);
});

test('in a Kamerad\'s Ausrüstung a piece is retired instead of taken back; it does not go back into the Lager', function () {
    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0003');
    $loan = app(IssuingService::class)->issueTracked($this->hanna, $item);
    $this->store->receive($this->gloves, null, null, null, 5);
    $gloves = app(IssuingService::class)->issueStock($this->hanna, $this->gloves, null, 2);

    Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->hanna, 'pageClass' => EditMember::class])
        ->assertTableActionVisible('dispose', $loan)
        ->callTableAction('dispose', $loan, ['reason' => 'Kontaminiert, nicht mehr zu reinigen'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('dispose', $gloves, ['reason' => 'Verschlissen']);

    expect($loan->refresh()->returned_at)->not->toBeNull()->and($item->refresh()->status)->toBe(ItemStatus::Retired)
        ->and($gloves->refresh()->returned_at)->not->toBeNull()
        ->and(Stock::where('item_type_id', $this->gloves->id)->value('quantity'))->toBe(3)
        ->and(Movement::where('kind', 'abgang')->where('user_id', $this->hanna->id)->orderBy('id')->pluck('quantity')->all())->toBe([1, 2])
        ->and(IssuedItem::whereNull('returned_at')->count())->toBe(0);

    // The Inventar no longer counts them
    expect(collect($this->store->inventory()['rows'])->pluck('quantity', 'name')->all())->toBe(['Handschuhe' => 3]);
});

test('sizes are deleted several at once; those in use stay', function () {
    $free = Size::create(['item_type_id' => $this->jacket->id, 'name' => 'XS']);
    $alsoFree = Size::create(['item_type_id' => $this->jacket->id, 'name' => 'XXL']);
    $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0009');

    Livewire::test(SizesRelationManager::class, ['ownerRecord' => $this->jacket, 'pageClass' => EditItemType::class])
        ->callTableBulkAction('deleteSizes', [$free, $alsoFree, $this->size])
        ->assertNotified();

    expect(Size::where('item_type_id', $this->jacket->id)->pluck('name')->all())->toBe(['L']);
});
