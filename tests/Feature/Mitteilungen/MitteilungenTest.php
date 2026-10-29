<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Support\Markdown;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Editor;
use Modules\Mitteilungen\src\Livewire\Manage;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Modules\Mitteilungen\src\Services\AnnouncementService;

beforeEach(function () {
    Module::where('key', 'mitteilungen')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    $this->member->moduleAccess()->create(['module' => 'mitteilungen']);
    $this->redakteur = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);
    $this->redakteur->moduleAccess()->create(['module' => 'mitteilungen', 'role' => 'redakteur']);
});

test('the module is on after the update and new Kameraden get it automatically', function () {
    expect(Module::find('mitteilungen'))->is_active->toBeTrue()->auto_assign->toBeTrue();
});

test('the migration gives everyone access and keeps the right to post as Redakteur', function () {
    $migration = require base_path('modules/Mitteilungen/database/migrations/2026_10_20_000000_make_mitteilungen_a_module.php');
    $migration->down();

    $poster = User::factory()->create();
    $reader = User::factory()->create();
    DB::table('users')->where('id', $poster->id)->update(['can_post_announcements' => true]);

    $migration->up();

    expect(Schema::hasColumn('users', 'can_post_announcements'))->toBeFalse()
        ->and($poster->refresh()->hasModuleRole('mitteilungen', 'redakteur'))->toBeTrue()
        ->and($reader->refresh()->moduleAccess()->where('module', 'mitteilungen')->exists())->toBeTrue()
        ->and($reader->hasModuleRole('mitteilungen', 'redakteur'))->toBeFalse();
});

test('only admins and Redakteure manage notices', function () {
    $this->actingAs($this->member)->get('/mitteilungen')->assertOk();
    $this->actingAs($this->member)->get('/mitteilungen/verwalten')->assertForbidden();
    $this->actingAs($this->redakteur)->get('/mitteilungen/verwalten')->assertOk();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/mitteilungen/verwalten')->assertOk();
});

test('the start page shows notices only when there are some', function () {
    // The module tile itself is called "Mitteilungen", so look for the board's own link
    Livewire::actingAs($this->member)->test(Home::class)->assertDontSee('Alle Mitteilungen');

    Announcement::create(['title' => 'Grillabend Samstag']);

    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Alle Mitteilungen')->assertSee('Grillabend Samstag');
});

test('a notice is written, shown to its audience and can be mailed', function () {
    Notification::fake();
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->member->groups()->attach($youth);
    $outsider = User::factory()->create();
    $outsider->moduleAccess()->create(['module' => 'mitteilungen']);

    Livewire::actingAs($this->redakteur)->test(Editor::class)
        ->set('title', 'Zeltlager-Anmeldung bis Freitag')
        ->set('body', 'Bitte bei Bernd abgeben.')
        ->set('groupIds', [(string) $youth->id])
        ->set('notify', 'mail')
        ->call('save')
        ->assertHasNoErrors();

    expect(Announcement::sole()->groups->pluck('name')->all())->toBe(['Jugendfeuerwehr']);
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Zeltlager-Anmeldung bis Freitag');
    Livewire::actingAs($outsider)->test(Home::class)->assertDontSee('Zeltlager-Anmeldung bis Freitag');

    Notification::assertSentTo($this->member, AnnouncementNotification::class);
    Notification::assertNotSentTo($outsider, AnnouncementNotification::class);
});

test('notices respect their visibility period', function () {
    Announcement::create(['title' => 'Kommt noch', 'visible_from' => now()->addDay()]);
    Announcement::create(['title' => 'Schon vorbei', 'visible_until' => now()->subMinute()]);
    Announcement::create(['title' => 'Gilt jetzt', 'visible_from' => now()->subDay(), 'visible_until' => now()->addDay()]);

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSee('Gilt jetzt')->assertDontSee('Kommt noch')->assertDontSee('Schon vorbei');
});

