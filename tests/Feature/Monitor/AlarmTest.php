<?php

use App\Filament\Pages\DiveraWebhookPage;
use App\Livewire\Monitor\Board;
use App\Models\Alarm;
use App\Models\Module;
use App\Models\User;
use App\Services\AlarmService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;

beforeEach(function () {
    config(['monitor.token' => 'geheimer-wachen-schluessel']);
    Http::preventStrayRequests();
    AlarmService::saveSettings(true, 60, true);
    $this->url = AlarmService::url();
    // DIVERA's "Vollständiges Objekt"
    $this->alarm = ['id' => 247, 'foreign_id' => 'some-id', 'title' => 'B 3 – Brand Scheune', 'text' => 'Starke Rauchentwicklung', 'address' => 'Dorfstraße 1, Musterstadt',
        'lat' => 51.1657, 'lng' => 10.4515, 'priority' => true, 'notification_type' => 0, 'cluster' => [], 'group' => [], 'user_cluster_relation' => [],
        'ts_create' => 1589528749, 'ts_update' => 1589528749];
});

test('DIVERA posts an alarm as JSON and the Wachen-Monitor switches to the alarm view with a map', function () {
    $this->postJson($this->url, $this->alarm)->assertOk()->assertJson(['ok' => true]);

    $alarm = Alarm::sole();
    expect($alarm)->keyword->toBe('B 3 – Brand Scheune')->external_id->toBe('247')->priority->toBeTrue()
        ->latitude->toBe(51.1657)->located_by->toBe('divera')->source->toBe('divera');

    Livewire::test(Board::class)
        ->assertSee('ALARM')->assertSee('B 3 – Brand Scheune')->assertSee('Dorfstraße 1, Musterstadt')->assertSee('Starke Rauchentwicklung')
        ->assertSee('Sonderrechte')->assertSee('alarmMap({ lat: 51.1657, lng: 10.4515 })', false);

    // The quick check of the page sees the alarm
    $this->getJson('/monitor-status/geheimer-wachen-schluessel')->assertOk()->assertJson(['state' => AlarmService::state()]);
    expect(AlarmService::state())->not->toBe('');
});

test('the same DIVERA id updates the alarm, "closed" ends it', function () {
    $this->postJson($this->url, $this->alarm)->assertOk();
    $this->postJson($this->url, [...$this->alarm, 'text' => 'Nachalarmierung DLK'])->assertOk();

    expect(Alarm::count())->toBe(1)->and(Alarm::sole()->message)->toBe('Nachalarmierung DLK');

    $this->postJson($this->url, [...$this->alarm, 'closed' => true])->assertOk();
    expect(Alarm::sole()->ended_at)->not->toBeNull()->and(AlarmService::current())->toBeNull();
});

test('without coordinates the address is located: Google with a key, else OpenStreetMap', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '51.5', 'lon' => '9.9']])]);

    $this->postJson($this->url, ['id' => 1, 'title' => 'H 1', 'address' => 'Hauptstraße 1, Musterstadt', 'lat' => 0, 'lng' => 0])->assertOk();

    expect(Alarm::sole())->latitude->toBe(51.5)->longitude->toBe(9.9)->located_by->toBe('osm');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'nominatim') && str_contains($request->header('User-Agent')[0], 'openwehr'));
});

test('with OpenStreetMap switched off and no Google key the alarm has no map', function () {
    AlarmService::saveSettings(true, 60, false);

    $this->postJson($this->url, ['title' => 'H 1', 'address' => 'Hauptstraße 1, Musterstadt'])->assertOk();

    expect(Alarm::sole()->hasPosition())->toBeFalse();
    Livewire::test(Board::class)->assertSee('Hauptstraße 1, Musterstadt')->assertDontSee('alarmMap(', false);
});

