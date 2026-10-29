<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Pages\InventoryPage;
use Modules\Kleiderkammer\src\Filament\Pages\MovementsPage;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\ListItemTypes;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\ListMembers;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource\Pages\ListStores;
use Modules\Kleiderkammer\src\Filament\Widgets\ReportWidget;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Models\Store;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->warden = User::factory()->create(['name' => 'Karla Kleiderwart']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->main = Store::firstOrFail();
    $this->north = Store::create(['name' => 'Lager Nord', 'sort_order' => 2]);
    $this->jacket = ItemType::create(['name' => 'PSA-Jacke', 'is_tracked' => true, 'has_size' => true]);
    $this->gloves = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false, 'has_size' => false, 'store_id' => $this->north->id]);
    $this->size = Size::create(['item_type_id' => $this->jacket->id, 'name' => 'L']);
    $this->store = app(StoreService::class);
    $this->actingAs($this->warden);
});

test('a fresh install has the Hauptlager; Zugang, Ausgabe and Rücknahme keep to their Lager', function () {
    expect($this->main->name)->toBe('Hauptlager');

    // Into the Artikel's Lager by default, or into the chosen one
    $this->store->receive($this->gloves, null, null, null, 5);
    $this->store->receive($this->gloves, null, null, null, 2, $this->main);
    expect(Stock::where('store_id', $this->north->id)->value('quantity'))->toBe(5)
        ->and(Stock::where('store_id', $this->main->id)->value('quantity'))->toBe(2);

    // The Artikel's Lager first; back into the Lager it came from
    $loan = app(IssuingService::class)->issueStock($this->hanna, $this->gloves, null, 3);
    expect($loan->store_id)->toBe($this->north->id)->and(Stock::where('store_id', $this->north->id)->value('quantity'))->toBe(2);
    app(IssuingService::class)->return($loan);
    expect(Stock::where('store_id', $this->north->id)->value('quantity'))->toBe(5);

    // The Lager list can be limited to one Lager; an Abgang takes from the chosen Lager
    expect($this->store->byType($this->main->id)['t'.$this->gloves->id]['in_store'])->toBe(2)
        ->and($this->store->byType()['t'.$this->gloves->id]['in_store'])->toBe(7);
    $this->store->dispose($this->gloves, null, null, null, 2, 'Verloren', $this->warden, $this->main);
    expect(Stock::where('store_id', $this->main->id)->value('quantity'))->toBe(0);

    // Every booking is in the Protokoll
    expect(Movement::orderBy('id')->pluck('kind')->all())->toBe(['zugang', 'zugang', 'ausgabe', 'ruecknahme', 'abgang'])
        ->and(Movement::where('kind', 'ausgabe')->sole())->user_name->toBe('Hanna Hydrant')->by_name->toBe('Karla Kleiderwart')->store_id->toBe($this->north->id);
});

test('the Protokoll lists every booking with search and filters', function () {
    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');
    app(IssuingService::class)->issueTracked($this->hanna, $item);
    $this->store->receive($this->gloves, null, null, null, 4);
    $stock = Stock::where('item_type_id', $this->gloves->id)->sole();
    $this->store->correct($stock, 3, $this->warden, 'Nach dem Zählen');

    $component = Livewire::test(MovementsPage::class);
    $component->assertCanSeeTableRecords(Movement::all())
        ->assertSee('Zugang')->assertSee('Ausgabe')->assertSee('Korrektur')->assertSee('-1')->assertSee('Nach dem Zählen')
        ->searchTable('WEHRPJA0001')->assertCanSeeTableRecords(Movement::where('code', 'WEHRPJA0001')->get())
        ->assertCanNotSeeTableRecords(Movement::whereNull('code')->get());
    Livewire::test(MovementsPage::class)
        ->filterTable('kind', ['ausgabe'])->assertCanSeeTableRecords(Movement::where('kind', 'ausgabe')->get())
        ->assertCanNotSeeTableRecords(Movement::where('kind', '!=', 'ausgabe')->get());
    Livewire::test(MovementsPage::class)
        ->filterTable('user_id', $this->hanna->id)->assertCountTableRecords(1);

    $this->actingAs($this->hanna)->get(MovementsPage::getUrl())->assertForbidden();
});

