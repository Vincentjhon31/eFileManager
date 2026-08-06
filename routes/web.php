<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Departments;
use App\Livewire\Admin\Users;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    // Registered only when Google credentials are present, so the feature is
    // genuinely absent rather than half-present when it is not configured.
    if (config('services.google.client_id')) {
        Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
    }
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::view('/', 'dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/offices', Departments::class)
            ->middleware('can:departments.manage')
            ->name('departments.index');

        Route::get('/users', Users::class)
            ->middleware('can:users.manage.own_department')
            ->name('users.index');

        Route::get('/audit', AuditTrail::class)
            ->middleware('can:audit.view.own_department')
            ->name('audit.index');
    });
});
