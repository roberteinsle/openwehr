<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\InventoryPage;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\EditItemType;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\InventoryExport;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->warden = User::factory()->create(['name' => 'Karla Kleiderwart']);
    $this->warden->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'kleiderkammer']);

    $psa = ItemCategory::create(['name' => 'PSA', 'sort_order' => 1]);
    $this->jacket = ItemType::create(['category_id' => $psa->id, 'name' => 'PSA-Jacke', 'is_tracked' => true, 'has_size' => true, 'price_net_cents' => 42000, 'sort_order' => 1]);
    $this->gloves = ItemType::create(['category_id' => $psa->id, 'name' => 'Handschuhe', 'is_tracked' => false, 'has_size' => false, 'price_net_cents' => 2990, 'sort_order' => 2]);
    $this->size = Size::create(['item_type_id' => $this->jacket->id, 'name' => 'L']);
    $this->store = app(StoreService::class);
});

test('a type with code comes in piece by piece with its code, one without code as a quantity', function () {
    expect(fn () => $this->store->receive($this->jacket, $this->size, null, ''))->toThrow(ValidationException::class);

    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');
    expect($item)->toBeInstanceOf(Item::class)->and($item->status)->toBe(ItemStatus::Available);
    expect(fn () => $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001'))->toThrow(ValidationException::class);

    $this->store->receive($this->gloves, null, null, null, 10);
    $this->store->receive($this->gloves, null, null, 'ignored', 5);
    expect(Stock::where('item_type_id', $this->gloves->id)->sole()->quantity)->toBe(15);
    expect(fn () => $this->store->receive($this->gloves, null, null, null, 0))->toThrow(ValidationException::class);

    // Issued pieces leave the Lager but stay in the list
    app(IssuingService::class)->issueTracked($this->hanna, $item);
    app(IssuingService::class)->issueStock($this->hanna, $this->gloves, null, 2);
    $levels = $this->store->levels()->keyBy('type');
    expect($levels['PSA-Jacke'])->toMatchArray(['in_store' => 0, 'issued' => 1, 'size' => 'L', 'tracked' => true])
        ->and($levels['Handschuhe'])->toMatchArray(['in_store' => 13, 'issued' => 2, 'tracked' => false]);
});

test('the Lager page books a Zugang and asks for the code only where the Artikel has one', function () {
    $this->actingAs($this->warden);

    Livewire::test(StorePage::class)
        ->callAction('receive', ['item_type_id' => $this->jacket->id, 'size_id' => $this->size->id, 'code' => ''])
        ->assertHasActionErrors(['code' => 'required']);

    Livewire::test(StorePage::class)
        ->callAction('receive', ['item_type_id' => $this->jacket->id, 'size_id' => $this->size->id, 'code' => 'WEHRPJA0007'])
        ->assertHasNoActionErrors()
        ->callAction('receive', ['item_type_id' => $this->gloves->id, 'quantity' => 4])
        ->assertHasNoActionErrors()
        ->assertSee('PSA-Jacke')->assertSee('Handschuhe');

    expect(Item::where('code', 'WEHRPJA0007')->exists())->toBeTrue()
        ->and(Stock::where('item_type_id', $this->gloves->id)->value('quantity'))->toBe(4);

    // One row per Artikel, a click opens its page; Einzelteile and Massenbestand have no menu entry or list any more
    $this->get(StorePage::getUrl())->assertOk()
        ->assertSee('href="'.StoreTypePage::getUrl(['type' => $this->jacket->id]).'"', false)
        ->assertDontSee('href="'.url('/admin/kleiderkammer/einzelteile').'"', false)
        ->assertDontSee('href="'.url('/admin/kleiderkammer/massenbestand').'"', false);
    $this->get('/admin/kleiderkammer/einzelteile')->assertRedirect(StorePage::getUrl());
    $this->get('/admin/kleiderkammer/massenbestand')->assertRedirect(StorePage::getUrl());

    $this->actingAs($this->hanna)->get(StorePage::getUrl())->assertForbidden();
});

test('the Artikel page lists the pieces with code and issues, takes back and books a Zugang there', function () {
    $this->actingAs($this->warden);
    $item = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');
    $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0002');

    Livewire::test(StoreTypePage::class, ['type' => $this->jacket->id])
        ->assertSee('PSA-Jacke')->assertSee('Einzeln mit Code')->assertSee('420,00 € netto / 499,80 € brutto')
        ->assertSeeHtml('L: <b>2</b> im Lager')
        ->assertCanSeeTableRecords([$item])
        ->callTableAction('issue', $item, ['user_id' => $this->hanna->id])
        ->assertHasNoTableActionErrors()
        ->callAction('receive', ['size_id' => $this->size->id, 'code' => 'WEHRPJA0003'])
        ->assertHasNoActionErrors();
    expect($item->refresh()->status)->toBe(ItemStatus::Issued)
        ->and(Item::where('code', 'WEHRPJA0003')->value('item_type_id'))->toBe($this->jacket->id);

    Livewire::test(StoreTypePage::class, ['type' => $this->jacket->id])->callTableAction('return', $item);
    expect($item->refresh()->status)->toBe(ItemStatus::Available);

    // Gloves: quantities, issued from there; a Zugang adds to the quantity
    $this->store->receive($this->gloves, null, null, null, 5);
    $stock = Stock::where('item_type_id', $this->gloves->id)->sole();
    Livewire::test(StoreTypePage::class, ['type' => $this->gloves->id])
        ->assertSee('Menge ohne Code')
        ->callTableAction('issue', $stock, ['user_id' => $this->hanna->id, 'quantity' => 2])
        ->callAction('receive', ['quantity' => 10])->assertHasNoActionErrors();
    expect($stock->refresh()->quantity)->toBe(13);

    $this->actingAs($this->hanna)->get(StoreTypePage::getUrl(['type' => $this->jacket->id]))->assertForbidden();
});

test('the net price is typed and the gross price follows', function () {
    $this->actingAs($this->warden);
    expect(ItemType::gross(10000))->toBe(11900)->and($this->jacket->priceGrossCents())->toBe(49980);

    Livewire::test(EditItemType::class, ['record' => $this->gloves->getRouteKey()])
        ->assertSchemaStateSet(['price_net_cents' => '29,90'])
        ->fillForm(['price_net_cents' => '12,50'])
        ->assertSee('14,88 €')
        ->call('save')->assertHasNoFormErrors();
    expect($this->gloves->refresh()->price_net_cents)->toBe(1250);

    Livewire::test(EditItemType::class, ['record' => $this->gloves->getRouteKey()])
        ->fillForm(['price_net_cents' => 'zwölf'])->call('save')->assertHasFormErrors(['price_net_cents']);
});

test('the Inventar counts what the Kleiderkammer owns (Lager and issued), with sums, as PDF and as A4 Excel', function () {
    $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0001');
    $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0002');
    $issued = $this->store->receive($this->jacket, $this->size, null, 'WEHRPJA0003');
    app(IssuingService::class)->issueTracked($this->hanna, $issued);
    $this->store->receive($this->gloves, null, null, null, 10);
    ItemType::create(['name' => 'Feuerwehrleine', 'is_tracked' => false, 'has_size' => false]);
    $rope = ItemType::where('name', 'Feuerwehrleine')->sole();
    $this->store->receive($rope, null, null, null, 3);

    $inventory = $this->store->inventory();
    // The issued jacket belongs to the Kleiderkammer too
    expect($inventory['rows'][2])->toBe(['nr' => 3, 'name' => 'PSA-Jacke', 'quantity' => 3, 'net' => 42000, 'net_sum' => 126000, 'gross' => 49980, 'gross_sum' => 149940])
        ->and($inventory['rows'][1])->toMatchArray(['nr' => 2, 'name' => 'Handschuhe', 'quantity' => 10, 'net_sum' => 29900, 'gross' => 3558, 'gross_sum' => 35580])
        ->and($inventory['rows'][0])->toMatchArray(['nr' => 1, 'name' => 'Feuerwehrleine', 'net' => null, 'net_sum' => null])
        ->and($inventory)->toMatchArray(['net_total' => 155900, 'gross_total' => 185520, 'quantity_total' => 16, 'unpriced' => 1]);

    $this->actingAs($this->warden);
    Livewire::test(InventoryPage::class)
        ->assertSee('Erstellt von: Karla Kleiderwart (Kleiderwart)')
        ->assertSee('1.559,00 €')->assertSee('1.855,20 €')->assertSee('1 Artikel ohne Preis')
        ->callAction('pdf')->assertFileDownloaded('inventar-kleiderkammer-'.now('Europe/Berlin')->format('Y-m-d').'.pdf')
        ->callAction('xlsx')->assertFileDownloaded('inventar-kleiderkammer-'.now('Europe/Berlin')->format('Y-m-d').'.xlsx');

    $export = app(InventoryExport::class);
    expect(substr($export->pdf($this->warden), 0, 4))->toBe('%PDF');

    // The Excel file is set up for A4 portrait, fitted to the page width, and names stay text
    $this->gloves->update(['name' => '=HYPERLINK("http://example.com")']);
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $export->xlsx($this->warden));
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);
    expect($sheet)->toContain('paperSize="9"')->toContain('orientation="portrait"')->toContain('fitToWidth="1"')
        ->toContain('Karla Kleiderwart')->toContain('Gesamtsumme')
        ->not->toContain('<f>');

    $this->actingAs($this->hanna)->get(InventoryPage::getUrl())->assertForbidden();
});
