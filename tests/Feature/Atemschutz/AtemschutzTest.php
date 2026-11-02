<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Atemschutz\src\Livewire\Board;
use Modules\Atemschutz\src\Livewire\Overview;
use Modules\Atemschutz\src\Livewire\Wearers;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\Team;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Warning;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Notifications\DeadlineNotification;
use Modules\Atemschutz\src\Notifications\PoolDigestNotification;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;

beforeEach(function () {
    Module::whereIn('key', ['atemschutz', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->wart = User::factory()->create(['name' => 'Anton Atemschutzwart']);
    $this->wart->moduleAccess()->create(['module' => 'atemschutz', 'role' => 'atemschutzwart']);
    $this->watch = User::factory()->create(['name' => 'Uwe Überwacher']);
    $this->watch->moduleAccess()->create(['module' => 'atemschutz', 'role' => 'ueberwacher']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'atemschutz']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->otto = User::factory()->create(['name' => 'Otto Ortsbrand']);

    foreach ([$this->hanna, $this->bernd] as $user) {
        readyWearer($user);
    }
    // Otto's G26.3 ran out
    readyWearer($this->otto, ['g263_valid_until' => now()->subDay()->toDateString()]);
    Wearer::where('user_id', $this->hanna->id)->update(['rfid_uid' => '04A1B2C3']);

    $this->m = app(MonitorService::class);
    $this->r = app(ReadinessService::class);
});

function readyWearer(User $user, array $overrides = []): Wearer
{
    $wearer = Wearer::create(['user_id' => $user->id, 'is_agt' => true, 'g263_examined_on' => now()->subYear()->toDateString(),
        'g263_valid_until' => now()->addYears(2)->toDateString(), ...$overrides]);
    foreach (['belastungsuebung', 'unterweisung'] as $kind) {
        Training::create(['user_id' => $user->id, 'kind' => $kind, 'done_on' => now()->subMonths(2)->toDateString()]);
    }

    return $wearer;
}

function startedTeam(): Team
{
    $t = test();
    $operation = $t->m->startOperation($t->watch, '', 'einsatz', null);
    $team = $t->m->registerTeam($t->watch, $operation, 'Angriffstrupp 1', 'Menschenrettung 1. OG', [
        ['user_id' => $t->hanna->id, 'start_pressure' => 300, 'is_leader' => true],
        ['user_id' => $t->bernd->id, 'start_pressure' => 290],
    ]);
    $t->m->startTeam($t->watch, $team);

    return $team->fresh();
}

test('einsatzbereit needs AGT, G26.3, Belastungsübung and Unterweisung of the last twelve months', function () {
    expect($this->r->forWearer(Wearer::where('user_id', $this->hanna->id)->first()))->ready->toBeTrue()->soon->toBeFalse()
        ->and($this->r->forWearer(Wearer::where('user_id', $this->otto->id)->first()))->ready->toBeFalse()->reasons->toBe(['G26.3 abgelaufen']);

    Training::where('user_id', $this->bernd->id)->where('kind', 'unterweisung')->update(['done_on' => now()->subMonths(12)->addDays(10)->toDateString()]);
    expect($this->r->forWearer(Wearer::where('user_id', $this->bernd->id)->first()))->ready->toBeTrue()->soon->toBeTrue();
    Training::where('user_id', $this->bernd->id)->where('kind', 'unterweisung')->delete();
    expect($this->r->forWearer(Wearer::where('user_id', $this->bernd->id)->first()))->reasons->toBe(['Unterweisung fehlt']);

    expect($this->r->poolCounts())->toBe(['csa_ready' => 0, 'ready' => 1, 'soon' => 0, 'not_ready' => 2]);
});

test('a completed AGT-Lehrgang and Belastungsübungen from the Lehrgänge count too', function () {
    $klara = User::factory()->create(['name' => 'Klara Kübel']);
    $wearer = Wearer::create(['user_id' => $klara->id, 'g263_valid_until' => now()->addYear()->toDateString()]);
    Training::create(['user_id' => $klara->id, 'kind' => 'unterweisung', 'done_on' => now()->subMonth()->toDateString()]);
    expect($this->r->forWearer($wearer)['reasons'])->toBe(['AGT-Lehrgang fehlt', 'Belastungsübung fehlt']);

    $agt = Course::create(['name' => 'Atemschutzgeräteträger (Wochenendlehrgang)']);
    $bel = Course::create(['name' => 'Atemschutz-Belastungsübung']);
    Record::create(['user_id' => $klara->id, 'course_id' => $agt->id, 'status' => 'completed', 'completed_on' => '2025-03-01']);
    Record::create(['user_id' => $klara->id, 'course_id' => $bel->id, 'status' => 'completed', 'completed_on' => now()->subMonths(3)->toDateString()]);

    // Only while the Lehrgänge module is on
    expect($this->r->forWearer($wearer)['ready'])->toBeFalse();
    Module::where('key', 'lehrgaenge')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $state = $this->r->forWearer($wearer);
    expect($state['ready'])->toBeTrue()->and($state['items']['belastungsuebung']['source'])->toBe('Lehrgänge');
});

test('registering a Trupp: RFID scan, pool, and a confirmation for who is not einsatzbereit', function () {
    $operation = $this->m->startOperation($this->watch, 'Wohnungsbrand', 'einsatz', null);

    Livewire::actingAs($this->watch)->test(Board::class, ['operation' => $operation])
        ->set('rfid', 'FFFFFF')->call('addByRfid')->assertHasErrors('rfid')
        ->set('rfid', '04A1B2C3')->call('addByRfid')->assertHasNoErrors()->assertSet('draft.0.name', 'Hanna Hydrant')
        ->call('addMember', $this->otto->id)->assertSet('draft.1.reason', 'G26.3 abgelaufen')
        ->call('register')->assertHasErrors('members.1')
        ->set('draft.1.override', true)->call('register')->assertHasNoErrors();

    $team = Team::sole();
    expect($team)->name->toBe('Trupp 1')->status->toBe('ready')->cylinder_liters->toBe(6.8)
        ->and($team->members->pluck('override_reason')->all())->toBe([null, 'G26.3 abgelaufen'])
        ->and($team->members->firstWhere('is_leader', true)->name)->toBe('Hanna Hydrant');

    // Nobody in two Trupps at once, at least two per Trupp
    expect(fn () => $this->m->registerTeam($this->watch, $operation, 'Trupp 2', null, [['user_id' => $this->hanna->id, 'start_pressure' => 300], ['user_id' => $this->bernd->id, 'start_pressure' => 300]]))
        ->toThrow(ValidationException::class, 'schon im Trupp 1')
        ->and(fn () => $this->m->registerTeam($this->watch, $operation, 'Trupp 2', null, [['user_id' => $this->bernd->id, 'start_pressure' => 300]]))
        ->toThrow(ValidationException::class, 'zwei bis vier');
});

test('the clock, the checks after 1/3 and 2/3, the Rückzugsdruck and the warnings', function () {
    $team = startedTeam();
    $state = $this->m->state($team);
    // (290 − 55) × 6.8 ÷ 40 = 39.95
    expect($state)->expected->toBe(39)->retreat->toBe(55)->checks->toBe([1 => ['at' => 13, 'done' => false], 2 => ['at' => 26, 'done' => false]])->warnings->toBe([]);

    $this->travel(14)->minutes();
    expect($this->m->state($team->fresh())['warnings'])->toHaveKey('check_1');
    Livewire::actingAs($this->watch)->test(Board::class, ['operation' => $team->operation])->assertSee('Druckkontrolle nach 1/3');
    expect(Warning::where('team_id', $team->id)->pluck('kind')->all())->toBe(['check_1']);

    $members = $team->members->pluck('id');
    $this->m->recordPressures($this->watch, $team, [$members[0] => 260, $members[1] => 250]);
    expect($this->m->state($team->fresh()))->checks->{1}->done->toBeTrue()->warnings->toBe([]);

    // Target reached with 240 bar at the lowest: the way back needs twice the 50 bar of the way in
    $this->travel(2)->minutes();
    expect(fn () => $this->m->recordPressures($this->watch, $team, [$members[0] => 245], 'target'))->toThrow(ValidationException::class, 'aller');
    $this->m->recordPressures($this->watch, $team, [$members[0] => 245, $members[1] => 240], 'target');
    expect($this->m->state($team->fresh()))->retreat->toBe(100);

    $this->travel(3)->minutes();
    $this->m->recordPressures($this->watch, $team, [$members[1] => 98]);
    expect($this->m->state($team->fresh())['warnings'])->toHaveKey('retreat');
    $this->m->withdraw($this->watch, $team->fresh());
    expect($this->m->state($team->fresh())['warnings'])->not->toHaveKey('retreat');

    $this->m->recordPressures($this->watch, $team->fresh(), [$members[0] => 120, $members[1] => 60], 'end');
    expect($team->fresh())->status->toBe('done')->minutes()->toBe(19)
        ->and($team->members()->pluck('end_pressure')->all())->toBe([120, 60])
        // Counts as Einsatz under Atemschutz for the yearly duty
        ->and($this->r->forWearer(Wearer::where('user_id', $this->hanna->id)->first())['items']['einsatzuebung']['ok'])->toBeTrue();
});

test('an Überwachung ends only when every Trupp is out; warnings are acknowledged', function () {
    $team = startedTeam();
    $this->travel(45)->minutes();
    Livewire::actingAs($this->watch)->test(Board::class, ['operation' => $team->operation])
        ->assertSee('Erwartete Einsatzzeit (39 min) überschritten')
        ->call('endOperation')->assertHasErrors('operation');

    $warning = Warning::where('team_id', $team->id)->where('kind', 'time')->sole();
    Livewire::actingAs($this->watch)->test(Board::class, ['operation' => $team->operation])->call('acknowledge', $warning->id);
    expect($warning->fresh())->acknowledged_by->toBe($this->watch->id);

    $members = $team->members->pluck('id');
    $this->m->recordPressures($this->watch, $team, [$members[0] => 70, $members[1] => 65], 'end');
    $this->m->endOperation($this->watch, $team->operation);
    expect($team->operation->fresh()->isRunning())->toBeFalse()
        ->and(fn () => $this->m->registerTeam($this->watch, $team->operation, 'Trupp 2', null, []))->toThrow(ValidationException::class, 'beendet');
});

test('linked to an Einsatzbericht: the Trupp is marked unter Atemschutz and shows in the report', function () {
    $leader = User::factory()->create(['name' => 'Bernd Einsatzleiter']);
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $leader->moduleAccess()->create(['module' => 'atemschutz', 'role' => 'ueberwacher']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader);
    $hlf = $reports->addVehicle($leader, $report, Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true])->id);
    $reports->assign($leader, $hlf, $this->hanna, 'angriffstrupp');
    $reports->assign($leader, $hlf, $this->bernd, 'angriffstrupp');

    Livewire::actingAs($leader)->withQueryParams(['bericht' => $report->id])->test(Overview::class)
        ->assertSet('reportId', (string) $report->id)->call('start')->assertRedirect();
    $operation = Operation::sole();
    expect($operation->report_id)->toBe($report->id);

    $team = $this->m->registerTeam($leader, $operation, 'Angriffstrupp', null, [['user_id' => $this->hanna->id, 'start_pressure' => 300], ['user_id' => $this->bernd->id, 'start_pressure' => 300]]);
    $this->m->startTeam($leader, $team);
    expect(CrewMember::where('report_id', $report->id)->pluck('breathing_apparatus')->all())->toBe([true, true]);

    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()->assertSee('Atemschutzüberwachung')->assertSee('Protokoll (PDF)');
    $this->actingAs($leader)->get(route('einsatzbericht.print', $report))->assertOk()->assertSee('Hanna Hydrant 300→… bar');
});

