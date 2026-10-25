<?php

use App\Livewire\Dashboard\Home;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\EventTypes;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->admin = User::factory()->admin()->create();
});

test('admins rename, recolour, add, switch off and delete Terminarten', function () {
    $uebung = EventType::where('key', 'uebung')->sole();
    $page = Livewire::actingAs($this->admin)->test(EventTypes::class)->assertSee('Arbeitsdienst')
        ->set('rows.'.$uebung->id.'.label', 'Übungsabend')->set('rows.'.$uebung->id.'.color', '#123456')->call('save')->assertSee('gespeichert');
    expect(EventType::labelOf('uebung'))->toBe('Übungsabend')->and(EventType::colorOf('uebung'))->toBe('#123456');

    $page->set('newLabel', 'Jugenddienst')->set('newColor', '#00aa00')->call('add')->assertHasNoErrors();
    $new = EventType::where('label', 'Jugenddienst')->sole();
    expect($new->key)->toBe('jugenddienst')->and(Event::types())->toHaveKey('jugenddienst');

    // A new Termin can use it; the calendar shows it in its colour
    Livewire::actingAs($this->admin)->test(EventForm::class)->set('title', 'Jugendabend')->set('type', 'jugenddienst')
        ->set('startsAt', '2027-06-10T18:00')->set('endsAt', '2027-06-10T20:00')->call('save')->assertHasNoErrors();
    expect(Event::where('type', 'jugenddienst')->count())->toBe(1);
    $member = kalenderUser(null, ['name' => 'Hanna Hydrant']);
    Livewire::actingAs($member)->test(Home::class)->assertSee('Jugenddienst')->assertSee('color: #00AA00', false)->assertSee('noch 9 Tage');

    // In use or needed by other modules: not deletable, only switched off
    $this->actingAs($this->admin);
    $page->call('delete', $new->id)->assertHasErrors('delete');
    $page->call('delete', $uebung->id)->assertHasErrors('delete');
    $page->set('rows.'.$new->id.'.is_active', false)->call('save');
    expect(Event::types())->not->toHaveKey('jugenddienst')
        ->and(Event::types('jugenddienst'))->toHaveKey('jugenddienst')   // kept for the Termin that has it
        ->and(EventType::labelOf('jugenddienst'))->toBe('Jugenddienst');

    $unused = EventType::create(['key' => 'sitzung', 'label' => 'Sitzung', 'color' => '#000000', 'sort_order' => 99]);
    $page->call('delete', $unused->id)->assertHasNoErrors();
    expect(EventType::find($unused->id))->toBeNull();

    // Only admins
    Livewire::actingAs(kalenderUser('terminverwalter'))->test(EventTypes::class)->assertForbidden();
});
