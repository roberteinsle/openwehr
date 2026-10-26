<?php

use App\Models\Group;
use App\Models\MemberFunction;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\AttendanceSettings;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Notifications\AttendanceMissingNotification;
use Modules\Kalender\src\Notifications\AttendanceReportNotification;
use Modules\Kalender\src\Services\AttendanceService;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-03-10 18:00', 'Europe/Berlin'));

    $chiefFn = MemberFunction::create(['name' => 'Wehrführer']);
    $deputyFn = MemberFunction::create(['name' => 'stellv. Wehrführer']);
    $this->chief = kalenderUser(null, ['name' => 'Wilma Wehrführer']);
    $this->chief->functions()->attach($chiefFn);
    $this->deputy = kalenderUser(null, ['name' => 'Stefan Stellvertreter']);
    $this->deputy->functions()->attach($deputyFn);
    $this->manager = kalenderUser('terminverwalter', ['name' => 'Tina Termin']);
    $this->hanna = kalenderUser(null, ['name' => 'Hanna Hydrant']);
    $this->hanna->groups()->attach(Group::create(['name' => 'Aktive']));
    $this->bernd = kalenderUser(null, ['name' => 'Bernd Blaulicht']);

    $this->event = kalenderEvent('2027-03-10 19:00', '21:00');
    $this->service = app(AttendanceService::class);
    $this->events = app(EventService::class);
});

test('the Stellvertreter records and closes; the list goes to the Wehrführer with CSV, the rest may not close', function () {
    $this->travelTo(Carbon::parse('2027-03-10 21:15', 'Europe/Berlin'));

    expect($this->service->mayClose($this->deputy, $this->event))->toBeTrue()
        ->and($this->service->mayClose($this->chief, $this->event))->toBeTrue()
        ->and($this->service->mayClose($this->manager, $this->event))->toBeFalse()
        ->and($this->service->mayClose($this->hanna, $this->event))->toBeFalse();

    Livewire::actingAs($this->deputy)->test(EventDetail::class, ['event' => $this->event])
        ->assertSee('Anwesenheit abschließen')->assertSee('Wilma Wehrführer')
        ->call('setAttendance', $this->hanna->id, true)
        ->call('closeAttendance')->assertHasNoErrors()
        ->assertSee('von Stefan Stellvertreter');

    Notification::assertSentTo($this->chief, AttendanceReportNotification::class, function ($n) {
        $mail = $n->toMail($this->chief);

        return $mail->subject === 'Anwesenheit: Übungsdienst am 10.03.2027'
            && in_array('- Hanna Hydrant (Aktive)', $mail->introLines, true)
            && str_contains($mail->rawAttachments[0]['data'], 'Hanna Hydrant')
            && $mail->rawAttachments[0]['name'] === 'anwesenheit-2027-03-10-ubungsdienst.csv';
    });
    Notification::assertNotSentTo($this->deputy, AttendanceReportNotification::class);
    expect(fn () => $this->service->close($this->event->refresh(), $this->chief))->toThrow(ValidationException::class);

    // A Kamerad without the Funktion sees nothing to close
    Livewire::actingAs($this->hanna)->test(EventDetail::class, ['event' => $this->event])->assertDontSee('Anwesenheit abschließen')
        ->call('closeAttendance')->assertForbidden();
});

