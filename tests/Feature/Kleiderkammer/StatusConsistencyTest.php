<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\EditItem;
use Modules\Kleiderkammer\src\Filament\Widgets\OverviewWidget;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\InventoryReport;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create(['name' => 'Karla Kleiderwart']));
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com']);
    $this->pants = ItemType::create(['name' => 'PSA Hose', 'is_tracked' => true, 'has_size' => false]);
});

test('an import marks a piece "ausgegeben" only with its issue; without the Kamerad it becomes verfügbar', function () {
    $payload = ['version' => 1, 'groups' => [
        'catalog' => ['item_types' => [['id' => 7, 'name' => 'PSA Hose', 'is_tracked' => true, 'has_size' => false]]],
        'members' => ['members' => [['id' => 9, 'name' => 'Hanna Hydrant', 'email' => 'hanna.hydrant@example.com', 'status' => 'active']]],
        'inventory' => [
            'items' => [
                ['id' => 1, 'item_type_id' => 7, 'code' => 'WEHRPHO0001', 'status' => 'issued'],
                ['id' => 2, 'item_type_id' => 7, 'code' => 'WEHRPHO0002', 'status' => 'issued'],
            ],
            'issued_items' => [
                ['id' => 1, 'member_id' => 9, 'item_id' => 1, 'quantity' => 1, 'issued_at' => '2027-01-02T10:00:00Z', 'returned_at' => null],
                ['id' => 2, 'member_id' => 99, 'item_id' => 2, 'quantity' => 1, 'issued_at' => '2027-01-02T10:00:00Z', 'returned_at' => null],
            ],
        ],
    ]];

    $report = app(DataTransferService::class)->import($payload);

    expect(Item::where('code', 'WEHRPHO0001')->value('status'))->toBe(ItemStatus::Issued)
        ->and(Item::where('code', 'WEHRPHO0002')->value('status'))->toBe(ItemStatus::Available)
        ->and($report)->toMatchArray(['issued_items' => 1, 'skipped_issued_items' => 1, 'corrected_items' => 1])
        ->and(Movement::where('kind', 'zugang')->where('reason', 'Datentransfer')->count())->toBe(2)
        ->and(Movement::where('kind', 'korrektur')->where('code', 'WEHRPHO0002')->value('reason'))->toContain('bei keinem Kameraden');

    // Every page counts the same now
    expect(app(InventoryReport::class)->summary())->toMatchArray(['issued' => 1, 'available' => 1, 'open_loans' => 1])
        ->and($this->hanna->kleiderkammerItems()->count())->toBe(1)
        ->and(app(StoreService::class)->byType()['t'.ItemType::where('name', 'PSA Hose')->value('id')])->toMatchArray(['in_store' => 1, 'issued' => 1]);
});

test('archived pieces are not counted in the Übersicht, as in the Lager', function () {
    $kept = app(StoreService::class)->receive($this->pants, null, null, 'WEHRPHO0010');
    $gone = app(StoreService::class)->receive($this->pants, null, null, 'WEHRPHO0011');
    $gone->delete();

    expect(app(InventoryReport::class)->summary())->toMatchArray(['tracked_total' => 1, 'available' => 1]);
    Livewire::test(OverviewWidget::class)->assertSee('Verfügbar');
    expect($kept->fresh()->status)->toBe(ItemStatus::Available);
});

test('a piece a Kamerad has can\'t be archived', function () {
    $item = app(StoreService::class)->receive($this->pants, null, null, 'WEHRPHO0020');
    app(IssuingService::class)->issueTracked($this->hanna, $item);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])->callAction('delete')->assertNotified('Teil ist ausgegeben');
    expect($item->fresh()->trashed())->toBeFalse();

});

test('the repair sets the status after the issues, in both directions, and the migration cleans up existing data', function () {
    $orphan = Item::create(['item_type_id' => $this->pants->id, 'code' => 'WEHRPHO0030', 'status' => 'issued']);
    $orphan->delete(); // archived, like after a clean-up by hand
    $lent = Item::create(['item_type_id' => $this->pants->id, 'code' => 'WEHRPHO0031', 'status' => 'available']);
    IssuedItem::create(['user_id' => $this->hanna->id, 'item_id' => $lent->id, 'quantity' => 1, 'issued_at' => now()]);

    (require base_path('modules/Kleiderkammer/database/migrations/2027_01_08_000000_repair_kk_item_status.php'))->up();

    expect(Item::withTrashed()->where('code', 'WEHRPHO0030')->value('status'))->toBe(ItemStatus::Available)
        ->and($lent->fresh()->status)->toBe(ItemStatus::Issued)
        ->and(Movement::where('kind', 'korrektur')->pluck('code')->sort()->values()->all())->toBe(['WEHRPHO0030', 'WEHRPHO0031']);

    // Nothing left to repair
    expect(app(StoreService::class)->repairStatus())->toBe(0);
});

test('the Lager sorts equal values by Artikel', function () {
    $zebra = ItemType::create(['name' => 'Zebra-Weste', 'is_tracked' => false, 'has_size' => false]);
    $apple = ItemType::create(['name' => 'Arbeitshandschuh', 'is_tracked' => false, 'has_size' => false]);

    Livewire::test(StorePage::class)
        ->sortTable('issued', 'desc')
        ->assertCanSeeTableRecords(['t'.$apple->id, 't'.$this->pants->id, 't'.$zebra->id], inOrder: true);
});