test('deadlines: one mail per deadline to the Geräteträger, an overview to the Atemschutzwart', function () {
    Wearer::where('user_id', $this->bernd->id)->update(['g263_valid_until' => now()->addDays(10)->toDateString()]);
    $this->artisan('atemschutz:remind')->assertSuccessful();

    Notification::assertSentTo($this->bernd, DeadlineNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'G26.3 läuft ab am'));
    Notification::assertSentTo($this->otto, DeadlineNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'G26.3 abgelaufen am'));
    Notification::assertNotSentTo($this->hanna, DeadlineNotification::class);
    Notification::assertSentTo($this->wart, PoolDigestNotification::class);

    Notification::fake();
    $this->artisan('atemschutz:remind')->assertSuccessful();
    Notification::assertNothingSent();
});

test('the Atemschutzwart keeps the pool: G26.3, chip, trainings for several at once, settings', function () {
    $klara = User::factory()->create(['name' => 'Klara Kübel']);

    Livewire::actingAs($this->wart)->test(Wearers::class)
        ->set('addUserId', (string) $klara->id)->call('addWearer')
        ->set('form.is_agt', true)->set('form.g263_examined_on', now()->subMonth()->toDateString())
        ->assertSet('form.g263_valid_until', now()->subMonth()->addYears(3)->toDateString())
        ->set('form.rfid_uid', '04A1B2C3')->call('save')->assertHasErrors('form.rfid_uid')
        ->set('form.rfid_uid', '04FFEE11')->call('save')->assertHasNoErrors()
        ->set('selected', [$klara->id, $this->otto->id])->set('trainingKind', 'belastungsuebung')->call('recordTraining')->assertHasNoErrors()
        ->set('trainingKind', 'unterweisung')->set('selected', [$klara->id])->call('recordTraining')
        ->set('consumption', '50')->call('saveSettings')->assertHasNoErrors();

    expect($this->r->forWearer(Wearer::where('user_id', $klara->id)->first())['ready'])->toBeTrue()
        ->and(Training::where('kind', 'belastungsuebung')->where('done_on', now('Europe/Berlin')->toDateString())->count())->toBe(2)
        ->and(MonitorService::settings()['consumption_lpm'])->toBe(50);
});

