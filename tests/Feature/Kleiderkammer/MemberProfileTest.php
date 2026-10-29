<?php

use App\Enums\MemberStatus;
use App\Models\Module;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\EditMember;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\IssuancesRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers\SizesRelationManager;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->kleiderwart = User::factory()->create();
    $this->kleiderwart->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->actingAs($this->kleiderwart);

    $this->hanna = User::factory()->create([
        'name' => 'Hanna Hydrant', 'rank' => 'Gruppenführerin',
    ]);
});

function issuances(User $member)
{
    return Livewire::test(IssuancesRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditMember::class]);
}

function profileSizes(User $member)
{
    return Livewire::test(SizesRelationManager::class, ['ownerRecord' => $member, 'pageClass' => EditMember::class]);
}

// ─── profile page ────────────────────────────────────────────────────────────

test('kleiderwart opens a member profile from the member list', function () {
    $this->get(MemberResource::getUrl('edit', ['record' => $this->hanna]))
        ->assertOk()
        ->assertSee('Kleiderkammerprofil: Hanna Hydrant')
        ->assertSee('Gruppenführerin')
        ->assertSee('Konfektionsgrößen')
        ->assertSee('Ausrüstung');
});

test('kleiderwart can edit the notes but nothing else of the member', function () {
    Livewire::test(EditMember::class, ['record' => $this->hanna->getRouteKey()])
        ->fillForm(['notes' => 'Helm muss getauscht werden'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->hanna->refresh();
    expect($this->hanna->notes)->toBe('Helm muss getauscht werden');
    expect($this->hanna->rank)->toBe('Gruppenführerin');
    expect($this->hanna->status)->toBe(MemberStatus::Active);
});

test('plain members cannot open profiles', function () {
    $member = User::factory()->create();
    $member->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => null]);

    $this->actingAs($member)->get(MemberResource::getUrl('edit', ['record' => $this->hanna]))->assertForbidden();
});

// ─── clothing sizes ──────────────────────────────────────────────────────────

test('clothing sizes are stored per item type, once per type', function () {
    $trousers = ItemType::create(['name' => 'Diensthose', 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $trousers->id, 'name' => 'Damen']);
    $w40 = Size::create(['item_type_id' => $trousers->id, 'group_id' => $women->id, 'name' => '40']);

    profileSizes($this->hanna)
        ->callAction(TestAction::make('create')->table(), ['item_type_id' => $trousers->id, 'group_id' => $women->id, 'size_id' => $w40->id])
        ->assertHasNoFormErrors();

    expect($this->hanna->kleiderkammerSizes()->first()->size->name)->toBe('40');

    profileSizes($this->hanna)
        ->callAction(TestAction::make('create')->table(), ['item_type_id' => $trousers->id, 'group_id' => $women->id, 'size_id' => $w40->id])
        ->assertHasFormErrors(['item_type_id' => 'Für diesen Artikel ist schon eine Größe hinterlegt.']);
});

// ─── equipping ───────────────────────────────────────────────────────────────

test('a tracked item can be issued from the profile by its code', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    issuances($this->hanna)
        ->callAction(TestAction::make('issueTracked')->table(), ['item_id' => $item->id])
        ->assertNotified('Teil ausgegeben.');

    expect($item->fresh()->status->value)->toBe('issued');
    expect($this->hanna->kleiderkammerItems()->value('item_id'))->toBe($item->id);
});

test('an item that is already issued cannot be issued again', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);

    issuances($this->hanna)
        ->callAction(TestAction::make('issueTracked')->table(), ['item_id' => $item->id])
        ->assertNotified('Das Teil ist nicht mehr verfügbar.');

    expect(IssuedItem::count())->toBe(0);
});

