<?php

use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\CreateItem;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\EditItemType;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers\SizesRelationManager;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Services\CodeResolver;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Setting::forget('org_code_prefix');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function kleiderwart(): User
{
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    return $user;
}

// ─── unknown codes from the scanner ──────────────────────────────────────────

test('item type is guessed from the code prefix, longest prefix wins', function () {
    $jacket = ItemType::create(['name' => 'Einsatzjacke', 'code_prefix' => 'EJ', 'is_tracked' => true]);
    $winterJacket = ItemType::create(['name' => 'Winterjacke', 'code_prefix' => 'EJW', 'is_tracked' => true]);

    $resolver = app(CodeResolver::class);
    expect($resolver->guessItemType('WEHREJ0007')?->id)->toBe($jacket->id);
    expect($resolver->guessItemType('WEHREJW0001')?->id)->toBe($winterJacket->id);
    expect($resolver->guessItemType('4006381333931'))->toBeNull();
});

test('a kleiderwart scanning an unknown code lands on the create form', function () {
    $this->actingAs(kleiderwart())
        ->get('/i/WEHREJA0099')
        ->assertRedirect(ItemResource::getUrl('create', ['code' => 'WEHREJA0099']));
});

test('other users still get 404 for unknown codes', function () {
    $member = User::factory()->create();
    $member->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => null]);

    $this->actingAs($member)->get('/i/WEHREJA0099')->assertNotFound();
    auth()->logout();
    $this->get('/i/WEHREJA0099')->assertNotFound();
});

test('the create form is prefilled from the scanned code and can issue the item right away', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'code_prefix' => 'EJA', 'is_tracked' => true]);
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);

    Livewire::actingAs(kleiderwart())
        ->withQueryParams(['code' => 'WEHREJA0099'])
        ->test(CreateItem::class)
        ->assertSee('Der Code WEHREJA0099 ist noch unbekannt')
        ->assertSchemaStateSet(['code' => 'WEHREJA0099', 'item_type_id' => $type->id])
        ->fillForm(['issue_to' => $hanna->id])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Teil an Hanna Hydrant ausgegeben.');

    $item = Item::where('code', 'WEHREJA0099')->first();
    expect($item->status->value)->toBe('issued');
    expect($item->currentIssuance()->value('user_id'))->toBe($hanna->id);
});

test('without a member the scanned item is only registered', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'code_prefix' => 'EJA', 'is_tracked' => true]);
    Livewire::actingAs(kleiderwart())
        ->withQueryParams(['code' => 'WEHREJA0100'])
        ->test(CreateItem::class)
        ->call('create')
        ->assertHasNoFormErrors();

    $item = Item::where('code', 'WEHREJA0100')->first();
    expect($item->item_type_id)->toBe($type->id);
    expect($item->status->value)->toBe('available');
});

// ─── sizes: filters and readable messages ────────────────────────────────────

test('duplicate sizes get a readable German message', function () {
    $this->actingAs(User::factory()->admin()->create());
    $type = ItemType::create(['name' => 'Diensthose', 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    Size::create(['item_type_id' => $type->id, 'name' => 'M']);
    Size::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'name' => '40']);

    $manager = fn () => Livewire::test(SizesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditItemType::class]);

    $manager()->callAction(TestAction::make('create')->table(), ['name' => 'M'])
        ->assertHasFormErrors(['name' => 'Diese Größe gibt es für diesen Artikel schon.']);
    $manager()->callAction(TestAction::make('create')->table(), ['name' => '40', 'group_id' => $women->id])
        ->assertHasFormErrors(['name' => 'Diese Größe gibt es in dieser Gruppe schon.']);
    $manager()->callAction(TestAction::make('create')->table(), ['name' => ''])
        ->assertHasFormErrors(['name' => 'Größe muss ausgefüllt werden.']);
});

test('sizes can be filtered by group and searched by name', function () {
    $this->actingAs(User::factory()->admin()->create());
    $type = ItemType::create(['name' => 'Diensthose', 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    $men = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Herren']);
    $w40 = Size::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'name' => '40']);
    $m40 = Size::create(['item_type_id' => $type->id, 'group_id' => $men->id, 'name' => '40']);
    $m52 = Size::create(['item_type_id' => $type->id, 'group_id' => $men->id, 'name' => '52']);
    $one = Size::create(['item_type_id' => $type->id, 'name' => 'Einheitsgröße']);

    Livewire::test(SizesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditItemType::class])
        ->filterTable('group', $men->id)
        ->assertCanSeeTableRecords([$m40, $m52])
        ->assertCanNotSeeTableRecords([$w40, $one])
        ->searchTable('40')
        ->assertCanSeeTableRecords([$m40])
        ->assertCanNotSeeTableRecords([$w40, $m52, $one])
        ->searchTable('')
        ->resetTableFilters()
        ->filterTable('group', 'none')
        ->assertCanSeeTableRecords([$one])
        ->assertCanNotSeeTableRecords([$w40, $m40, $m52]);
});
