<?php

use App\Support\DisplayToken;
use Illuminate\Support\Facades\Route;
use Modules\Fahrzeugbuchung\src\Http\Controllers\BookingEventController;
use Modules\Fahrzeugbuchung\src\Http\Controllers\BookingReturnController;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

Route::prefix('fahrzeugbuchung')->name('fahrzeugbuchung.')->group(function () {

    // -----------------------------------------------------------------------
    // Station monitor: read-only, no user. Admins or the secret DISPLAY_TOKEN;
    // everyone else gets a 404 so the page doesn't reveal that it exists.
    // -----------------------------------------------------------------------
    Route::get('/monitor/{key?}', function (?string $key = null) {
        abort_unless(DisplayToken::allows($key), 404);

        return response()
            ->view('fahrzeugbuchung::pages.monitor')
            ->header('Referrer-Policy', 'no-referrer');   // X-Robots-Tag comes from the NoIndex middleware
    })->middleware('throttle:30,1')->name('monitor');

    // -----------------------------------------------------------------------
    // Return a vehicle early from the reminder mail: signed link, no login needed
    // -----------------------------------------------------------------------
    Route::middleware(['signed', 'throttle:30,1'])->group(function () {
        Route::get('/buchungen/{booking}/zurueckgeben', [BookingReturnController::class, 'show'])->name('bookings.return');
        Route::post('/buchungen/{booking}/zurueckgeben', [BookingReturnController::class, 'store']);

        // Links in reminder mails sent before the German addresses; the signature covers the old address
        Route::get('/bookings/{booking}/return', [BookingReturnController::class, 'show'])->name('bookings.return.legacy');
        Route::post('/bookings/{booking}/return', [BookingReturnController::class, 'store']);
    });

    // -----------------------------------------------------------------------
    // Authenticated routes
    // -----------------------------------------------------------------------
    Route::middleware(['auth', 'active', 'module:fahrzeugbuchung'])->group(function () {
        // The calendar is the module's start page
        Route::get('/', fn () => view('fahrzeugbuchung::pages.calendar'))->name('calendar');
        Route::get('/meine-buchungen', fn () => view('fahrzeugbuchung::pages.my-bookings'))->name('my-bookings');

        // Conflict decisions (deciders and admins)
        Route::middleware('can:decide-bookings')->group(function () {
            Route::get('/entscheidungen', fn () => view('fahrzeugbuchung::pages.decisions.index'))->name('decisions.index');
            Route::get('/entscheidungen/{decision}', fn (BookingDecision $decision) => view('fahrzeugbuchung::pages.decisions.show', ['decision' => $decision]))
                ->name('decisions.show');
        });

        // FullCalendar JSON events feed
        Route::get('/buchungen/termine', [BookingEventController::class, 'index'])->name('bookings.events');
    });
});
