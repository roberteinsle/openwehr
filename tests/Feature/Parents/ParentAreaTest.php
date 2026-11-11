<?php

use App\Livewire\Auth\LoginForm;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\LoginToken;
use App\Models\Module;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\ParentEvents;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Livewire\EquipmentMessageForm;
use Modules\Kleiderkammer\src\Livewire\ParentEquipment;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;

require_once __DIR__.'/../Kalender/Helpers.php';

beforeEach(function () {
    RateLimiter::clear('magic-link:ip:127.0.0.1');
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    // Two siblings without a login of their own, one mother for both
    $this->paul = kalenderUser(null, ['name' => 'Paul Pumpe', 'email' => null]);
    $this->pia = kalenderUser(null, ['name' => 'Pia Pumpe', 'email' => null]);
    $this->mum = Guardian::create(['user_id' => $this->paul->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    $this->mumPia = Guardian::create(['user_id' => $this->pia->id, 'name' => 'Petra Pumpe', 'email' => 'Petra@Example.com']);
});

test('Erziehungsberechtigte sign in with the address stored at the child and land in the Eltern-Bereich', function () {
    Notification::fake();

    $this->post(route('auth.magic-link.send'), ['email' => ' PETRA@example.com '])->assertRedirect(route('auth.magic-link.sent'));
    $token = LoginToken::sole();
    expect($token->user_id)->toBeNull()->and($token->guardian_id)->toBe($this->mum->id);

    $raw = null;
    Notification::assertSentTo($this->mum, MagicLinkNotification::class, function (MagicLinkNotification $n) use (&$raw) {
        $raw = basename(parse_url($n->toMail($this->mum)->actionUrl, PHP_URL_PATH));

        return true;
    });

    $this->post(route('auth.magic-link.consume', $raw))->assertRedirect(route('parents.index'));
    expect(Auth::guard('guardian')->id())->toBe($this->mum->id)->and(Auth::guard('web')->check())->toBeFalse()
        ->and($this->mum->fresh()->last_login_at)->not->toBeNull();

    // Both children, each with its own page; Kamerad pages send the parent back to the Eltern-Bereich
    $this->get(route('parents.index'))->assertOk()->assertSee('Paul Pumpe')->assertSee('Pia Pumpe')->assertSee('Abmelden');
    $this->get(route('parents.child', $this->pia))->assertOk()->assertSee('Termine');
    $this->get('/')->assertRedirect('/eltern');
    $this->get(route('profile'))->assertRedirect('/eltern');

    $other = kalenderUser(null, ['name' => 'Fremdes Kind']);
    $this->get(route('parents.child', $other))->assertNotFound();

    $this->post(route('parents.logout'))->assertRedirect(route('auth.magic-link.form'));
    expect(Auth::guard('guardian')->check())->toBeFalse();
});

test('the code from the mail works for parents too, and the access ends with the last active child', function () {
    Notification::fake();
    Livewire::test(LoginForm::class)->set('email', 'petra@example.com')->call('submit');

    $code = null;
    Notification::assertSentTo($this->mum, MagicLinkNotification::class, function (MagicLinkNotification $n) use (&$code) {
        $mail = $n->toMail($this->mum);
        preg_match('/\*\*(\d{6})\*\*/', implode(' ', [...$mail->introLines, ...$mail->outroLines]), $m);
        $code = $m[1] ?? null;

        return true;
    });
    Livewire::test(LoginForm::class)->set('email', 'petra@example.com')->set('code', $code)->call('verify')->assertRedirect(route('parents.index'));
    expect(Auth::guard('guardian')->check())->toBeTrue();

    // Both children leave: the next request ends the session, a new link isn't sent
    $this->paul->update(['status' => 'left']);
    $this->pia->update(['status' => 'left']);
    $this->get(route('parents.index'))->assertRedirect(route('auth.magic-link.form'));
    expect(Auth::guard('guardian')->check())->toBeFalse();

    LoginToken::query()->delete();
    $this->post(route('auth.magic-link.send'), ['email' => 'petra@example.com']);
    expect(LoginToken::count())->toBe(0);
});

test('a Kamerad whose address is stored as guardian sees the Eltern-Bereich in the normal app; others get a 404', function () {
    $dad = kalenderUser(null, ['name' => 'Peter Pumpe', 'email' => 'peter@example.com']);
    Guardian::create(['user_id' => $this->paul->id, 'name' => 'Peter Pumpe', 'email' => 'peter@example.com']);

    $this->actingAs($dad)->get(route('parents.index'))->assertOk()->assertSee('Paul Pumpe')->assertDontSee('Pia Pumpe');
    $this->actingAs(kalenderUser())->get(route('parents.index'))->assertNotFound();
    auth()->logout();
    $this->get(route('parents.index'))->assertRedirect(route('auth.magic-link.form'));
});

test('child and parents answer apart: the parents count, a difference is marked', function () {
    $events = app(EventService::class);
    $event = kalenderEvent('2027-06-15 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true]);

    $events->respond($event, $this->paul, 'yes');
    $events->respondAsGuardian($event, $this->mum, 'no', 'Zahnarzt');
    $r = $event->responses()->sole();
    expect($r->status)->toBe('no')->and($r->own_status)->toBe('yes')->and($r->guardian_status)->toBe('no')->and($r->mismatch())->toBeTrue();

    // The child answers again: its answer is kept, the parents' still counts
    $events->respond($event, $this->paul, 'maybe');
    expect($r->fresh())->status->toBe('no')->own_status->toBe('maybe');

    $row = $events->participantRows($event)->firstWhere('user.id', $this->paul->id);
    expect($row['mismatch'])->toBeTrue()->and($row['guardian'])->toBe('Abgesagt (Petra Pumpe) – Zahnarzt')->and($row['own'])->toBe('Vielleicht');
    expect($events->summary($event)['no']->pluck('user_id')->all())->toBe([$this->paul->id]);

    $manager = kalenderUser('terminverwalter');
    $this->actingAs($manager)->get(route('kalender.show', $event))->assertOk()->assertSee('passt nicht zusammen')->assertSee('Eltern (Petra Pumpe)');
});

test('parents answer in the Eltern-Bereich and only for their own children', function () {
    $event = kalenderEvent('2027-06-15 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true]);
    Auth::guard('guardian')->login($this->mum);

    Livewire::test(ParentEvents::class, ['childId' => $this->pia->id])->assertSee('Jugenddienst')
        ->set('comments.'.$event->id, 'kommt später')->call('respond', $event->id, 'yes')->assertSee('Rückmeldung für „Jugenddienst“ gespeichert');
    $r = $event->responses()->where('user_id', $this->pia->id)->sole();
    expect($r->guardian_status)->toBe('yes')->and($r->guardian_id)->toBe($this->mumPia->id)->and($r->guardian_comment)->toBe('kommt später');

    $other = kalenderUser(null, ['name' => 'Fremdes Kind']);
    Livewire::test(ParentEvents::class, ['childId' => $other->id])->assertNotFound();
});

test('the parent Kalender-Abo has the Termine of all children, named after them, without app links', function () {
    $group = Group::create(['name' => 'Jugendfeuerwehr']);
    $this->paul->groups()->attach($group);
    $both = kalenderEvent('2027-06-15 18:00', '20:00', ['title' => 'Jugenddienst']);
    $onlyPaul = kalenderEvent('2027-06-20 10:00', '12:00', ['title' => 'Wettbewerb'], [$group->id]);

    $feeds = app(FeedService::class);
    $url = $feeds->parentUrl('PETRA@example.com');
    expect($feeds->parentUrl('petra@example.com'))->toBe($url);

    $ics = $this->get($url)->assertOk()->getContent();
    expect($ics)->toContain('SUMMARY:Paul Pumpe\, Pia Pumpe: Jugenddienst')->toContain('SUMMARY:Paul Pumpe: Wettbewerb')
        ->not->toContain('/kalender/termin/');

    // A new address ends the old one
    $feeds->regenerateParent('petra@example.com');
    $this->get($url)->assertNotFound();
});

test('parents see the Ausrüstung of their child and report a Mangel that names them', function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $type = ItemType::create(['name' => 'JF-Pullover', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'JFPU0001', 'status' => ItemStatus::Issued]);
    $issued = IssuedItem::create(['user_id' => $this->paul->id, 'item_id' => $item->id, 'issued_at' => now()]);

    Auth::guard('guardian')->login($this->mum);
    $this->get(route('parents.child', $this->paul))->assertOk()->assertSee('Ausrüstung');
    Livewire::test(ParentEquipment::class, ['childId' => $this->paul->id])->assertSee('JF-Pullover')->assertSee('JFPU0001');

    Livewire::test(EquipmentMessageForm::class, ['issuedItemId' => $issued->id, 'childId' => $this->paul->id])
        ->set('body', 'Ärmel eingerissen.')->call('send')->assertHasNoErrors();
    expect(EquipmentMessage::sole())->user_id->toBe($this->paul->id)->body->toBe('Ärmel eingerissen. (von Petra Pumpe, erziehungsberechtigt)');

    // Not for the sibling's items, and not without a parent
    Livewire::test(EquipmentMessageForm::class, ['issuedItemId' => $issued->id, 'childId' => $this->pia->id])->set('body', 'x')->call('send')->assertNotFound();
    expect(EquipmentMessage::count())->toBe(1);
    Auth::guard('guardian')->logout();
    Livewire::test(ParentEquipment::class, ['childId' => $this->paul->id])->assertNotFound();
});