test('who may do what, the pages and the PDF', function () {
    $team = startedTeam();

    $this->actingAs($this->hanna)->get(route('atemschutz.index'))->assertOk()->assertSee('Mein Status');
    $this->actingAs($this->hanna)->get(route('atemschutz.board', $team->operation))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('atemschutz.wearers'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('atemschutz.personnel-file'))->assertOk()->assertSee('Einsatzbereit unter Atemschutz')->assertSee('Angriffstrupp 1');
    $this->actingAs($this->watch)->get(route('atemschutz.wearers'))->assertForbidden();
    $this->actingAs($this->watch)->get(route('atemschutz.index'))->assertOk()->assertSee('Überwachung starten')->assertSee('läuft seit');
    $this->actingAs($this->watch)->get(route('atemschutz.board', $team->operation))->assertOk()->assertSee('Angriffstrupp 1')->assertSee('← Übersicht', false);
    $this->actingAs($this->wart)->get(route('atemschutz.wearers'))->assertOk()->assertSee('Otto Ortsbrand');
    expect(fn () => $this->m->startOperation($this->hanna, '', 'uebung', null))->toThrow(ValidationException::class);

    $response = $this->actingAs($this->watch)->get(route('atemschutz.pdf', $team->operation))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    Livewire::actingAs($this->watch)->test(Home::class)->assertSee('2 einsatzbereit')->assertSee('1 nicht einsatzbereit');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Ich: einsatzbereit');
});

