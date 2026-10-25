<?php

use Illuminate\Support\Facades\Route;

Route::prefix('gefahrstoffe')->name('gefahrstoffe.')
    ->middleware(['auth', 'active', 'module:gefahrstoffe'])
    ->group(function () {
        // One page with all data, searched in the browser; the core service worker keeps it for offline use
        Route::get('/', fn () => view('gefahrstoffe::pages.index'))->name('index');
    });
