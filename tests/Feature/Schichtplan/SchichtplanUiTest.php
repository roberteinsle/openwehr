<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Livewire\PlanEditor;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Template;
use Modules\Schichtplan\src\Notifications\PlanNotification;
use Modules\Schichtplan\src\Notifications\SignupOpenNotification;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;
use Modules\Schichtplan\src\Services\SignupService;

beforeEach(function () {
    Module::where('key', 'schichtplan')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->planer = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);
    $this->planer->moduleAccess()->create(['module' => 'schichtplan', 'role' => 'schichtplaner']);
    $this->kid = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    $this->kid->moduleAccess()->create(['module' => 'schichtplan']);
    $this->other = User::factory()->create(['name' => 'Klara Kübel', 'phone' => null]);
    $this->other->moduleAccess()->create(['module' => 'schichtplan']);

    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $this->plan = app(PlanService::class)->create(['title' => 'Dorffest – Pommesstand', 'location' => 'Festplatz', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'shift_minutes' => 60], $this->planer);
    app(PlanService::class)->addStation($this->plan, 'Fritteuse', 1);
    app(PlanService::class)->addStation($this->plan, 'Kasse', 1);
    app(PlanService::class)->addParticipants($this->plan, [$this->kid->id, $this->other->id]);
    app(ShiftPlanner::class)->fill($this->plan, 1);
});

// ─── access ──────────────────────────────────────────────────────────────────

test('the module is hidden while inactive and needs a grant', function () {
    $this->actingAs($this->kid)->get('/schichtplan')->assertOk()->assertSee('Meine Schichten');
    $this->actingAs(User::factory()->create())->get('/schichtplan')->assertForbidden();

    Module::where('key', 'schichtplan')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->kid)->get('/schichtplan')->assertNotFound();
});

test('only planers create and edit plans', function () {
    $this->actingAs($this->kid)->get('/schichtplan/neu')->assertForbidden();
    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id.'/bearbeiten')->assertForbidden();

    $this->actingAs($this->planer)->get('/schichtplan/neu')->assertOk();
    $this->actingAs($this->planer)->get('/schichtplan/'.$this->plan->id.'/bearbeiten')->assertOk()->assertSee('Fritteuse')->assertSee('Hanna Hydrant');
});

test('participants see a plan only once it is published', function () {
    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id)->assertForbidden();
    $this->actingAs($this->kid)->get('/schichtplan')->assertDontSee('Dorffest – Pommesstand');

    $this->plan->update(['status' => Plan::PUBLISHED]);

    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id)->assertOk()->assertSee('Deine Schichten');
    $this->actingAs($this->kid)->get('/schichtplan')->assertSee('Dorffest – Pommesstand');

    $stranger = User::factory()->create();
    $stranger->moduleAccess()->create(['module' => 'schichtplan']);
    $this->actingAs($stranger)->get('/schichtplan/'.$this->plan->id)->assertForbidden();
});

// ─── planer pages ────────────────────────────────────────────────────────────

test('a planer creates a plan with stations from a template', function () {
    $template = Template::create(['name' => 'Grillstand Aktive', 'stations' => [['name' => 'Grill', 'capacity' => 2], ['name' => 'Theke', 'capacity' => 1]]]);

    Livewire::actingAs($this->planer)->test(PlanCreate::class)
        ->set('title', 'Feuerwehrfest – Grill')
        ->set('date', '2027-07-03')
        ->set('startTime', '18:00')
        ->set('endTime', '01:00')
        ->set('shiftMinutes', 60)
        ->set('templateId', $template->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $plan = Plan::where('title', 'Feuerwehrfest – Grill')->sole();
    expect($plan->slots()->count())->toBe(7)
        ->and($plan->stations()->pluck('name')->all())->toBe(['Grill', 'Theke'])
        ->and($plan->timeLabel())->toContain('18:00–01:00');
});

test('participants and the people to add show their Abteilung', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->kid->groups()->attach($youth);
    $new = User::factory()->create(['name' => 'Tim Tanklösch', 'phone' => null]);
    $new->moduleAccess()->create(['module' => 'schichtplan']);
    $new->groups()->attach(Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 2]));

    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->assertSeeHtml('Hanna Hydrant</span>')->assertSee('· Jugendfeuerwehr')
        ->assertSee('Tim Tanklösch (Einsatzabteilung)');
});

