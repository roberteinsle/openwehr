<?php

use App\Models\Contact;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Facades\Cache;
use Modules\Kalender\src\Models\Event;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Objektplaene\src\Models\PlanObject;

function searchModules(array $keys, bool $on = true): void
{
    Module::whereIn('key', $keys)->update(['is_active' => $on]);
    Cache::forget('modules_active_keys');
}

beforeEach(function () {
    searchModules(['kalender', 'mitteilungen', 'objektplaene']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['kalender', 'mitteilungen'] as $module) {
        $this->member->moduleAccess()->create(['module' => $module]);
    }
});

test('the header has a search field left of Profil and the phone a magnifier', function () {
    $html = $this->actingAs($this->member)->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="header-search"')->toContain('action="'.route('search').'"')
        ->and(strpos($html, 'id="header-search"'))->toBeLessThan(strpos($html, 'href="'.route('profile').'" class="inline-flex'))
        ->and($html)->toContain('aria-label="Suchen"');
});

test('the search needs a login', function () {
    $this->get('/suche?q=kalender')->assertRedirect();
});

test('modules are found by name, also without umlauts, and only those the Kamerad may use', function () {
    $this->actingAs($this->member)->get('/suche?q=kalender')->assertOk()
        ->assertSee('Module und Seiten')
        ->assertSee(route('kalender.index'), false);

    // Objektpläne is on, but Hanna has no access
    $this->actingAs($this->member)->get('/suche?q=objekt')->assertOk()->assertDontSee('Objektpläne');

    $this->actingAs($this->member)->get('/suche?q=mitteilung')->assertSee('Mitteilungen');
    $this->actingAs($this->member)->get('/suche?q=Benachrichtigungen')->assertSee(route('notifications.index'), false);
});

test('Termine and Mitteilungen are found, only those the Kamerad may see', function () {
    $jf = Group::create(['name' => 'Jugendfeuerwehr']);
    $start = now()->addDays(3)->setTime(18, 0);
    $grill = Event::create(['title' => 'Grillabend am Gerätehaus', 'type' => 'veranstaltung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(3)]);
    Event::create(['title' => 'Grillabend Jugend', 'type' => 'veranstaltung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(3)])->groups()->attach($jf);
    Announcement::create(['title' => 'Salate für Samstag', 'body' => 'Zum Grillabend bitte Salate mitbringen.']);
    Announcement::create(['title' => 'Nur für die Jugend', 'body' => 'Grillabend der Jugend'])->groups()->attach($jf);

    $this->actingAs($this->member)->get('/suche?q=grillabend')->assertOk()
        ->assertSee('Termine')
        ->assertSee('Grillabend am Gerätehaus')
        ->assertSee(route('kalender.show', $grill), false)
        ->assertSee('Salate für Samstag')
        ->assertDontSee('Grillabend Jugend')
        ->assertDontSee('Nur für die Jugend');
});

test('sources of switched-off modules and modules without access stay out', function () {
    PlanObject::create(['name' => 'Grundschule Musterstadt', 'object_type' => array_key_first(PlanObject::TYPES), 'address' => 'Schulstraße 1, Musterstadt']);

    $this->actingAs($this->member)->get('/suche?q=grundschule')->assertDontSee('Grundschule Musterstadt');

    $this->member->moduleAccess()->create(['module' => 'objektplaene']);
    $this->actingAs($this->member)->get('/suche?q=grundschule')->assertSee('Grundschule Musterstadt');

    searchModules(['objektplaene'], false);
    $this->actingAs($this->member)->get('/suche?q=grundschule')->assertDontSee('Grundschule Musterstadt');
});

test('Ansprechpartner are found for everyone', function () {
    Contact::create(['role' => 'Gerätewart', 'name' => 'Bernd Blaulicht', 'phone' => '0123 456789']);

    $this->actingAs($this->member)->get('/suche?q=gerätewart')->assertSee('Bernd Blaulicht')->assertSee('0123 456789');
});

test('short terms and wildcards find nothing by accident', function () {
    Announcement::create(['title' => 'Irgendwas']);

    $this->actingAs($this->member)->get('/suche?q=a')->assertSee('mindestens 2 Zeichen')->assertDontSee('Irgendwas');
    $this->actingAs($this->member)->get('/suche?q=%25%25')->assertOk()->assertDontSee('Irgendwas')->assertSee('Nichts gefunden');
    expect(SearchRegistry::like('50%_x'))->toBe('%50\%\_x%');
});

test('SQL injection payloads in the search are harmless', function (string $payload) {
    $this->actingAs($this->member)->get('/suche?q='.urlencode($payload))->assertOk()->assertDontSee('SQLSTATE');
})->with(["' OR '1'='1", "' OR 1=1 --", "'; DROP TABLE users; --", '1\' UNION SELECT NULL--']);

test('a failing source does not break the search', function () {
    app(SearchRegistry::class)->register('test.kaputt', null, 'Kaputt', fn () => throw new RuntimeException('boom'));

    $this->actingAs($this->member)->get('/suche?q=kalender')->assertOk()->assertSee(route('kalender.index'), false)->assertDontSee('Kaputt');
});

test('the 3×3 menu has a module filter', function () {
    $this->actingAs($this->member)->get('/')
        ->assertSee('id="launcher-filter"', false)
        ->assertSee('data-launcher-module', false)
        ->assertSee('Überall suchen');
});