test('issuing from stock suggests the profile size and books the right stock', function () {
    $shirt = ItemType::create(['name' => 'Diensthemd', 'is_tracked' => false, 'has_size' => true]);
    $m = Size::create(['item_type_id' => $shirt->id, 'name' => 'M', 'sort_order' => 1]);
    $l = Size::create(['item_type_id' => $shirt->id, 'name' => 'L', 'sort_order' => 2]);
    Stock::create(['item_type_id' => $shirt->id, 'size_id' => $m->id, 'quantity' => 4]);
    Stock::create(['item_type_id' => $shirt->id, 'size_id' => $l->id, 'quantity' => 4]);
    MemberSize::create(['user_id' => $this->hanna->id, 'item_type_id' => $shirt->id, 'size_id' => $l->id]);

    issuances($this->hanna)
        ->mountAction(TestAction::make('issueStock')->table())
        ->fillForm(['item_type_id' => $shirt->id])
        ->assertSchemaStateSet(['size_id' => $l->id])
        ->fillForm(['quantity' => 2])
        ->callMountedAction()
        ->assertNotified('Aus Bestand ausgegeben.');

    expect(Stock::where('size_id', $l->id)->value('quantity'))->toBe(2);
    expect(Stock::where('size_id', $m->id)->value('quantity'))->toBe(4);
});

test('issuing more than the stock shows a message', function () {
    $gloves = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false, 'has_size' => false]);
    Stock::create(['item_type_id' => $gloves->id, 'quantity' => 1]);

    issuances($this->hanna)
        ->callAction(TestAction::make('issueStock')->table(), ['item_type_id' => $gloves->id, 'quantity' => 3])
        ->assertNotified('Nicht genug Bestand.');

    expect(Stock::where('item_type_id', $gloves->id)->value('quantity'))->toBe(1);
});

test('equipment can be taken back from the profile', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    $issued = IssuedItem::create(['user_id' => $this->hanna->id, 'item_id' => $item->id, 'issued_at' => now()]);

    issuances($this->hanna)
        ->assertCanSeeTableRecords([$issued])
        ->callAction(TestAction::make('return')->table($issued));

    expect($issued->fresh()->returned_at)->not->toBeNull();
    expect($item->fresh()->status->value)->toBe('available');
});

test('members who left cannot be equipped', function () {
    $this->hanna->update(['status' => MemberStatus::Left]);

    issuances($this->hanna)
        ->assertActionHidden(TestAction::make('issueTracked')->table())
        ->assertActionHidden(TestAction::make('issueStock')->table());
});

test('items to issue can be found by name, group and size, not only by code', function () {
    $jacket = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $helmet = ItemType::create(['name' => 'Helm', 'is_tracked' => true]);
    $sGuard = ItemGroup::create(['item_type_id' => $jacket->id, 'name' => 'S-Guard']);
    $l = Size::create(['item_type_id' => $jacket->id, 'name' => 'L']);
    $m = Size::create(['item_type_id' => $jacket->id, 'name' => 'M']);
    $jacketL = Item::create(['item_type_id' => $jacket->id, 'group_id' => $sGuard->id, 'size_id' => $l->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $jacketM = Item::create(['item_type_id' => $jacket->id, 'size_id' => $m->id, 'code' => 'WEHREJA0002', 'status' => 'available']);
    $issued = Item::create(['item_type_id' => $jacket->id, 'size_id' => $l->id, 'code' => 'WEHREJA0003', 'status' => 'issued']);
    $helm = Item::create(['item_type_id' => $helmet->id, 'code' => 'WEHRHLM0001', 'status' => 'available']);

    $results = fn (string $search) => array_keys(IssuancesRelationManager::searchAvailableItems($search));

    expect($results('jacke'))->toEqualCanonicalizing([$jacketL->id, $jacketM->id]);
    expect($results('Jacke L'))->toBe([$jacketL->id]);
    expect($results('s-guard'))->toBe([$jacketL->id]);
    expect($results('HLM'))->toBe([$helm->id]);
    expect($results('0003'))->toBe([]); // already issued
});
