<?php

use Illuminate\Support\Facades\Route;
use Modules\Anmeldungen\src\Livewire\FormEditor;
use Modules\Anmeldungen\src\Livewire\FormList;
use Modules\Anmeldungen\src\Livewire\FormView;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Services\RegistrationService;

Route::prefix('anmeldungen')->name('anmeldungen.')->middleware(['auth', 'active', 'module:anmeldungen'])->group(function () {
    Route::get('/', FormList::class)->name('index');
    Route::get('/neu', FormEditor::class)->middleware('can:anmeldungen-manage')->name('create');
    Route::get('/{form}', FormView::class)->whereNumber('form')->name('show');
    Route::get('/{form}/bearbeiten', FormEditor::class)->whereNumber('form')->middleware('can:anmeldungen-manage')->name('edit');

    // Teilnehmerliste for Excel and for the folder on the trip (emergency numbers, health answers while they exist)
    Route::middleware('can:anmeldungen-manage')->whereNumber('form')->group(function () {
        Route::get('/{form}/teilnehmer.csv', function (Form $form, RegistrationService $service) {
            return response($service->csv($form, auth()->user()), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="anmeldungen-'.$form->id.'.csv"',
                'Cache-Control' => 'no-store',
            ]);
        })->name('export');
        Route::get('/{form}/drucken', function (Form $form) {
            return view('anmeldungen::pages.print', [
                'form' => $form,
                'registrations' => $form->registrations()->where('status', 'registered')->with('user')->get()->sortBy(fn ($r) => $r->user->name)->values(),
            ]);
        })->name('print');
    });
});
