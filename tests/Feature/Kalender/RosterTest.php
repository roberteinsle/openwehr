<?php

use App\Models\Contact;
use App\Models\Group;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-01-05 12:00', 'Europe/Berlin'));
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->hanna = kalenderUser(null, ['name' => 'Hanna Hydrant']);
});

test('the Dienstplan lists the Termine of a year or half-year with time, place, Kleidung, Teilnehmer and Ausführend', function () {
    kalenderEvent('2027-02-04 19:00', '21:00', ['title' => 'Übungsdienst', 'location' => 'Wache', 'organizer' => 'Vorstand']);
    kalenderEvent('2027-03-06 19:00', '23:00', ['title' => 'Jahreshauptversammlung', 'type' => 'dienst', 'organizer' => 'Wehrführung']);
    kalenderEvent('2027-09-10 18:00', '20:00', ['title' => 'Jugenddienst'], [$this->jf->id]);
    kalenderEvent('2027-10-01 10:00', '11:00', ['title' => 'Abgesagt', 'cancelled_at' => now()]);
    // Personal Termine (only single invitees, e.g. Führerschein) are left out
    app(EventService::class)->create(['title' => 'Führerschein läuft ab', 'type' => 'sonstiges',
        'starts_at' => Carbon::parse('2027-04-01', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-04-02', 'Europe/Berlin'), 'all_day' => true], [], null, null, false, [$this->hanna->id]);
    Contact::create(['role' => 'Wehrführer', 'name' => 'Bernd Blaulicht', 'phone' => '0123 456789', 'sort_order' => 1]);

    $admin = kalenderUser('terminverwalter');
    $this->actingAs($admin)->get(route('kalender.roster', ['jahr' => 2027, 'kontakte' => 1, 'hinweis' => "Abmelden ist erforderlich!\nBitte rechtzeitig."]))
        ->assertOk()->assertSee('Dienstplan 2027')
        ->assertSeeInOrder(['Do 04.02.2027', '19:00', 'Übungsdienst', 'Wache', 'Alle', 'Vorstand', 'Jahreshauptversammlung', 'Wehrführung', 'Jugenddienst', 'Jugendfeuerwehr'])
        ->assertDontSee('Abgesagt')->assertDontSee('Führerschein läuft ab')
        ->assertSee('Abmelden ist erforderlich!')->assertSee('Wehrführer:')->assertSee('0123 456789');

    // 1. Halbjahr, only Dienste
    $this->actingAs($admin)->get(route('kalender.roster', ['jahr' => 2027, 'zeitraum' => 'h1', 'arten' => ['dienst']]))
        ->assertSee('Jahreshauptversammlung')->assertDontSee('Jugenddienst')->assertDontSee('>Übungsdienst<', false);

    // A Kamerad outside the Jugendfeuerwehr doesn't see its Termine
    $this->actingAs($this->hanna)->get(route('kalender.roster', ['jahr' => 2027]))->assertOk()->assertSee('Jahreshauptversammlung')->assertDontSee('Jugenddienst');
});
