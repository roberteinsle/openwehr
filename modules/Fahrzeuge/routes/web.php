<?php

use App\Support\FileResponse;
use Illuminate\Support\Facades\Route;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Attachment;
use Modules\Fahrzeuge\src\Models\Document;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Services\FleetService;

Route::prefix('fahrzeuge')->name('fahrzeuge.')
    ->middleware(['auth', 'active', 'module:fahrzeuge'])
    ->group(function () {
        Route::get('/', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.fleet', 'title' => 'Fahrzeuge']))->name('index');
        Route::get('/funkrufnamen', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.call-signs', 'title' => 'Funkrufnamen']))->name('call-signs');
        Route::get('/fristen', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.deadlines', 'title' => 'Fristen']))->name('deadlines');
        Route::get('/neu', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-form', 'title' => 'Neues Fahrzeug']))
            ->middleware('can:fahrzeuge-manage')->name('create');
        Route::get('/{vehicle}', fn (Vehicle $vehicle) => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-detail', 'title' => $vehicle->name, 'params' => ['vehicle' => $vehicle]]))
            ->whereNumber('vehicle')->name('show');
        Route::get('/{vehicle}/bearbeiten', fn (Vehicle $vehicle) => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-form', 'title' => $vehicle->name.' bearbeiten', 'params' => ['vehicle' => $vehicle]]))
            ->whereNumber('vehicle')->middleware('can:fahrzeuge-manage')->name('edit');

        // Photo, versioned by ?v= so it can be cached for long
        Route::get('/{vehicle}/foto', function (Vehicle $vehicle) {
            $photo = Profile::where('vehicle_id', $vehicle->id)->value('photo');
            abort_unless($photo, 404);

            return response(base64_decode($photo), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=31536000, immutable']);
        })->whereNumber('vehicle')->name('photo');

        // QR label for the vehicle: a scan opens the Fahrzeugakte (with "Mangel melden")
        Route::get('/{vehicle}/etikett', fn (Vehicle $vehicle) => view('fahrzeuge::pages.label', ['vehicle' => $vehicle,
            'profile' => Profile::firstOrNew(['vehicle_id' => $vehicle->id])]))->whereNumber('vehicle')->middleware('can:fahrzeuge-manage')->name('label');

        Route::get('/pruefprotokoll/{record}', function (Record $record) {
            return view('fahrzeuge::pages.record', ['record' => $record->load('vehicle', 'creator', 'deadline'),
                'profile' => Profile::firstOrNew(['vehicle_id' => $record->vehicle_id])]);
        })->whereNumber('record')->name('record');

        Route::get('/dokumente/{document}', function (Document $document) {
            abort_unless(FleetService::canSeeDocument(auth()->user(), $document), 403);

            return FileResponse::make(base64_decode($document->data), $document->name);
        })->whereNumber('document')->name('document');

        Route::get('/anhaenge/{attachment}', fn (Attachment $attachment) => FileResponse::make(base64_decode($attachment->data), $attachment->name))
            ->whereNumber('attachment')->name('attachment');
    });
