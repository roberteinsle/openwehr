<?php

use App\Filament\Pages\MonitorSettingsPage;
use App\Http\Middleware\NoIndex;
use App\Livewire\Monitor\Board;
use App\Models\Group;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\MonitorRegistry;
use App\Support\MonitorSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kalender\src\Models\Event;
use Modules\Mitteilungen\src\Livewire\Editor as EditorComponent;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;
use Modules\Uebungsdienste\src\Models\Service;

function monitorModules(array $keys, bool $on = true): void
{
    Module::whereIn('key', $keys)->update(['is_active' => $on]);
    Cache::forget('modules_active_keys');
}

beforeEach(function () {
    config(['monitor.token' => 'geheimer-wachen-schluessel']);
    monitorModules(['uebungsdienste', 'kalender', 'fahrzeugbuchung', 'mitteilungen']);

    $this->aktive = Group::create(['name' => 'Aktive', 'sort_order' => 1]);
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->musik = Group::create(['name' => 'Musikzug', 'sort_order' => 3]);
});

// ─── Access ───────────────────────────────────────────────────────────────

test('the station link with the right key opens the monitor without login', function () {
    $this->get('/monitor/geheimer-wachen-schluessel')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', NoIndex::HEADER)
        ->assertSee('wire:poll.60s', false);

    expect(auth()->check())->toBeFalse();
});

test('without or with a wrong key the monitor does not exist, admins open it directly', function () {
    $this->get('/monitor')->assertNotFound();
    $this->get('/monitor/falsch')->assertNotFound();
    $this->actingAs(User::factory()->create())->get('/monitor')->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get('/monitor')->assertOk();
});

test('no key configured means admins only', function () {
    config(['monitor.token' => '']);

    $this->get('/monitor/')->assertNotFound();
    $this->get('/monitor/geheimer-wachen-schluessel')->assertNotFound();
});

// ─── Default layout ───────────────────────────────────────────────────────

test('by default four fields: Dienste per Abteilung, Fahrzeugbelegung, Mitteilungen and next Termine', function () {
    expect(MonitorSettings::get())->toMatchArray([
        'fields' => 4,
        'panels' => ['uebungsdienste.abteilungen', 'fahrzeugbuchung.belegung', 'mitteilungen.aktuell', 'kalender.termine'],
        'group_ids' => [],
    ]);

    Livewire::test(Board::class)
        ->assertSee('Nächste Dienste je Abteilung')
        ->assertSee('Fahrzeugbelegung')
        ->assertSee('Mitteilungen')
        ->assertSee('Nächste Termine');
});

test('without the Übungsdienstplaner the first field shows the Termine per Abteilung', function () {
    monitorModules(['uebungsdienste'], false);

    expect(MonitorSettings::get()['panels'][0])->toBe('kalender.abteilungen')
        ->and(app(MonitorRegistry::class)->options())->not->toHaveKey('uebungsdienste.abteilungen');
});

test('a content whose module was switched off stays an empty field', function () {
    MonitorSettings::save(2, ['mitteilungen.aktuell', 'fahrzeugbuchung.belegung'], []);
    monitorModules(['mitteilungen'], false);

    Livewire::test(Board::class)
        ->assertSee('Kein Inhalt gewählt')
        ->assertSee('Fahrzeugbelegung');
});

// ─── Contents ─────────────────────────────────────────────────────────────

test('Dienste appear in the column of their Abteilung, Dienste of the whole Wehr in every column', function () {
    $day = now('Europe/Berlin')->addDays(3)->toDateString();
    Service::create(['date' => $day, 'starts_at' => '19:00', 'ends_at' => '21:00', 'topic' => 'Knoten und Stiche', 'owner_group_id' => $this->jf->id]);
    Service::create(['date' => $day, 'starts_at' => '19:30', 'ends_at' => '21:30', 'topic' => 'Atemschutz Grundlagen', 'owner_group_id' => $this->aktive->id]);
    Service::create(['date' => $day, 'starts_at' => '18:00', 'topic' => 'Gerätehaus putzen']);
    // Long over: not on the monitor
    Service::create(['date' => now('Europe/Berlin')->subDays(2)->toDateString(), 'starts_at' => '19:00', 'topic' => 'Vorbei']);

    MonitorSettings::save(1, ['uebungsdienste.abteilungen'], []);
    $html = Livewire::test(Board::class)->html();

    // Only Abteilungen with own Dienste get a column; Musikzug has none
    expect($html)->toContain('Aktive')->toContain('Jugendfeuerwehr')->not->toContain('Musikzug')
        ->and($html)->toContain('Knoten und Stiche')->toContain('Atemschutz Grundlagen')->not->toContain('Vorbei')
        ->and(substr_count($html, 'Gerätehaus putzen'))->toBe(2);
});