test('the editor adds a department, fills the plan and swaps by hand', function () {
    $group = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $new = User::factory()->create(['name' => 'Tim Tanklösch', 'phone' => null]);
    $new->moduleAccess()->create(['module' => 'schichtplan']);
    $new->groups()->attach($group);

    $editor = Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->set('addGroupId', $group->id)->call('addGroup')
        ->call('distribute')
        ->assertSee('Tim Tanklösch');

    expect($this->plan->participants()->count())->toBe(3);

    $slot = $this->plan->slots()->first();
    $fritteuse = $this->plan->stations()->where('name', 'Fritteuse')->first();
    $current = Assignment::where('slot_id', $slot->id)->where('station_id', $fritteuse->id)->first();
    $replacement = collect([$this->kid->id, $this->other->id, $new->id])->first(fn ($id) => $id !== $current?->user_id);

    $editor->call('setSeat', $slot->id, $fritteuse->id, $current?->user_id, (string) $replacement);

    expect(Assignment::where('slot_id', $slot->id)->where('station_id', $fritteuse->id)->sole())
        ->user_id->toBe($replacement)->locked->toBeTrue();
});

test('publishing mails every participant, sending again works as well', function () {
    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->call('publish')
        ->assertSee('Veröffentlicht');

    expect($this->plan->refresh()->isPublished())->toBeTrue();
    Notification::assertSentTo([$this->kid, $this->other], PlanNotification::class);
    Notification::assertNotSentTo($this->planer, PlanNotification::class);

    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])->call('sendMail');
    Notification::assertSentToTimes($this->kid, PlanNotification::class, 2);
});

test('the mail lists your own shifts and the whole plan as a table', function () {
    $mine = Assignment::where('user_id', $this->kid->id)->with(['slot', 'station'])->first();

    $html = (string) (new PlanNotification($this->plan, true))->toMail($this->kid)->render();

    expect($html)->toContain('Deine Schichten')
        ->toContain($mine->slot->label().' Uhr: '.$mine->station->name)
        ->toContain('<table')
        ->toContain('Fritteuse')->toContain('Kasse')
        ->toContain('Hanna Hydrant')->toContain('Klara Kübel')
        ->toContain('Dorffest – Pommesstand');
});

test('the print view shows the grid and who has what', function () {
    $this->plan->update(['status' => Plan::PUBLISHED]);

    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id.'/druck')
        ->assertOk()
        ->assertSee('Wer hat wann was')
        ->assertSee('14:00–15:00')
        ->assertSee('Hanna Hydrant')
        ->assertSee('Klara Kübel');
});

test('changing the times in the editor rebuilds the blocks', function () {
    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->set('endTime', '18:00')
        ->call('saveSettings')
        ->assertSee('Zeitblöcke wurden neu angelegt');

    expect($this->plan->slots()->count())->toBe(4)
        ->and($this->plan->assignments()->count())->toBe(0);
});

// ─── Stationsverantwortung, overview, CSV ────────────────────────────────────

test('the planer sets a Stationsverantwortung, also for someone without shifts', function () {
    $lead = User::factory()->create(['name' => 'Lena Leitung', 'phone' => null]);
    $lead->moduleAccess()->create(['module' => 'schichtplan']);
    $station = $this->plan->stations()->where('name', 'Kasse')->first();

    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->assertSee('Verantwortlich (ganzer Abend)')
        ->set("stationLeads.{$station->id}", (string) $lead->id)
        ->call('saveStationLead', $station->id)
        ->assertSee('Stationsverantwortung für „Kasse“ gesetzt', false)
        ->assertSee('Verantw.: Lena Leitung');

    expect($station->refresh()->lead_user_id)->toBe($lead->id);

    // Someone without access is refused
    $outsider = User::factory()->create();
    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->set("stationLeads.{$station->id}", (string) $outsider->id)
        ->call('saveStationLead', $station->id)
        ->assertHasErrors("stationLeads.{$station->id}");

    // The lead sees the published plan and gets the mail, though not a participant
    app(PlanService::class)->setStationLead($station->refresh(), $lead->id);
    app(PlanMailer::class)->publish($this->plan);
    Notification::assertSentTo($lead, PlanNotification::class, function ($n) use ($lead) {
        return str_contains($n->toMail($lead)->render(), 'Du hast die Stationsverantwortung für: Kasse');
    });
    $this->actingAs($lead)->get('/schichtplan/'.$this->plan->id)->assertOk()->assertSee('Du hast die Stationsverantwortung für Kasse');
    $this->actingAs($lead)->get('/schichtplan')->assertSee('Stationsverantwortung: Kasse');
});

