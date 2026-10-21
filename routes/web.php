<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Auth\Middleware\RequirePassword;
use App\Http\Middleware\SetLocale;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;

Route::redirect('/', '/fr');

Route::prefix('{locale}')
    ->whereIn('locale', ['en', 'fr', 'de', 'it', 'es', 'pt'])
    ->middleware(SetLocale::class)
    ->group(function () {

        Route::livewire('/', 'pages::client.welcome')
            ->name('welcome');

        Route::livewire('/events', 'pages::client.events')
            ->name('events');

        Route::livewire('/events/{event}', 'pages::client.event')
            ->name('events.show');
    });

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/dashboard', 'pages::admin.dashboard')
            ->name('dashboard');

        Route::livewire('/profile', 'pages::admin.auth.profile')
            ->name('profile');

        Route::livewire('/security', 'pages::admin.auth.security')
            ->middleware(
                RequirePassword::using('admin.confirm-password')
            )
            ->name('security');

        Route::livewire('/confirm-password', 'pages::admin.auth.confirm-password')
            ->name('confirm-password');

        // Route::livewire('/security', Security::class)
        //     ->middleware('password.confirm')
        //     ->name('security');
    });



// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::view('dashboard', 'dashboard')->name('dashboard');
// });

require __DIR__ . '/settings.php';