test('Nächste Termine leave out the Übungsdienste while their own field is on the screen', function () {
    $start = now()->addDays(2)->setTime(19, 0);
    Event::create(['title' => 'Übungsdienst', 'type' => 'uebung', 'managed_by' => 'uebungsdienste', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2)]);
    Event::create(['title' => 'Jahreshauptversammlung', 'type' => 'veranstaltung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2)]);

    MonitorSettings::save(2, ['uebungsdienste.abteilungen', 'kalender.termine'], []);
    $html = Livewire::test(Board::class)->html();
    expect(substr_count($html, 'Jahreshauptversammlung'))->toBe(1)
        ->and($html)->not->toContain('>Übungsdienst<');

    // Without the Übungsdienste field the Termin is listed
    MonitorSettings::save(1, ['kalender.termine'], []);
    Livewire::test(Board::class)->assertSee('Übungsdienst')->assertSee('Jahreshauptversammlung');
});

test('chosen Abteilungen get a column even without Dienste', function () {
    MonitorSettings::save(1, ['uebungsdienste.abteilungen'], [$this->aktive->id, $this->jf->id, $this->musik->id]);

    Livewire::test(Board::class)
        ->assertSee('Musikzug')
        ->assertSee('Nichts geplant.');
});

test('the Termine per Abteilung leave out personal Termine', function () {
    $start = now()->addDays(2)->setTime(18, 0);
    $probe = Event::create(['title' => 'Probe Musikzug', 'type' => 'uebung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2)]);
    $probe->groups()->attach($this->musik);
    $personal = Event::create(['title' => 'Führerschein vorzeigen', 'type' => 'sonstiges', 'starts_at' => $start, 'ends_at' => $start->copy()->addHour()]);
    $personal->invitees()->attach(User::factory()->create());

    MonitorSettings::save(2, ['kalender.abteilungen', 'kalender.termine'], []);

    Livewire::test(Board::class)
        ->assertSee('Musikzug')
        ->assertSee('Probe Musikzug')
        ->assertDontSee('Führerschein vorzeigen');
});

test('Mitteilungen show only those ticked for the monitor and meant for everyone, important first', function () {
    Announcement::create(['title' => 'Grillabend Samstag', 'body' => 'Bitte **Salate** mitbringen.', 'on_monitor' => true]);
    Announcement::create(['title' => 'Neue Alarmordnung', 'important' => true, 'on_monitor' => true]);
    Announcement::create(['title' => 'Nicht angehakt']);
    Announcement::create(['title' => 'Schon vorbei', 'visible_until' => now()->subMinute(), 'on_monitor' => true]);
    Announcement::create(['title' => 'Nur für die Jugend', 'on_monitor' => true])->groups()->attach($this->jf);

    MonitorSettings::save(1, ['mitteilungen.aktuell'], []);

    Livewire::test(Board::class)
        ->assertSeeInOrder(['Neue Alarmordnung', 'Grillabend Samstag'])
        ->assertSee('Bitte Salate mitbringen.')
        ->assertDontSee('Nicht angehakt')
        ->assertDontSee('Schon vorbei')
        ->assertDontSee('Nur für die Jugend');
});

test('the editor puts a Mitteilung for everyone on the monitor, but refuses one with an audience', function () {
    $redakteur = User::factory()->create();
    $redakteur->moduleAccess()->create(['module' => 'mitteilungen', 'role' => 'redakteur']);

    Livewire::actingAs($redakteur)->test(EditorComponent::class)
        ->set('title', 'Übung am Samstag')->set('onMonitor', true)->set('notify', 'none')
        ->call('save')->assertHasNoErrors();
    expect(Announcement::where('title', 'Übung am Samstag')->value('on_monitor'))->toBeTrue();

    Livewire::actingAs($redakteur)->test(EditorComponent::class)
        ->set('title', 'Nur Jugend')->set('onMonitor', true)->set('groupIds', [(string) $this->jf->id])->set('notify', 'none')
        ->call('save')->assertHasErrors('onMonitor');
    expect(Announcement::where('title', 'Nur Jugend')->exists())->toBeFalse();

    // The service never keeps the flag on a Mitteilung with an audience
    $a = app(AnnouncementService::class)->save(null, ['title' => 'Direkt', 'on_monitor' => true], [$this->jf->id], $redakteur);
    expect($a->refresh()->on_monitor)->toBeFalse();
});

test('the refresh interval is chosen in the settings and used by the screen', function () {
    expect(MonitorSettings::get()['refresh'])->toBe(1);
    $this->get('/monitor/geheimer-wachen-schluessel')->assertSee('wire:poll.60s', false)->assertSee('aktualisiert sich jede Minute');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(MonitorSettingsPage::class)
        ->fillForm(['fields' => '2', 'panel_0' => 'kalender.termine', 'panel_1' => 'mitteilungen.aktuell', 'refresh' => '15'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MonitorSettings::get()['refresh'])->toBe(15);
    Livewire::test(Board::class)->assertSee('wire:poll.900s', false)->assertSee('aktualisiert sich alle 15 Minuten');

    Livewire::test(MonitorSettingsPage::class)->fillForm(['refresh' => '7'])->call('save')->assertHasFormErrors(['refresh']);
    expect(fn () => MonitorSettings::save(1, ['kalender.termine'], [], 7))->toThrow(ValidationException::class)
        ->and(MonitorSettings::get()['refresh'])->toBe(15);

    // Saving without an interval keeps it; the defaults bring back one minute
    MonitorSettings::save(1, ['kalender.termine'], []);
    expect(MonitorSettings::get()['refresh'])->toBe(15);
    Livewire::test(MonitorSettingsPage::class)->call('resetToDefaults');
    expect(MonitorSettings::get()['refresh'])->toBe(1);
});

test('the Fahrzeugbelegung shows the state with shortened names', function () {
    $mtw = Vehicle::factory()->create(['name' => 'MTW neu', 'short_name' => 'MTW-N']);
    Booking::factory()->create([
        'vehicle_id' => $mtw->id,
        'user_id' => User::factory()->create(['name' => 'Hanna Hydrant'])->id,
        'purpose' => 'Jugendfeuerwehr Ausflug',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
    ]);

    MonitorSettings::save(1, ['fahrzeugbuchung.belegung'], []);

    Livewire::test(Board::class)
        ->assertSee('MTW-N')
        ->assertSee('Jugendfeuerwehr Ausflug')
        ->assertSee('Hanna H.')
        ->assertDontSee('Hanna Hydrant');
});

// ─── Settings page ────────────────────────────────────────────────────────

test('only admins reach the settings, which show the station link', function () {
    $this->actingAs(User::factory()->create())->get('/admin/wachen-monitor')->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get('/admin/wachen-monitor')
        ->assertOk()
        ->assertSee('/monitor/geheimer-wachen-schluessel')
        ->assertSee('Nächste Dienste je Abteilung');
});

test('the settings page comes right after Einstellungen, before DIVERA-Webhook and Feedback', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/wachen-monitor')->getContent();
    preg_match_all('/fi-sidebar-item-label[^>]*>\s*([^<]+?)\s*</', $html, $m);
    $labels = $m[1];

    $settings = array_search('Einstellungen', $labels, true);
    expect($labels[$settings + 1] ?? null)->toBe('Wachen-Monitor')
        ->and($labels[$settings + 2] ?? null)->toBe('DIVERA-Webhook')
        ->and($labels[$settings + 3] ?? null)->toBe('Feedback');
});

test('an admin chooses two fields with their contents and the Abteilungen', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(MonitorSettingsPage::class)
        ->fillForm(['fields' => '2', 'panel_0' => 'kalender.abteilungen', 'panel_1' => 'mitteilungen.aktuell', 'group_ids' => [$this->aktive->id, $this->musik->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MonitorSettings::get())->toMatchArray([
        'fields' => 2,
        'group_ids' => [$this->aktive->id, $this->musik->id],
    ])->and(array_slice(MonitorSettings::get()['panels'], 0, 2))->toBe(['kalender.abteilungen', 'mitteilungen.aktuell']);
});

test('unknown contents and field counts are refused', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(MonitorSettingsPage::class)
        ->fillForm(['fields' => '3'])
        ->call('save')
        ->assertHasFormErrors(['fields']);

    Livewire::test(MonitorSettingsPage::class)
        ->fillForm(['fields' => '1', 'panel_0' => 'gibt.es.nicht'])
        ->call('save')
        ->assertHasFormErrors(['panel_0']);

    expect(fn () => MonitorSettings::save(1, ['gibt.es.nicht'], []))->toThrow(ValidationException::class)
        ->and(Setting::where('key', MonitorSettings::KEY)->exists())->toBeFalse();
});

test('defaults can be restored', function () {
    MonitorSettings::save(1, ['mitteilungen.aktuell'], [$this->jf->id]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(MonitorSettingsPage::class)->call('resetToDefaults');

    expect(MonitorSettings::get()['fields'])->toBe(4)
        ->and(MonitorSettings::get()['group_ids'])->toBe([])
        ->and(MonitorSettings::get()['panels'][0])->toBe('uebungsdienste.abteilungen');
});
