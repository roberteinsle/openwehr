<?php

use App\Models\ActivityLog;
use App\Models\Guardian;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Notifications\GuardianCopyNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Personalverwaltung\src\Livewire\MemberFile;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->kid = kalenderUser(null, ['name' => 'Paul Pumpe']);
    $this->mum = Guardian::create(['user_id' => $this->kid->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
});

test('Erziehungsberechtigte get the Kalender mails of the child and answer for it by link', function () {
    Notification::fake();
    $manager = kalenderUser('terminverwalter');
    $event = kalenderEvent('2027-06-15 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true]);
    app(EventService::class)->announce($event, $manager);

    Notification::assertSentTo($this->kid, EventCreatedNotification::class);
    Notification::assertSentTo($this->mum, GuardianCopyNotification::class, function (GuardianCopyNotification $n) {
        $mail = $n->toMail($this->mum);

        return $mail->subject === 'Neuer Termin: Jugenddienst – Paul Pumpe' && $mail->greeting === 'Hallo Petra Pumpe,'
            && $mail->actionText === 'Rückmeldung für Paul Pumpe' && str_contains($mail->actionUrl, '/kalender/rueckmeldung/');
    });

    // The link: GET only shows, the POST answers; without signature nothing
    $url = GuardianCopyNotification::url($event, $this->mum);
    $this->get($url)->assertOk()->assertSee('Rückmeldung für Paul Pumpe')->assertSee('keine Rückmeldung');
    expect($event->responses()->count())->toBe(0);

    $this->post($url, ['status' => 'no', 'comment' => 'krank'])->assertRedirect();
    $response = $event->responses()->sole();
    expect($response->user_id)->toBe($this->kid->id)->and($response->status)->toBe('no')
        ->and($response->guardian_status)->toBe('no')->and($response->guardian_comment)->toBe('krank')->and($response->guardian_name)->toBe('Petra Pumpe')
        ->and($response->own_status)->toBeNull()->and($response->comment)->toBeNull();
    $this->get($url)->assertSee('Abgesagt');

    $this->get(route('kalender.guardian', ['event' => $event->id, 'guardian' => $this->mum->id]))->assertForbidden();
    // After the start the link has expired
    $this->travelTo(Carbon::parse('2027-06-15 18:30', 'Europe/Berlin'));
    $this->get($url)->assertForbidden();
});

test('the Personalverwalter and the admin keep the Erziehungsberechtigte', function () {
    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $pv = User::factory()->create();
    $pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);

    Livewire::actingAs($pv)->test(MemberFile::class, ['member' => $this->kid])
        ->assertSee('Petra Pumpe')
        ->set('guardian', ['name' => 'Peter Pumpe', 'email' => 'PETER@example.com', 'phone' => '0170 1'])->call('addGuardian')->assertHasNoErrors()
        ->set('guardian', ['name' => 'Peter Pumpe', 'email' => 'peter@example.com', 'phone' => ''])->call('addGuardian')->assertHasErrors('guardian_email')
        ->set('guardian', ['name' => '', 'email' => 'x', 'phone' => ''])->call('addGuardian')->assertHasErrors(['guardian_name', 'guardian_email'])
        ->call('deleteGuardian', $this->mum->id)->assertSee('entfernt');
    expect($this->kid->guardians()->pluck('email')->all())->toBe(['peter@example.com']);

    // The log shows that it changed, not the address
    $log = ActivityLog::where('subject_type', Guardian::class)->where('event', 'created')->latest('id')->first();
    expect(json_encode($log->changes))->not->toContain('peter@example.com');

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get('/admin/kameraden/'.$this->kid->id.'/bearbeiten')->assertOk()->assertSee('Erziehungsberechtigte');
});
