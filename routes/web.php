<?php

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\ScanController;
use App\Livewire\Profile\Hours;
use App\Models\Contact;
use App\Models\FeedbackThread;
use App\Models\User;
use App\Services\CredentialRegistry;
use App\Services\HoursRegistry;
use App\Services\Impersonation;
use App\Services\MagicLinkService;
use App\Services\OrganizationSettings;
use App\Support\HoursCsv;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Root: the personal start page with notices, module cards and the modules (requires login)
Route::view('/', 'pages.home')->middleware(['auth', 'active'])->name('home');

// Laravel auth middleware expects a route named 'login'; guests are sent straight to /anmelden (bootstrap/app.php)
Route::redirect('/login', '/anmelden')->name('login');

// -----------------------------------------------------------------------
// Auth routes (public)
// -----------------------------------------------------------------------
Route::name('auth.')->group(function () {
    Route::get('/anmelden', [MagicLinkController::class, 'showForm'])->name('magic-link.form');
    Route::post('/anmelden', [MagicLinkController::class, 'sendLink'])->name('magic-link.send');
    Route::get('/anmelden/gesendet', [MagicLinkController::class, 'linkSent'])->name('magic-link.sent');

    // Two-step: GET shows confirmation button, POST consumes the token; a token of the wrong shape is a 404
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('/anmelden/{token}', [MagicLinkController::class, 'showConfirm'])->where('token', MagicLinkService::TOKEN_PATTERN)->name('magic-link.confirm');
        Route::post('/anmelden/{token}', [MagicLinkController::class, 'consumeToken'])->where('token', MagicLinkService::TOKEN_PATTERN)->name('magic-link.consume');
    });

    // Double opt-in for a Kamerad's new e-mail address; the same two steps, so mail scanners cannot confirm it
    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/e-mail-bestaetigen/{token}', [EmailChangeController::class, 'show'])->name('email-change.confirm');
        Route::post('/e-mail-bestaetigen/{token}', [EmailChangeController::class, 'confirm'])->name('email-change.consume');
    });
});

// -----------------------------------------------------------------------
// Authenticated routes
// -----------------------------------------------------------------------
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/personalakte', fn () => view('pages.profile'))->name('profile');
    // Digitale Nachweise (Dienstausweis, Atemschutzpass) with a QR code for checking
    Route::get('/personalakte/nachweise', fn () => view('pages.credentials'))->name('credentials');
    // Stundennachweis from Dienste, Einsätze, Lehrgänge and Schichten
    Route::get('/personalakte/stunden', fn () => view('pages.hours'))->name('hours');
    Route::get('/personalakte/stunden.csv', function (HoursRegistry $hours) {
        $year = max(2000, min(2100, request()->integer('jahr') ?: (int) now('Europe/Berlin')->year));

        return response(HoursCsv::make($hours->forUser(auth()->user(), ...Hours::period($year)), [auth()->user()->id => auth()->user()->name]), 200, [
            'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="Stunden-'.$year.'.csv"',
        ]);
    })->name('hours.csv');
    // After the first login: the steps that make the app useful
    Route::get('/willkommen', fn () => view('pages.welcome'))->name('welcome');
    // The Bestand across modules: one search and scan field (barcode, QR, RFID, NFC) and the Ersatzbeschaffung
    Route::get('/bestand', fn () => view('pages.inventory'))->name('inventory');
    Route::get('/ansprechpartner', fn () => view('pages.contacts', ['sections' => Contact::sections()]))->name('contacts');

    // Notifications (the bell): the list, and opening one marks it read and leads to where something is to do
    Route::get('/benachrichtigungen', fn () => view('pages.notifications.index'))->name('notifications.index');
    Route::get('/benachrichtigungen/{id}', function (string $id) {
        $notification = auth()->user()->notifications()->whereKey($id)->firstOrFail();
        if (! app(Impersonation::class)->active()) {
            $notification->markAsRead();
        }
        $url = parse_url((string) ($notification->data['url'] ?? ''));

        // Only path and query are used, always on this host: a link written under another address
        // (APP_URL from the scheduler, an earlier domain) still opens here, and none leads away
        // (a leading "//" would read as another host, so it becomes one slash)
        return redirect()->to(filled($url['path'] ?? null) ? '/'.ltrim($url['path'], '/').(isset($url['query']) ? '?'.$url['query'] : '') : route('notifications.index'));
    })->whereUuid('id')->name('notifications.open');

    // Feedback conversation with the admins (own threads only)
    Route::get('/feedback/{thread}', function (FeedbackThread $thread) {
        abort_unless($thread->user_id === auth()->id(), 403);

        return view('pages.feedback.show', ['thread' => $thread]);
    })->name('feedback.show');

    // Avatar images (versioned URL, cached by the browser)
    Route::get('/avatare/{user}', function (User $user) {
        $avatar = $user->avatar ?? abort(404);

        return response(base64_decode($avatar->data), 200, [
            'Content-Type' => $avatar->mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    })->name('avatars.show');

    // Logout
    Route::post('/abmelden', [MagicLinkController::class, 'logout'])->name('logout');

    // An admin who sees the app as a Kamerad goes back to the own login
    Route::post('/zurueck-zum-admin', function (Impersonation $impersonation) {
        $admin = $impersonation->stop();

        return $admin ? redirect('/admin/kameraden') : redirect()->route('auth.magic-link.form');
    })->name('impersonation.stop');
});

