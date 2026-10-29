<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\ListItemTypes;
use Modules\Kleiderkammer\src\Filament\Widgets\OverviewWidget;
use Modules\Kleiderkammer\src\Filament\Widgets\ReportWidget;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
    $this->pants = ItemType::create(['name' => 'Diensthose', 'code_prefix' => 'DHO', 'is_tracked' => false, 'has_size' => true, 'price_net_cents' => 5000]);
    $this->women = ItemGroup::create(['item_type_id' => $this->pants->id, 'name' => 'Damen', 'sort_order' => 1]);
    $this->forty = Size::create(['item_type_id' => $this->pants->id, 'group_id' => $this->women->id, 'name' => '40', 'sort_order' => 1]);
    Size::create(['item_type_id' => $this->pants->id, 'name' => 'XL', 'sort_order' => 2]);
});

test('the Bestandsbericht is part of the Übersicht now; its old address leads there', function () {
    app(StoreService::class)->receive($this->pants, $this->forty, $this->women, null, 4);

    $this->get('/admin/kleiderkammer')->assertOk()
        ->assertSeeLivewire(OverviewWidget::class)->assertSeeLivewire(ReportWidget::class)
        ->assertDontSee('href="'.url('/admin/kleiderkammer/bestandsbericht').'"', false);
    Livewire::test(OverviewWidget::class)->assertSee('Verfügbar')->assertSee('Offene Leihen');
    Livewire::test(ReportWidget::class)->assertSee('Artikel mit Code')->assertSee('Artikel mit Menge')->assertSee('Letzte Ausleihe')->assertSee('Diensthose');
    $this->get('/admin/kleiderkammer/bestandsbericht')->assertRedirect('/admin/kleiderkammer');
});

test('the Lager shows Lager, Ausgegeben and Gesamt per Artikel', function () {
    app(StoreService::class)->receive($this->pants, $this->forty, $this->women, null, 4);
    app(IssuingService::class)->issueStock(User::factory()->create(), $this->pants, $this->forty, 1, '', $this->women);

    expect(app(StoreService::class)->byType()['t'.$this->pants->id])->toMatchArray(['in_store' => 3, 'issued' => 1, 'total' => 4]);
    Livewire::test(StorePage::class)
        ->assertTableColumnExists('in_store')->assertTableColumnExists('issued')->assertTableColumnExists('total')
        ->assertTableColumnDoesNotExist('variants')
        ->assertSee('Gesamt')->assertDontSee('Größen / Gruppen');
});

test('an Artikel is copied with its groups and sizes, without code prefix; the list has no group column', function () {
    Livewire::test(ListItemTypes::class)
        ->assertTableColumnDoesNotExist('groups.name')
        ->callTableAction('copy', $this->pants, ['name' => 'Diensthose Sommer'])
        ->assertHasNoTableActionErrors();

    $copy = ItemType::where('name', 'Diensthose Sommer')->sole();
    expect($copy)->code_prefix->toBeNull()->price_net_cents->toBe(5000)->is_tracked->toBeFalse()
        ->and($copy->groups()->pluck('name')->all())->toBe(['Damen'])
        ->and($copy->sizes()->pluck('name')->all())->toBe(['40', 'XL'])
        ->and($copy->sizes()->where('name', '40')->value('group_id'))->toBe($copy->groups()->value('id'))
        ->and($this->pants->sizes()->count())->toBe(2);

    $this->get(ItemTypeResource::getUrl('edit', ['record' => $copy]))->assertOk();
});