test('Lager are created and only deleted when empty; the Lager page filters and sorts', function () {
    $this->store->receive($this->gloves, null, null, null, 1);
    $empty = Store::create(['name' => 'Lager Süd']);

    Livewire::test(ListStores::class)
        ->callTableAction('delete', $this->north)
        ->callTableAction('delete', $empty);
    expect(Store::whereKey($this->north->id)->exists())->toBeTrue()->and(Store::whereKey($empty->id)->exists())->toBeFalse();

    Livewire::test(StorePage::class)
        ->assertSee('Abgang buchen')->assertDontSee('Ausgang buchen')
        ->assertCanSeeTableRecords(['t'.$this->gloves->id, 't'.$this->jacket->id], inOrder: true)
        ->sortTable('type', 'desc')->assertCanSeeTableRecords(['t'.$this->jacket->id, 't'.$this->gloves->id], inOrder: true)
        ->filterTable('tracked', 'code')->assertCanNotSeeTableRecords(['t'.$this->gloves->id])
        ->resetTableFilters()->filterTable('store', $this->main->id)->assertTableColumnStateSet('in_store', 0, 't'.$this->gloves->id)
        ->callAction('receive', ['item_type_id' => $this->jacket->id, 'store_id' => $this->north->id, 'size_id' => $this->size->id, 'code' => 'WEHRPJA0042'])
        ->assertHasNoActionErrors();
    expect(Item::where('code', 'WEHRPJA0042')->value('store_id'))->toBe($this->north->id);
});

test('Kameraden: list without Abteilung, Dienstgrad and Status; the profile has Zugang and Abgang, the photo and messages only when there are some', function () {
    Livewire::test(ListMembers::class)
        ->assertTableColumnDoesNotExist('groups.name')->assertTableColumnDoesNotExist('rank')->assertTableColumnDoesNotExist('status')
        ->assertSee('Kameraden');

    // Zugang on the profile goes to this Kamerad straight away
    Livewire::test(EditMember::class, ['record' => $this->hanna->getRouteKey()])
        ->assertActionExists('receive')->assertActionExists('dispose')
        ->callAction('receive', ['item_type_id' => $this->jacket->id, 'store_id' => $this->main->id, 'size_id' => $this->size->id, 'code' => 'WEHRPJA0100', 'issue' => true])
        ->assertHasNoActionErrors();
    $item = Item::where('code', 'WEHRPJA0100')->sole();
    expect($item->currentIssuance()->value('user_id'))->toBe($this->hanna->id);

    $loan = $item->currentIssuance()->firstOrFail();
    Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->hanna, 'pageClass' => EditMember::class])
        ->assertTableColumnHidden('messages_count');
    EquipmentMessage::create(['user_id' => $this->hanna->id, 'issued_item_id' => $loan->id, 'item_label' => 'PSA-Jacke', 'body' => 'Reißverschluss defekt']);
    Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $this->hanna, 'pageClass' => EditMember::class])
        ->assertTableColumnVisible('messages_count')->assertSee('1 (1 offen)')
        ->mountTableAction('messages', $loan)
        ->tap(fn ($c) => expect((string) $c->instance()->getMountedAction()->getModalContent())->toContain('Reißverschluss defekt'));

    $this->hanna->forceFill(['avatar_updated_at' => now()])->save();
    $this->get(MemberResource::getUrl('edit', ['record' => $this->hanna]))
        ->assertOk()->assertSee($this->hanna->fresh()->avatarUrl(), false);
});

test('Artikel: every column sorts, columns can be hidden, several are copied or deleted at once', function () {
    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');

    Livewire::test(ListItemTypes::class)
        ->assertSee('Artikel')
        ->sortTable('name')->assertCanSeeTableRecords([$this->gloves, $this->jacket], inOrder: true)
        ->sortTable('price_gross', 'desc')
        ->assertTableColumnExists('store.name')
        ->callTableBulkAction('copySelected', [$this->gloves, $this->jacket]);
    expect(ItemType::where('name', 'like', '% (Kopie)')->pluck('name')->sort()->values()->all())->toBe(['Handschuhe (Kopie)', 'PSA-Jacke (Kopie)']);

    $copy = ItemType::where('name', 'Handschuhe (Kopie)')->sole();
    Livewire::test(ListItemTypes::class)->callTableBulkAction('deleteSelected', [$copy, $this->jacket])->assertNotified();
    expect(ItemType::whereKey($copy->id)->exists())->toBeFalse()->and(ItemType::whereKey($this->jacket->id)->exists())->toBeTrue();
});

test('Übersicht shows five each, sorted; Inventar has the two tiles', function () {
    foreach (range(1, 7) as $i) {
        $type = ItemType::create(['name' => 'Menge '.$i, 'is_tracked' => false, 'has_size' => false]);
        $this->store->receive($type, null, null, null, $i);
    }
    Stock::whereHas('itemType', fn ($q) => $q->where('name', 'Menge 7'))->update(['quantity' => 0]);
    $data = Livewire::test(ReportWidget::class)->assertSee('Letzte Ausleihe')->assertSee('Kamerad')->viewData('stockByType');
    expect($data->pluck('name')->all())->toBe(['Menge 7', 'Menge 1', 'Menge 2', 'Menge 3', 'Menge 4']);

    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0005');
    app(IssuingService::class)->issueTracked($this->hanna, $item);
    Livewire::test(ReportWidget::class)->assertSee(MemberResource::getUrl('edit', ['record' => $this->hanna]), false);

    Livewire::test(InventoryPage::class)->assertSee('Gesamtsumme netto')->assertSee('Gesamtanzahl');
});