test('a change after closing is sent as Korrektur', function () {
    $this->travelTo(Carbon::parse('2027-03-10 21:15', 'Europe/Berlin'));
    $this->events->setAttendance($this->event, $this->hanna, true, $this->manager);
    $this->service->close($this->event, $this->chief);
    expect(fn () => $this->service->sendCorrection($this->event->refresh(), $this->chief))->toThrow(ValidationException::class);

    Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $this->event->refresh()])
        ->call('setAttendance', $this->bernd->id, true)->assertSee('Seit dem Abschluss geändert')->assertDontSee('Korrektur senden');

    Livewire::actingAs($this->chief)->test(EventDetail::class, ['event' => $this->event->refresh()])
        ->call('sendAttendanceCorrection')->assertHasNoErrors()->assertDontSee('Seit dem Abschluss geändert');

    Notification::assertSentToTimes($this->chief, AttendanceReportNotification::class, 2);
    Notification::assertSentTo($this->chief, AttendanceReportNotification::class, fn ($n) => str_starts_with($n->toMail($this->chief)->subject, 'Korrektur – Anwesenheit')
        && str_contains(implode("\n", $n->toMail($this->chief)->introLines), 'Bernd Blaulicht'));
});

test('24 hours after the end it goes out by itself; without attendance the closers are reminded once instead', function () {
    $empty = kalenderEvent('2027-03-11 19:00', '21:00', ['title' => 'Gerätepflege']);
    $this->travelTo(Carbon::parse('2027-03-11 20:30', 'Europe/Berlin'));
    $this->events->setAttendance($this->event, $this->hanna, true, $this->manager);

    expect($this->service->closeDue())->toBe(['sent' => 0, 'reminded' => 0]);

    $this->travelTo(Carbon::parse('2027-03-12 21:30', 'Europe/Berlin'));
    $this->artisan('kalender:close-attendance')->assertSuccessful();
    expect($this->event->refresh()->attendance_closed_at)->not->toBeNull()->and($this->event->attendance_closed_by)->toBeNull()
        ->and($empty->refresh()->attendance_closed_at)->toBeNull();
    Notification::assertSentTo($this->chief, AttendanceReportNotification::class, fn ($n) => str_contains(implode("\n", $n->toMail($this->chief)->introLines), 'automatisch'));
    Notification::assertSentTo([$this->chief, $this->deputy], AttendanceMissingNotification::class);
    Notification::assertNotSentTo($this->hanna, AttendanceMissingNotification::class);

    // No second reminder; once attendance is there, the list goes out at the next run
    expect($this->service->closeDue())->toBe(['sent' => 0, 'reminded' => 0]);
    $this->events->setAttendance($empty, $this->bernd, true, $this->manager);
    expect($this->service->closeDue())->toBe(['sent' => 1, 'reminded' => 0]);
});

test('admins set the Arten, who closes and the recipients, also addresses outside the Wehr', function () {
    $admin = kalenderUser(null, ['is_admin' => true]);
    $kassenwart = MemberFunction::create(['name' => 'Kassenwart']);

    Livewire::actingAs($admin)->test(AttendanceSettings::class)
        ->assertSet('types', ['uebung'])
        ->set('recipientFunctionIds', [])
        ->set('addUserId', (string) $this->bernd->id)->call('addUser')
        ->set('emails', "Verwaltung@example.com\nkein-mail")->call('save')->assertHasErrors('emails')
        ->set('emails', 'Verwaltung@example.com')->set('closerFunctionIds', [$kassenwart->id])->call('save')->assertHasNoErrors();

    expect($this->service->recipientLabel())->toBe('Bernd Blaulicht, verwaltung@example.com')
        ->and($this->service->mayClose($this->chief, $this->event))->toBeFalse();

    $this->travelTo(Carbon::parse('2027-03-10 21:15', 'Europe/Berlin'));
    $this->service->close($this->event, $admin);
    Notification::assertSentTo($this->bernd, AttendanceReportNotification::class);
    Notification::assertSentTo(new AnonymousNotifiable, AttendanceReportNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'verwaltung@example.com');
    Notification::assertNotSentTo($this->chief, AttendanceReportNotification::class);

    // Other Arten are not closed, and only admins open the settings
    expect($this->service->appliesTo(kalenderEvent('2027-03-20 10:00', '12:00', ['type' => 'veranstaltung'])))->toBeFalse();
    $this->actingAs($this->hanna)->get(route('kalender.attendance'))->assertForbidden();
});