// Universal scan redirect: /i/{code} → module-owned detail page (public; auth at destination)
Route::get('/i/{code}', ScanController::class)->middleware('throttle:60,1')->name('scan.code');

// Organization logo (public, versioned for browser caching)
// What a checker sees after scanning the QR code of the Digitale Nachweise: signed, valid for 10 minutes,
// no login – today's state, not a screenshot
Route::get('/nachweis/{user}', function (User $user) {
    return view('pages.credential-check', ['member' => $user, 'items' => $user->is_active ? app(CredentialRegistry::class)->verify($user) : []]);
})->middleware(['signed', 'throttle:60,1'])->whereNumber('user')->name('credentials.check');

// The app on the home screen (PWA): manifest and the page the service worker shows without network
Route::get('/manifest.webmanifest', function (OrganizationSettings $org) {
    return response()->json([
        'name' => $org->shortName(),
        'short_name' => Str::limit($org->shortName(), 12, ''),
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#1B2645',
        'theme_color' => '#1B2645',
        'lang' => 'de',
        'icons' => [
            ['src' => '/images/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => '/images/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
})->name('manifest');
Route::view('/offline', 'pages.offline')->name('offline');

Route::get('/organisation/logo', function () {
    $org = app(OrganizationSettings::class);
    abort_unless($org->logoData(), 404);

    // An uploaded SVG may carry scripts: opened directly, nothing in it may run
    return response(base64_decode($org->logoData()), 200, [
        'Content-Type' => $org->logoMime() ?? 'image/webp',
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('organization.logo');

// -----------------------------------------------------------------------
// Old English addresses: bookmarks, the station monitor and links in sent mails keep working
// -----------------------------------------------------------------------
foreach ([
    'auth/login/sent' => 'anmelden/gesendet',
    'auth/login' => 'anmelden',
    'auth/email' => 'e-mail-bestaetigen',
    'profile' => 'personalakte',
    'profil' => 'personalakte',
    'avatars' => 'avatare',
    'organization/logo' => 'organisation/logo',
    'fahrzeugbuchung/calendar' => 'fahrzeugbuchung',
    'fahrzeugbuchung/kalender' => 'fahrzeugbuchung',
    'fahrzeugbuchung/my-bookings' => 'fahrzeugbuchung/meine-buchungen',
    'fahrzeugbuchung/decisions' => 'fahrzeugbuchung/entscheidungen',
    'admin/login' => 'admin/anmelden',
    'admin/users' => 'admin/kameraden',
    'admin/groups' => 'admin/abteilungen',
    'admin/functions' => 'admin/funktionen',
    'admin/feedback-threads' => 'admin/feedback',
    'admin/manage-modules' => 'admin/module',
    'admin/modules' => 'admin/module',
    'admin/services' => 'admin/einstellungen',
    'admin/dienste' => 'admin/einstellungen',
    'admin/organization-settings-page' => 'admin/einstellungen',
    'admin/e-mail-log' => 'admin/logs',
    'admin/fahrzeugbuchung/login' => 'admin/anmelden',
    'admin/fahrzeugbuchung/anmelden' => 'admin/anmelden',
    'admin/fahrzeugbuchung/bookings' => 'admin/fahrzeugbuchung/buchungen',
    'admin/fahrzeugbuchung/vehicles' => 'admin/fahrzeugbuchung/fahrzeuge',
    'admin/fahrzeugbuchung/mail-templates' => 'admin/fahrzeugbuchung/e-mail-texte',
    'admin/kleiderkammer/login' => 'admin/anmelden',
    'admin/kleiderkammer/anmelden' => 'admin/anmelden',
    'admin/kleiderkammer/data-transfer-page' => 'admin/kleiderkammer/datentransfer',
    'admin/kleiderkammer/inventory-report-page' => 'admin/kleiderkammer/bestandsbericht',
    'admin/kleiderkammer/scan-page' => 'admin/kleiderkammer/scanner',
    'admin/kleiderkammer/messages' => 'admin/kleiderkammer/maengelmanagement',
    'admin/kleiderkammer/item-categories' => 'admin/kleiderkammer/kategorien',
    'admin/kleiderkammer/item-types' => 'admin/kleiderkammer/artikeltypen',
    'admin/kleiderkammer/items' => 'admin/kleiderkammer/einzelteile',
    'admin/kleiderkammer/members' => 'admin/kleiderkammer/kameraden',
    'admin/kleiderkammer/stocks' => 'admin/kleiderkammer/massenbestand',
    'admin/maengelmanagement/login' => 'admin/anmelden',
    'admin/maengelmanagement/anmelden' => 'admin/anmelden',
    'admin/maengelmanagement/defects' => 'admin/maengelmanagement/maengel',
    'admin/maengelmanagement/devices' => 'admin/maengelmanagement/geraete',
    'admin/ausruestung/login' => 'admin/anmelden',
    'admin/ausruestung/anmelden' => 'admin/anmelden',
    'admin/ausruestung/categories' => 'admin/ausruestung/kategorien',
    'admin/ausruestung/damages' => 'admin/ausruestung/schaeden',
    'admin/ausruestung/equipment' => 'admin/ausruestung/geraete',
    'admin/ausruestung/loans' => 'admin/ausruestung/ausleihe',
    'admin/ausruestung/locations' => 'admin/ausruestung/standorte',
    'admin/ausruestung/recalls' => 'admin/ausruestung/rueckrufe',
    'admin/ausruestung/load-plan' => 'admin/ausruestung/beladeplan',
    // Module settings moved into the admin panel
    'einsatzbericht/einstellungen' => 'admin/einsatzbericht/einstellungen',
    'personalverwaltung/einstellungen' => 'admin/personalverwaltung/einstellungen',
] as $old => $new) {
    Route::get($old.'/{rest?}', function (?string $rest = null) use ($new) {
        // Admin sub-pages: …/create → …/neu, …/{id}/edit → …/{id}/bearbeiten
        $rest = preg_replace(['#^create$#', '#/edit$#'], ['neu', '/bearbeiten'], (string) $rest);
        $query = request()->getQueryString();

        return redirect('/'.$new.($rest !== '' ? '/'.$rest : '').($query ? '?'.$query : ''), 301);
    })->where('rest', '.*');
}