test('a wrong key, a switched-off webhook, GET and no JSON are refused', function () {
    $this->postJson(route('webhooks.alarm', ['key' => str_repeat('x', 48)]), $this->alarm)->assertNotFound();
    $this->get($this->url)->assertStatus(405);
    $this->call('POST', $this->url, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Feuer')->assertStatus(422);

    AlarmService::saveSettings(false, 60, true);
    $this->postJson($this->url, $this->alarm)->assertNotFound();

    expect(Alarm::count())->toBe(0);
});

test('an empty body ("Ohne Inhalt") is still an alarm', function () {
    $this->postJson($this->url, [])->assertOk();

    Livewire::test(Board::class)->assertSee('ALARM');
});

test('the alarm disappears after the chosen time or when ended, and not at all with "gar nicht"', function () {
    $this->postJson($this->url, $this->alarm)->assertOk();
    $this->travel(61)->minutes();
    expect(AlarmService::current())->toBeNull();
    Livewire::test(Board::class)->assertDontSee('B 3 – Brand Scheune');

    $this->travelBack();
    AlarmService::saveSettings(true, 0, true);
    expect(AlarmService::current())->toBeNull();
});

test('the alarm goes on to the Einsatzbericht as a draft when its DIVERA drafts are on; tests do not', function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    DiveraWebhook::setEnabled(true);

    $this->postJson($this->url, $this->alarm)->assertOk();
    expect(Report::count())->toBe(1)->and(Report::sole()->keyword)->toBe('B 3 – Brand Scheune');

    // An update of the same alarm makes no second draft
    $this->postJson($this->url, [...$this->alarm, 'text' => 'Update'])->assertOk();
    expect(Report::count())->toBe(1);

    app(AlarmService::class)->test(['keyword' => 'Probe', 'latitude' => 51.0, 'longitude' => 10.0]);
    expect(Report::count())->toBe(1);
});

test('only admins reach the settings, which sit between Wachen-Monitor and Feedback and show the address', function () {
    $this->actingAs(User::factory()->create())->get('/admin/divera-webhook')->assertForbidden();

    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/divera-webhook')
        ->assertOk()->assertSee($this->url)->assertSee('POST application/json')->getContent();

    preg_match_all('/fi-sidebar-item-label[^>]*>\s*([^<]+?)\s*</', $html, $m);
    $i = array_search('Wachen-Monitor', $m[1], true);
    expect($m[1][$i + 1] ?? null)->toBe('DIVERA-Webhook')->and($m[1][$i + 2] ?? null)->toBe('Feedback');
});

test('an admin saves the settings and simulates an alarm, then ends it', function () {
    AlarmService::saveSettings(false, 60, true);
    $admin = User::factory()->admin()->create(['name' => 'Bernd Blaulicht']);
    $this->actingAs($admin);

    Livewire::test(DiveraWebhookPage::class)
        ->fillForm(['enabled' => true, 'show_minutes' => '30', 'osm' => false])
        ->call('save')->assertHasNoFormErrors();
    expect(AlarmService::settings())->toBe(['enabled' => true, 'show_minutes' => 30, 'osm' => false]);

    Livewire::test(DiveraWebhookPage::class)
        ->fillForm(['keyword' => 'TH 1 – Baum auf Straße', 'address' => 'Waldweg', 'latitude' => '51.2', 'longitude' => '10.1'], 'testForm')
        ->call('sendTest');

    $alarm = Alarm::sole();
    expect($alarm->isTest())->toBeTrue()->and($alarm->keyword)->toBe('TH 1 – Baum auf Straße');
    Livewire::test(Board::class)->assertSee('TEST-ALARM')->assertSee('TH 1 – Baum auf Straße');

    Livewire::test(DiveraWebhookPage::class)->assertSee('auf dem Monitor')->call('endAlarm', $alarm->id);
    expect($alarm->refresh()->ended_by)->toBe('Bernd Blaulicht')->and(AlarmService::current())->toBeNull();
});

test('the test alarm can go through the real address', function () {
    Http::fake(function ($request) {
        $response = $this->postJson($request->url(), $request->data());

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $alarm = app(AlarmService::class)->test(['keyword' => 'Über die Adresse'], viaUrl: true);

    expect($alarm->isTest())->toBeTrue()->and($alarm->keyword)->toBe('Über die Adresse');
});

test('a new address keeps the old key away', function () {
    $old = $this->url;
    AlarmService::regenerateKey();

    $this->postJson($old, $this->alarm)->assertNotFound();
    $this->postJson(AlarmService::url(), $this->alarm)->assertOk();
});
