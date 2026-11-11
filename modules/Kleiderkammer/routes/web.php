<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('kleiderkammer')->name('kleiderkammer.')
    ->middleware(['auth', 'active', 'module:kleiderkammer'])
    ->group(function () {
        Route::get('/', function () {
            /** @var User $user */
            $user = auth()->user();
            $items = $user->kleiderkammerItems()
                ->with(['item.itemType', 'item.size', 'item.group', 'itemType', 'size', 'group', 'messages'])
                ->orderByDesc('issued_at')
                ->get();

            return view('kleiderkammer::pages.my-equipment', compact('items'));
        })->name('index');
    });