test('"Veröffentlichen" tells under the bell only; "Per E-Mail senden" mails the plan', function () {
    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])
        ->assertSee('Veröffentlichen')->assertDontSee('Veröffentlichen &amp; senden', false)
        ->call('publish')->assertSee('2 Kameraden sind in der App benachrichtigt')->assertSee('Per E-Mail senden');

    Notification::assertSentTo($this->kid, PlanNotification::class, fn ($n, $channels) => $channels === ['database']);
    expect($this->plan->refresh()->mailed_at)->toBeNull();

    Notification::fake();
    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $this->plan])->call('sendMail')->assertSee('Per E-Mail an 2 Kameraden geschickt');
    Notification::assertSentTo([$this->kid, $this->other], PlanNotification::class, fn ($n, $channels, $u) => $channels === ['mail'] && $n->toMail($u)->subject === 'Schichtplan: Dorffest – Pommesstand');
    expect($this->plan->refresh()->mailed_at)->not->toBeNull();
});

test('self sign-up: the invitation goes under the bell; by mail the target group is invited, who signed up gets the shifts', function () {
    $this->plan->assignments()->delete();
    $this->plan->participants()->delete();
    $this->plan->update(['signup' => 'self']);
    app(PlanMailer::class)->publish($this->plan->refresh());
    Notification::assertSentTo([$this->kid, $this->other], SignupOpenNotification::class, fn ($n, $channels) => $channels === ['database']);

    app(SignupService::class)->signUp($this->kid, $this->plan, $this->plan->slots()->first(), $this->plan->stations()->first());
    Notification::fake();
    app(PlanMailer::class)->send($this->plan->refresh());
    Notification::assertSentTo($this->kid, PlanNotification::class, fn ($n, $channels) => $channels === ['mail']);
    Notification::assertNotSentTo($this->kid, SignupOpenNotification::class);
    Notification::assertSentTo($this->other, SignupOpenNotification::class, fn ($n, $channels) => $channels === ['mail']);
});

test('a participant can lead a station and still have shifts', function () {
    $station = $this->plan->stations()->where('name', 'Fritteuse')->first();
    app(PlanService::class)->setStationLead($station, $this->kid->id);
    app(PlanMailer::class)->publish($this->plan);

    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id)->assertOk()
        ->assertSee('Du hast die Stationsverantwortung für Fritteuse')
        ->assertSee('Deine Schichten')
        ->assertSee('Gesamter Schichtplan')
        ->assertSee('Deine Schichten sind rot umrandet');
});

test('members see the whole plan with their own places highlighted and who works alongside', function () {
    app(PlanService::class)->addStation($this->plan, 'Ausgabe', 2);
    $this->plan->assignments()->delete();
    $slot = $this->plan->slots()->first();
    $station = $this->plan->stations()->where('name', 'Ausgabe')->first();
    Assignment::create(['plan_id' => $this->plan->id, 'slot_id' => $slot->id, 'station_id' => $station->id, 'user_id' => $this->kid->id]);
    Assignment::create(['plan_id' => $this->plan->id, 'slot_id' => $slot->id, 'station_id' => $station->id, 'user_id' => $this->other->id]);
    app(PlanMailer::class)->publish($this->plan);

    $html = $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id)->assertOk()->getContent();
    expect($html)->toContain('14:00–15:00 Uhr</span> · Ausgabe')
        ->toContain('mit Klara Kübel')
        ->toContain('ring-fw-red')
        ->toContain('font-bold text-fw-red">Hanna Hydrant')
        // Phone: the stations one after the other
        ->toContain('space-y-4 sm:hidden')->toContain('hidden min-w-full text-sm sm:table');
});

test('the plan is exported as CSV like the printed plan', function () {
    $station = $this->plan->stations()->where('name', 'Kasse')->first();
    app(PlanService::class)->setStationLead($station, $this->other->id);

    $this->actingAs($this->kid)->get('/schichtplan/'.$this->plan->id.'/schichtplan.csv')->assertForbidden(); // still a draft

    $csv = $this->actingAs($this->planer)->get('/schichtplan/'.$this->plan->id.'/schichtplan.csv')
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Zeit;Fritteuse;Kasse')
        ->toContain('Verantwortlich;;"Klara Kübel"')
        ->toContain('14:00–15:00;')
        ->toContain('Name;Schichten;Stationsverantwortung')
        ->toContain('"Klara Kübel";');
});