test('an Einsatz marked unter Atemschutz in the Einsatzbericht counts, also without Überwachung', function () {
    $leader = User::factory()->create();
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader, now()->subDays(3));
    $reports->update($leader, $report, ['keyword' => 'FEU 2']);
    $hlf = $reports->addVehicle($leader, $report, Vehicle::factory()->create(['is_active' => true])->id);
    $reports->assign($leader, $hlf, $this->hanna, 'angriffstrupp', true);
    $reports->assign($leader, $hlf, $this->bernd, 'maschinist');

    // A draft does not count yet
    expect($this->r->forWearer(Wearer::where('user_id', $this->hanna->id)->sole())['items']['einsatzuebung']['ok'])->toBeFalse();

    $reports->start($leader, $report->refresh());
    $hanna = $this->r->forWearer(Wearer::where('user_id', $this->hanna->id)->sole())['items']['einsatzuebung'];
    expect($hanna['ok'])->toBeTrue()->and($hanna['last']->toDateString())->toBe(now('Europe/Berlin')->subDays(3)->toDateString())
        ->and($this->r->forWearer(Wearer::where('user_id', $this->bernd->id)->sole())['items']['einsatzuebung']['ok'])->toBeFalse();

    // In the Personalakte under "Unter Atemschutz"
    $this->actingAs($this->hanna)->get(route('atemschutz.personnel-file'))->assertOk()
        ->assertSee('Einsatz '.$report->number.' · FEU 2')->assertSee('laut Einsatzbericht, ohne Überwachung');

    // Einsatzbericht switched off: no longer read
    Module::where('key', 'einsatzbericht')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    expect($this->r->forWearer(Wearer::where('user_id', $this->hanna->id)->sole())['items']['einsatzuebung']['ok'])->toBeFalse();
});

test('the pool shows Dienstgrad and Abteilung and filters by Abteilung', function () {
    $jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $ea = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->hanna->update(['rank' => 'Oberfeuerwehrfrau']);
    $this->hanna->groups()->attach($ea);
    $this->bernd->groups()->attach($jf);

    Livewire::actingAs($this->wart)->test(Wearers::class)
        ->assertSee('Oberfeuerwehrfrau · Einsatzabteilung')->assertSee('Bernd Blaulicht')
        ->set('group', (string) $ea->id)->assertSee('Hanna Hydrant')->assertDontSee('Bernd Blaulicht')->assertDontSee('Otto Ortsbrand');
});