test('normal notices can be dismissed on the start page, important ones stay and come first', function () {
    $normal = Announcement::create(['title' => 'Grillabend Samstag']);
    $important = Announcement::create(['title' => 'Atemschutz-Untersuchung Pflicht', 'important' => true]);

    $html = Livewire::actingAs($this->member)->test(Home::class)->html();
    expect(strpos($html, 'Atemschutz-Untersuchung Pflicht'))->toBeLessThan(strpos($html, 'Grillabend Samstag'));

    $this->actingAs($this->member)->from('/')->post(route('mitteilungen.dismiss', $normal))->assertRedirect('/');
    $this->actingAs($this->member)->post(route('mitteilungen.dismiss', $important));

    Livewire::actingAs($this->member)->test(Home::class)->assertDontSee('Grillabend Samstag')->assertSee('Atemschutz-Untersuchung Pflicht');

    // The module page still lists the dismissed one
    $this->actingAs($this->member)->get('/mitteilungen')->assertSee('Grillabend Samstag')->assertSee('auf der Startseite ausgeblendet');
});

test('a period is entered as TT.MM.JJJJ and must be in order', function () {
    Livewire::actingAs($this->redakteur)->test(Editor::class)
        ->set('title', 'Test')->set('visibleFrom', '2027-01-01')->call('save')->assertHasErrors('visibleFrom')
        ->set('visibleFrom', '10.01.2027')->set('visibleUntil', '01.01.2027')->call('save')->assertHasErrors('visibleUntil')
        ->set('visibleUntil', '31.01.2027')->call('save')->assertHasNoErrors();

    $a = Announcement::sole();
    expect($a->visible_from->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('10.01.2027 00:00')
        ->and($a->visible_until->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('31.01.2027 23:59');
});

test('without access to the module no notices appear', function () {
    // With "automatisch freischalten" everyone has access; switched off, only granted Kameraden
    Module::where('key', 'mitteilungen')->update(['auto_assign' => false]);
    Cache::forget('module_auto_assign_mitteilungen');
    Announcement::create(['title' => 'Grillabend Samstag']);
    $noAccess = User::factory()->create();

    Livewire::actingAs($noAccess)->test(Home::class)->assertDontSee('Grillabend Samstag');
    $this->actingAs($noAccess)->get('/mitteilungen')->assertForbidden();
});

test('notices on the start page stand out in orange, important ones in dark red', function () {
    Announcement::create(['title' => 'Grillabend Samstag']);
    Announcement::create(['title' => 'Alarmübung', 'important' => true]);

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSeeHtml('bg-fw-orange')
        ->assertSeeHtml('bg-fw-red-dark')
        ->assertSee('Wichtige Mitteilung');
});

test('Mitteilungen for a Funktion, with attachments, Lesebestätigung and reminder', function () {
    Notification::fake();
    $fn = MemberFunction::create(['name' => 'Maschinist']);
    $sub = MemberFunction::create(['name' => 'Maschinist HLF', 'parent_id' => $fn->id]);
    $this->member->functions()->attach($sub);
    $other = User::factory()->create(['name' => 'Fritz Feuer']);
    $other->moduleAccess()->create(['module' => 'mitteilungen']);

    Livewire::actingAs($this->redakteur)->test(Editor::class)
        ->set('title', 'Neue Betriebsanweisung Pumpe')->set('functionIds', [(string) $fn->id])->set('needsAck', true)
        ->set('uploads', [UploadedFile::fake()->createWithContent('Betriebsanweisung.pdf', "%PDF-1.4\n%%EOF")])->call('save')->assertHasNoErrors();
    $a = Announcement::sole();
    expect($a->needs_ack)->toBeTrue()->and($a->files()->sole()->filename)->toBe('Betriebsanweisung.pdf')
        ->and(app(AnnouncementService::class)->recipients($a)->pluck('name')->all())->toBe(['Hanna Hydrant']);

    // Only for the Maschinisten (incl. sub-functions)
    $this->actingAs($other)->get(route('mitteilungen.index'))->assertDontSee('Neue Betriebsanweisung Pumpe');
    $this->actingAs($other)->get(route('mitteilungen.file', $a->files()->sole()))->assertForbidden();
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Neue Betriebsanweisung Pumpe')->assertSee('Gelesen und verstanden')->assertDontSee('Ausblenden');

    // Opening the page counts as read, not as confirmed
    $this->actingAs($this->member)->get(route('mitteilungen.index'))->assertSee('Betriebsanweisung.pdf');
    $this->get(route('mitteilungen.file', $a->files()->sole()))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $stats = app(AnnouncementService::class)->stats($a);
    expect($stats)->toMatchArray(['recipients' => 1, 'read' => 1, 'acknowledged' => 0])->and($stats['missing']->pluck('name')->all())->toBe(['Hanna Hydrant']);

    Livewire::actingAs($this->redakteur)->test(Manage::class)->call('showStats', $a->id)->assertSee('1 von 1 gelesen, 0 bestätigt')->assertSee('Noch nicht bestätigt: Hanna Hydrant')
        ->call('remind', $a->id)->assertSee('1 Kameraden erinnert');
    Notification::assertSentTo($this->member, AnnouncementNotification::class, fn ($n, $c, $u) => str_starts_with($n->toMail($u)->subject, 'Bitte bestätigen:'));

    $this->actingAs($this->member)->post(route('mitteilungen.acknowledge', $a))->assertRedirect();
    expect(app(AnnouncementService::class)->stats($a)['acknowledged'])->toBe(1);
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Gelesen bestätigt')->assertSee('Ausblenden');
});

test('the archive shows Mitteilungen whose time is over; a filter shows only important ones', function () {
    Announcement::create(['title' => 'Altes Grillfest', 'visible_until' => now()->subDay(), 'created_by' => $this->redakteur->id]);
    Announcement::create(['title' => 'Neue Alarmordnung', 'important' => true, 'created_by' => $this->redakteur->id]);
    Announcement::create(['title' => 'Kuchen im Gerätehaus', 'created_by' => $this->redakteur->id]);

    $this->actingAs($this->member)->get(route('mitteilungen.index'))->assertSee('Neue Alarmordnung')->assertSee('Kuchen')->assertDontSee('Altes Grillfest');
    $this->get(route('mitteilungen.index', ['wichtig' => 1]))->assertSee('Neue Alarmordnung')->assertDontSee('Kuchen');
    $this->get(route('mitteilungen.index', ['ansicht' => 'archiv']))->assertSee('Altes Grillfest')->assertDontSee('Kuchen');
});

test('the text is Markdown: formatted on the start page, in the list and in the mail, with a preview while writing', function () {
    Notification::fake();
    $body = "Hallo zusammen,\nzwei Neuerungen:\n\n## Anmelden mit Code\n\n- **Code** aus der Mail\n- [Hilfe](https://example.com/hilfe)\n\n1. erst dies\n2. dann das";

    Livewire::actingAs($this->redakteur)->test(Editor::class)
        ->set('title', 'Neu in der App')->set('body', $body)
        ->set('preview', true)
        ->assertSee('So erscheint die Mitteilung auf der Startseite')
        ->assertSeeHtml('<h2>Anmelden mit Code</h2>')->assertSeeHtml('<strong>Code</strong>')->assertDontSee('**Code**')
        ->set('preview', false)->assertSee('Formatieren mit Markdown')
        ->set('notify', 'mail')->call('save')->assertHasNoErrors()->assertRedirect(route('mitteilungen.manage'));

    // Start page and list: rendered, a single line break stays a break
    $this->actingAs($this->member)->get(route('home'))
        ->assertSeeHtml('Hallo zusammen,<br>')->assertSeeHtml('<li><strong>Code</strong> aus der Mail</li>')->assertSeeHtml('<ol>');
    $this->actingAs($this->member)->get(route('mitteilungen.index'))
        ->assertSeeHtml('<h2>Anmelden mit Code</h2>')
        ->assertSeeHtml('<a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com/hilfe">Hilfe</a>');

    // Mail: formatted HTML, line breaks kept
    Notification::assertSentTo($this->member, AnnouncementNotification::class, function ($n) {
        $html = preg_replace('/ style="[^"]*"/', '', (string) $n->toMail($this->member)->render());

        return str_contains($html, '<strong>Code</strong>') && str_contains($html, 'Hallo zusammen,<br');
    });
});

test('Markdown never lets HTML, scripts or outside images through', function () {
    $html = (string) Markdown::html("<script>alert(1)</script>\n\n<b onmouseover=x>fett</b>\n\n[klick](javascript:alert(1))\n\n![Logo](https://tracker.example.com/pixel.png)");

    expect($html)->not->toContain('<script')->not->toContain('<b ')->not->toContain('javascript:')->not->toContain('<img')
        ->toContain('&lt;script&gt;')
        ->toContain('<a target="_blank" rel="noopener noreferrer nofollow" href="https://tracker.example.com/pixel.png">Logo</a>');
});

test('a new notice goes to bell and push by default; an edit notifies nobody unless chosen', function () {
    Notification::fake();
    Livewire::actingAs($this->redakteur)->test(Editor::class)->assertSet('notify', 'push')
        ->set('title', 'Dienst heute erst um 19:30')->call('save')->assertHasNoErrors()->assertRedirect(route('mitteilungen.manage'));
    expect(session('mitteilungen.flash'))->toContain('in die Glocke und als Push verschickt');
    Notification::assertSentTo($this->member, AnnouncementNotification::class, fn ($n, array $channels) => $channels === ['database']);

    Notification::fake();
    $a = Announcement::sole();
    Livewire::actingAs($this->redakteur)->test(Editor::class, ['announcement' => $a])->assertSet('notify', 'none')->assertSet('title', 'Dienst heute erst um 19:30')
        ->set('title', 'Dienst heute erst um 19:45')->call('save')->assertHasNoErrors();
    expect($a->fresh()->title)->toBe('Dienst heute erst um 19:45');
    Notification::assertNothingSent();

    Livewire::actingAs($this->redakteur)->test(Editor::class, ['announcement' => $a])->set('notify', 'mail')->call('save');
    Notification::assertSentTo($this->member, AnnouncementNotification::class, fn ($n, array $channels) => in_array('mail', $channels, true));

    Livewire::actingAs($this->redakteur)->test(Editor::class)->set('title', 'X')->set('notify', 'alle')->call('save')->assertHasErrors('notify');
});

test('Verwalten is an overview by state; new and edit are pages of their own, linked from the list for the Redakteure', function () {
    $current = Announcement::create(['title' => 'Grillabend Samstag', 'created_by' => $this->redakteur->id]);
    Announcement::create(['title' => 'Kommt noch', 'visible_from' => now()->addWeek()]);
    Announcement::create(['title' => 'Altes Grillfest', 'visible_until' => now()->subDay()]);

    $this->actingAs($this->redakteur)->get(route('mitteilungen.manage'))->assertOk()
        ->assertSeeInOrder(['Neue Mitteilung', 'Aktuell sichtbar', 'Grillabend Samstag', 'Geplant', 'Kommt noch', 'Abgelaufen', 'Altes Grillfest'])
        ->assertSee(route('mitteilungen.edit', $current), false)->assertDontSee('wire:model="title"', false);
    $this->get(route('mitteilungen.create'))->assertOk()->assertSee('Neue Mitteilung')->assertSee('Veröffentlichen');
    $this->get(route('mitteilungen.edit', $current))->assertOk()->assertSee('Mitteilung bearbeiten')->assertSee('Grillabend Samstag');

    // The list of all Mitteilungen: edit link for Redakteure only
    $this->get(route('mitteilungen.index'))->assertSee(route('mitteilungen.edit', $current), false)->assertSee(route('mitteilungen.create'), false);
    $this->actingAs($this->member)->get(route('mitteilungen.index'))->assertOk()->assertDontSee(route('mitteilungen.edit', $current), false);
    $this->get(route('mitteilungen.edit', $current))->assertForbidden();
    $this->get(route('mitteilungen.create'))->assertForbidden();
});

test('Kameraden without the role can not use the editor or the overview, not even through Livewire', function () {
    $a = Announcement::create(['title' => 'Grillabend Samstag']);
    Livewire::actingAs($this->member)->test(Editor::class)->assertForbidden();
    Livewire::actingAs($this->member)->test(Manage::class)->assertForbidden();

    // Attachments of another Mitteilung can't be removed from the editor of this one
    $other = Announcement::create(['title' => 'Andere']);
    $file = $other->files()->create(['filename' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 3, 'data' => base64_encode('pdf')]);
    Livewire::actingAs($this->redakteur)->test(Editor::class, ['announcement' => $a])->call('deleteFile', $file->id);
    expect($file->fresh())->not->toBeNull();
});
