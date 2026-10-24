<?php

use App\Models\Contact;
use App\Models\Group;
use App\Models\Guardian;
use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Http\Controllers\FeedController;
use Modules\Kalender\src\Http\Controllers\PublicCalendarController;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Support\Roster;

// Subscription feed for phone calendars; the token in the URL replaces the login
Route::get('/kalender/abo/{token}.ics', FeedController::class)
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('kalender.feed');

// Erziehungsberechtigte answer for the child from the signed link in their mail (no login). GET only
// shows the page, the POST answers – mail scanners open links, they don't send forms
Route::match(['get', 'post'], '/kalender/rueckmeldung/{event}/{guardian}', function (Event $event, Guardian $guardian, EventService $events) {
    abort_unless(app(ModuleRegistry::class)->isActive('kalender') && $guardian->user?->is_active, 404);
    $child = $guardian->user;
    $error = null;
    if (request()->isMethod('post')) {
        try {
            $events->respondAsGuardian($event, $guardian, (string) request('status'), request('comment'));
            session()->flash('kalender-guardian', 'Danke! Die Rückmeldung für '.$child->name.' ist gespeichert.');

            return redirect()->to(request()->fullUrl());
        } catch (ValidationException $e) {
            $error = collect($e->errors())->flatten()->first();
        }
    }

    return view('kalender::pages.guardian', [
        'event' => $event, 'guardian' => $guardian, 'child' => $child, 'error' => $error,
        'response' => $event->responses()->where('user_id', $child->id)->first(),
    ]);
})->middleware(['signed', 'throttle:30,1'])->whereNumber(['event', 'guardian'])->name('kalender.guardian');

// Public events for the Wehr's website (iframe and iCal), no login
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/kalender/oeffentlich', [PublicCalendarController::class, 'page'])->name('kalender.public');
    Route::get('/kalender/oeffentlich.ics', [PublicCalendarController::class, 'ics'])->name('kalender.public.ics');
});

Route::prefix('kalender')->name('kalender.')
    ->middleware(['auth', 'active', 'module:kalender'])
    ->group(function () {
        Route::get('/', fn () => view('kalender::pages.index'))->name('index');

        // The Dienstplan of a year or half-year to print or save as PDF (what the Kamerad may see)
        Route::get('/dienstplan', function () {
            $request = request();
            $year = $request->integer('jahr') ?: (int) now('Europe/Berlin')->year;
            $period = array_key_exists((string) $request->query('zeitraum'), Roster::PERIODS) ? (string) $request->query('zeitraum') : 'jahr';
            $groups = Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
            $groupId = $groups->has($request->integer('abteilung')) ? $request->integer('abteilung') : null;
            $types = array_values(array_intersect((array) $request->query('arten', []), array_keys(Event::TYPES))) ?: array_keys(Event::TYPES);
            $text = fn (string $key, string $default, int $max) => mb_substr(trim((string) ($request->query($key) ?? $default)), 0, $max);

            return view('kalender::pages.roster', [
                'year' => $year, 'period' => $period, 'groupId' => $groupId, 'groups' => $groups, 'selectedTypes' => $types,
                'years' => range((int) now('Europe/Berlin')->year - 1, (int) now('Europe/Berlin')->year + 1),
                'rows' => Roster::rows(auth()->user(), $year, $period, $groupId, $types),
                'heading' => $text('titel', app(OrganizationSettings::class)->name().' – Dienstplan '.$year.($period === 'jahr' ? '' : ' ('.Roster::PERIODS[$period].')'), 120),
                'subheading' => $text('unterzeile', $groupId ? $groups[$groupId] : 'Alle Mitglieder', 120),
                'hint' => $text('hinweis', '', 600),
                'showContacts' => $request->boolean('kontakte'),
                'contacts' => Contact::orderBy('sort_order')->orderBy('name')->get(),
                'logo' => app(OrganizationSettings::class)->logoUrl(),
            ]);
        })->name('roster');

        Route::get('/termine/neu', fn () => view('kalender::pages.form', ['event' => null]))
            ->middleware('can:manage,'.Event::class)->name('create');

        Route::get('/termine/{event}', fn (Event $event) => view('kalender::pages.show', compact('event')))
            ->whereNumber('event')->middleware('can:view,event')->name('show');

        Route::get('/termine/{event}/bearbeiten', fn (Event $event) => view('kalender::pages.form', compact('event')))
            ->whereNumber('event')->middleware('can:manage,event')->name('edit');

        Route::get('/termine/{event}/teilnehmer.csv', fn (Event $event, EventService $events) => response($events->participantsCsv($event), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="teilnehmer-'.$event->starts_at->copy()->setTimezone('Europe/Berlin')->format('Y-m-d').'.csv"',
        ]))->whereNumber('event')->middleware('can:manage,event')->name('participants');

        Route::get('/termine/{event}/teilnehmer/druck', fn (Event $event, EventService $events) => view('kalender::pages.participants-print', [
            'event' => $event, 'people' => $events->participantRows($event), 'summary' => $events->summary($event),
        ]))->whereNumber('event')->middleware('can:manage,event')->name('participants.print');

        // Terminabfragen (like Doodle): everyone with access sees the ones meant for them
        Route::get('/abfragen', fn () => view('kalender::pages.polls'))->name('polls');
        Route::get('/abfragen/neu', fn () => view('kalender::pages.poll-create'))->middleware('can:manage,'.Event::class)->name('polls.create');
        Route::get('/abfragen/{poll}', fn (Poll $poll) => view('kalender::pages.poll', compact('poll')))->whereNumber('poll')->name('polls.show');

        // Orte & Kleidung: the lists all modules share, kept here by the Terminverwalter
        Route::get('/orte-kleidung', fn () => view('kalender::pages.places'))
            ->middleware('can:manage,'.Event::class)->name('places');

        Route::get('/externe-kalender', fn () => view('kalender::pages.sources'))
            ->middleware('can:manage,'.Event::class)->name('sources');
    });