test('the Personalverwaltung file and the Jahresbericht show Atemschutz', function () {
    $this->travelTo(now()->setDate(2027, 5, 1));
    startedTeam();
    Training::create(['user_id' => $this->otto->id, 'kind' => 'unterweisung', 'done_on' => '2027-04-01']);

    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $pv = User::factory()->create();
    $pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->actingAs($pv)->get(route('personalverwaltung.member', $this->otto))->assertOk()
        ->assertSee('Atemschutz')->assertSee('Nicht einsatzbereit: G26.3 abgelaufen');

    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $html = $this->actingAs($chief)->get(route('einsatzbericht.annual', ['jahr' => 2027]))->assertOk()->getContent();
    expect($html)->toContain('<h2>Atemschutz</h2>')->toContain('Überwachungen Einsatz')->toContain('Trupps unter Atemschutz')
        ->toContain('Pool heute')->toContain('Unterweisung (eingetragene Nachweise)');
});

test('CSA-Träger: ready with Atemschutz, CSA training and a CSA-Übung of the last twelve months; reminded of it', function () {
    Wearer::where('user_id', $this->hanna->id)->update(['is_csa' => true, 'csa_qualified_on' => now()->subYears(2)->toDateString()]);
    $state = fn (User $u) => $this->r->forWearer(Wearer::where('user_id', $u->id)->sole());

    expect($state($this->hanna)['csa'])->ready->toBeFalse()->reasons->toBe(['CSA-Übung fehlt'])
        ->and($state($this->bernd)['csa'])->toBeNull();

    // The Atemschutzwart enters a CSA-Übung with Tragezeit for Hanna
    Livewire::actingAs($this->wart)->test(Wearers::class)
        ->set('selected', [$this->hanna->id])->set('trainingKind', 'csa_uebung')->set('trainingMinutes', '25')->call('recordTraining')->assertHasNoErrors()
        ->set('carrier', 'csa')->assertSee('Hanna Hydrant')->assertDontSee('Bernd Blaulicht');
    expect($state($this->hanna)['csa'])->ready->toBeTrue()
        ->and($this->r->poolCounts()['csa_ready'])->toBe(1);

    // A CSA-Trupp only with CSA-ready CSA-Träger, or with confirmation
    $operation = $this->m->startOperation($this->watch, '', 'uebung', null);
    expect(fn () => $this->m->registerTeam($this->watch, $operation, 'CSA-Trupp', null, [
        ['user_id' => $this->hanna->id, 'start_pressure' => 300], ['user_id' => $this->bernd->id, 'start_pressure' => 300]], true))
        ->toThrow(ValidationException::class, 'kein CSA-Träger');
    $team = $this->m->registerTeam($this->watch, $operation, 'CSA-Trupp', null, [
        ['user_id' => $this->hanna->id, 'start_pressure' => 300], ['user_id' => $this->bernd->id, 'start_pressure' => 300, 'override' => true]], true);
    expect($team->csa)->toBeTrue()->and($team->members()->where('user_id', $this->bernd->id)->value('override_reason'))->toBe('kein CSA-Träger');

    // The CSA-Übung runs out: reminder (20 days before, whatever the months' lengths)
    Notification::fake();
    $this->travelTo(now()->addMonths(ReadinessService::VALID_MONTHS)->subDays(20));
    Training::where('user_id', $this->hanna->id)->whereIn('kind', ['belastungsuebung', 'unterweisung'])->update(['done_on' => now()->toDateString()]);
    $this->artisan('atemschutz:remind')->assertSuccessful();
    Notification::assertSentTo($this->hanna, DeadlineNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'CSA-Übung läuft ab am'));
});

test('Tragezeit by Einsatz, Übung, Belastungsübung and CSA in the pass and the Personalakte', function () {
    $this->travelTo(now()->setDate(2027, 5, 1)->setTime(12, 0));
    $team = startedTeam();                                   // Einsatz
    $this->travel(30)->minutes();
    $members = $team->members()->pluck('id')->all();
    $this->m->recordPressures($this->watch, $team, [$members[0] => 150, $members[1] => 140], 'end');
    Training::create(['user_id' => $this->hanna->id, 'kind' => 'belastungsuebung', 'done_on' => '2027-04-10', 'minutes' => 20]);
    Training::create(['user_id' => $this->hanna->id, 'kind' => 'einsatzuebung', 'done_on' => '2027-04-12', 'minutes' => 15, 'csa' => true]);

    $wear = $this->r->wearTime($this->hanna, 2027);
    expect($wear['by'])->toBe(['einsatz' => 30, 'uebung' => 0, 'belastungsuebung' => 20, 'csa' => 15])->and($wear['minutes'])->toBe(65);

    $this->actingAs($this->hanna)->get(route('atemschutz.personnel-file'))->assertOk()
        ->assertSee('65 min Tragezeit 2027')->assertSee('Einsatz 30 min · Belastungsübung 20 min · mit CSA 15 min');
});
