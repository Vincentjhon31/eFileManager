<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Departments;
use App\Livewire\Admin\Users;
use App\Livewire\Dashboard;
use App\Livewire\Desk\Index as Desk;
use App\Livewire\Documents\Index as DocumentIndex;
use App\Livewire\Documents\Register as RegisterDocument;
use App\Livewire\Documents\Show as DocumentShow;
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

    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/desk', Desk::class)
        ->middleware('can:documents.view.own_department')
        ->name('desk');

    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/', DocumentIndex::class)
            ->middleware('can:documents.view.own_department')
            ->name('index');

        // Before the {document} route, or "register" is read as a tracking key.
        Route::get('/register', RegisterDocument::class)
            ->middleware('can:documents.create')
            ->name('register');

        // Authorisation for a specific document is the policy's job, in the
        // component — the middleware here only establishes that this user has
        // any business on a document screen at all.
        Route::get('/{document}', DocumentShow::class)
            ->middleware('can:documents.view.own_department')
            ->name('show');
    });

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
