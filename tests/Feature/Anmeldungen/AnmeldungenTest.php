<?php

use App\Models\Group;
use App\Models\Guardian;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Anmeldungen\src\Livewire\FormEditor;
use Modules\Anmeldungen\src\Livewire\FormView;
use Modules\Anmeldungen\src\Livewire\ParentForms;
use Modules\Anmeldungen\src\Livewire\RegistrationForm;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Notifications\FormOpenedNotification;
use Modules\Anmeldungen\src\Notifications\GuardianFormNotification;
use Modules\Anmeldungen\src\Services\RegistrationService;

beforeEach(function () {
    Module::where('key', 'anmeldungen')->update(['is_active' => true, 'auto_assign' => true]);
    Cache::flush();
    $this->travelTo(Carbon::parse('2027-11-01 12:00', 'Europe/Berlin'));

    $this->jf = Group::create(['name' => 'Jugendfeuerwehr']);
    $this->jugendwart = User::factory()->create(['name' => 'Jana Jugendwart']);
    $this->jugendwart->moduleAccess()->create(['module' => 'anmeldungen', 'role' => 'organisator']);
    $this->jugendwart->groups()->attach($this->jf);
    // A child without a login of its own, with its mother; and a young Kamerad with an own login and no guardian
    $this->paul = User::factory()->create(['name' => 'Paul Pumpe', 'email' => null]);
    $this->paul->groups()->attach($this->jf);
    $this->mum = Guardian::create(['user_id' => $this->paul->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    $this->lena = User::factory()->create(['name' => 'Lena Leiter']);
    $this->lena->groups()->attach($this->jf);
    $this->other = User::factory()->create(['name' => 'Otto Aktiv']);

    $this->service = app(RegistrationService::class);
});

function fahrt(array $overrides = []): Form
{
    $template = RegistrationService::template();

    return app(RegistrationService::class)->save($overrides + [
        'title' => 'Jahresabschlussfahrt', 'location' => 'Jugendherberge Musterstadt', 'starts_on' => '2027-12-10', 'ends_on' => '2027-12-12',
        'deadline' => '2027-11-20T18:00', 'group_ids' => [test()->jf->id], 'fields' => $template['fields'], 'consents' => $template['consents'],
        'member_signature' => true,
    ], test()->jugendwart);
}

function answers(Form $form): array
{
    $values = [];
    foreach ($form->fields as $f) {
        $values[$f['key']] = match ($f['type']) {
            'yesno' => '1', 'choice' => $f['options'][1], 'phone' => '0170 1234567', default => $f['health'] ? 'Erdnussallergie' : 'Peter Pumpe',
        };
    }

    return $values;
}

test('the organiser builds an Anmeldung from the template, opens it and the right people are told', function () {
    Notification::fake();

    Livewire::actingAs($this->jugendwart)->test(FormEditor::class)
        ->assertSet('fields.0.label', 'Notfallkontakt während der Fahrt (Name)')
        ->set('title', 'Zeltlager')->set('startsOn', '2027-07-01')->set('endsOn', '2027-07-04')->set('groupIds', [(string) $this->jf->id])
        ->call('addField')->set('fields.9.label', 'T-Shirt-Größe')->set('fields.9.type', 'choice')->set('fields.9.options', "S\nM\nL")
        ->call('save')->assertHasNoErrors()->assertRedirect();
    $form = Form::sole();
    expect($form->status)->toBe('draft')->and(collect($form->fields)->firstWhere('label', 'T-Shirt-Größe')['options'])->toBe(['S', 'M', 'L'])
        ->and(collect($form->fields)->where('health', true)->count())->toBe(3);

    Livewire::actingAs($this->jugendwart)->test(FormView::class, ['form' => $form])->call('open')->assertSee('Die Anmeldung ist offen');
    // Lena (own login, no guardian) gets bell and mail; Paul's mother a mail to the Eltern-Bereich; the Jugendwart too (in the audience)
    Notification::assertSentTo($this->lena, FormOpenedNotification::class);
    Notification::assertNotSentTo($this->paul, FormOpenedNotification::class);
    Notification::assertSentTo($this->mum, GuardianFormNotification::class, fn ($n) => str_contains($n->toMail($this->mum)->actionUrl, '/eltern/'.$this->paul->id));
    Notification::assertNotSentTo($this->other, FormOpenedNotification::class);

    // Only organisers manage
    Livewire::actingAs($this->lena)->test(FormEditor::class)->assertForbidden();
    $this->actingAs($this->lena)->get(route('anmeldungen.export', $form))->assertForbidden();
    $this->actingAs($this->other)->get(route('anmeldungen.show', $form))->assertNotFound();
});

test('a Kamerad registers and signs; fields, declarations and signature are checked; answers are encrypted', function () {
    $form = fahrt(['member_signature' => false]);
    $this->service->open($form, $this->jugendwart);
    $all = array_column($form->consents, 'key');

    expect(fn () => $this->service->register($form, $this->lena, [], $all, signaturePng(), by: $this->lena))->toThrow(ValidationException::class);
    expect(fn () => $this->service->register($form, $this->lena, answers($form), [], signaturePng(), by: $this->lena))->toThrow(ValidationException::class);
    expect(fn () => $this->service->register($form, $this->lena, answers($form), $all, signaturePng(blank: true), by: $this->lena))->toThrow(ValidationException::class);

    Livewire::actingAs($this->lena)->test(RegistrationForm::class, ['formId' => $form->id])
        ->set('answers', answers($form))->set('consents', array_fill_keys($all, true))->set('signature', signaturePng())
        ->call('submit')->assertHasNoErrors()->assertSee('Lena Leiter ist angemeldet');

    $r = Registration::sole();
    expect($r->signer_name)->toBe('Lena Leiter')->and($r->answer(collect($form->fields)->firstWhere('health', true)['key']))->toBe('Erdnussallergie');
    $raw = DB::table('an_registrations')->first();
    expect($raw->health)->not->toContain('Erdnuss')->and($raw->answers)->not->toContain('Peter Pumpe');

    // A child with Erziehungsberechtigten can't register itself
    expect($this->service->maySelfRegister($form, $this->paul))->toBeFalse()
        ->and(fn () => $this->service->register($form, $this->paul, answers($form), $all, signaturePng(), by: $this->paul))->toThrow(ValidationException::class);
});

test('Erziehungsberechtigte register the child in the Eltern-Bereich, both sign, and withdraw until the deadline', function () {
    $form = fahrt();
    $this->service->open($form, $this->jugendwart);
    Auth::guard('guardian')->login($this->mum);

    $this->get(route('parents.child', $this->paul))->assertOk()->assertSee('Anmeldungen');
    Livewire::test(ParentForms::class, ['childId' => $this->paul->id])->assertSee('Jahresabschlussfahrt');

    $component = Livewire::test(RegistrationForm::class, ['formId' => $form->id, 'childId' => $this->paul->id])
        ->set('answers', answers($form))->set('consents', array_fill_keys(array_column($form->consents, 'key'), true))
        ->set('signature', signaturePng())->call('submit')->assertHasErrors('member_signature')
        ->set('memberSignature', signaturePng())->call('submit')->assertHasNoErrors()->assertSee('Paul Pumpe ist angemeldet');
    $r = Registration::sole();
    expect($r->guardian_id)->toBe($this->mum->id)->and($r->signer_name)->toBe('Petra Pumpe')->and($r->member_signature)->not->toBeNull();

    // The organiser sees the list with both signatures, and the CSV
    Livewire::actingAs($this->jugendwart)->test(FormView::class, ['form' => $form])->assertSee('Paul Pumpe')->call('$set', 'showId', $r->id)
        ->assertSee('Erdnussallergie')->assertSee('data:image/png;base64,', false);
    $csv = $this->actingAs($this->jugendwart)->get(route('anmeldungen.export', $form))->assertOk()->getContent();
    expect($csv)->toContain('Paul Pumpe')->toContain('Erdnussallergie')->toContain('Petra Pumpe');

    // Withdraw: the health answers go at once; after the deadline only organisers can
    $component->call('withdraw')->assertSee('Abgemeldet');
    expect($r->fresh())->status->toBe('cancelled')->health->toBeNull();
    $this->travelTo(Carbon::parse('2027-11-21 12:00', 'Europe/Berlin'));
    expect(fn () => $this->service->cancel($r->fresh(), 'Petra'))->toThrow(ValidationException::class);
});

test('health answers are wiped some days after the end, and full forms take nobody else', function () {
    $form = fahrt(['member_signature' => false, 'max_participants' => 1, 'purge_days' => 3]);
    $this->service->open($form, $this->jugendwart);
    $all = array_column($form->consents, 'key');
    $this->service->register($form, $this->lena, answers($form), $all, signaturePng(), by: $this->lena);
    expect(fn () => $this->service->register($form, $this->jugendwart, answers($form), $all, signaturePng(), by: $this->jugendwart))->toThrow(ValidationException::class, 'Alle Plätze sind vergeben.');

    $this->travelTo(Carbon::parse('2027-12-14 12:00', 'Europe/Berlin'));
    expect($this->service->purgeHealth())->toBe(0);
    $this->travelTo(Carbon::parse('2027-12-15 08:00', 'Europe/Berlin'));
    $this->artisan('anmeldungen:purge-health')->assertSuccessful();

    $r = Registration::sole()->load('form');
    $healthField = collect($form->fields)->firstWhere('health', true);
    expect($r->health)->toBeNull()->and($r->answerLabel($healthField))->toBe('(gelöscht)')
        ->and($r->answerLabel(collect($form->fields)->firstWhere('type', 'phone')))->toBe('0170 1234567')
        ->and($form->fresh()->health_purged_at)->not->toBeNull();
});

test('the start page shows open Anmeldungen and a copy keeps fields without dates', function () {
    $form = fahrt();
    $this->service->open($form, $this->jugendwart);
    $this->actingAs($this->lena)->get(route('home'))->assertOk()->assertSee('Anmeldung offen: Jahresabschlussfahrt');

    $copy = $this->service->duplicate($form, $this->jugendwart);
    expect($copy->title)->toBe('Jahresabschlussfahrt (Kopie)')->and($copy->fields)->toBe($form->fields)->and($copy->starts_on)->toBeNull()->and($copy->status)->toBe('draft');
});
