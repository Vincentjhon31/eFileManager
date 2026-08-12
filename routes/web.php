<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\PublicFileController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\RoutingSlipController;
use App\Http\Controllers\TourController;
use App\Livewire\Admin\Announcements;
use App\Livewire\Admin\AuditTrail;
use App\Livewire\Admin\Departments;
use App\Livewire\Admin\Disclosures;
use App\Livewire\Admin\Storage;
use App\Livewire\Admin\Users;
use App\Livewire\Admin\WorkspaceApps;
use App\Livewire\Alerts;
use App\Livewire\Dashboard;
use App\Livewire\Desk\Index as Desk;
use App\Livewire\Documents\Index as DocumentIndex;
use App\Livewire\Documents\Register as RegisterDocument;
use App\Livewire\Documents\Show as DocumentShow;
use App\Livewire\Documents\Track;
use App\Livewire\Drive\Browser as DriveBrowser;
use App\Livewire\Settings\Appearance as AppearanceSettings;
use App\Livewire\Settings\Notifications as NotificationSettings;
use App\Livewire\Settings\Preferences as PreferenceSettings;
use App\Livewire\Settings\Profile as ProfileSettings;
use App\Livewire\Settings\Security as SecuritySettings;
use App\Livewire\Settings\System as SystemSettingsScreen;
use App\Livewire\Workspace\Index as Workspace;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public page
|--------------------------------------------------------------------------
|
| No authentication, and at the root of the domain on purpose. This is a
| gov.ph address: somebody who finds it should meet the municipality's notices
| and its Full Disclosure Policy board, not a sign-in form for a system they
| have no account on. Staff reach their desk from the link in the corner.
|
| Every route below reads through a Live scope. Nothing here can reach a draft,
| a withdrawn disclosure, or anything at all in the drive.
|
*/

Route::name('public.')->group(function () {
    Route::get('/', [PublicPortalController::class, 'home'])->name('home');
    Route::get('/notices', [PublicPortalController::class, 'announcements'])->name('announcements');
    Route::get('/notices/{announcement}', [PublicPortalController::class, 'announcement'])->name('announcement');
    Route::get('/disclosure', [PublicPortalController::class, 'disclosure'])->name('disclosure');

    // Throttled: a disclosure board is meant to be read, not harvested in a
    // loop by something that will then complain the server is slow.
    Route::get('/disclosure/{publicFile}/download', [PublicFileController::class, 'download'])
        ->middleware('throttle:60,1')
        ->name('download');
});

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

});

/*
|--------------------------------------------------------------------------
| Google sign-in
|--------------------------------------------------------------------------
|
| Registered only when credentials are present, so the feature is genuinely
| absent rather than half-present when it is not configured.
|
| Deliberately outside the 'guest' group, because these two routes serve two
| audiences. A signed-out employee uses them to sign in; a signed-in one uses
| them to link Google to the account they already have, from Settings →
| Security. Google redirects back to one callback either way, so the callback
| decides which of the two happened by asking whether anybody is signed in.
|
*/
if (config('services.google.client_id')) {
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
}

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/desk', Desk::class)
        ->middleware('can:documents.view.own_department')
        ->name('desk');

    Route::get('/alerts', Alerts::class)->name('alerts');

    Route::post('/tour/complete', [TourController::class, 'complete'])->name('tour.complete');

    Route::get('/workspace', Workspace::class)->name('workspace');

    Route::get('/drive', DriveBrowser::class)->name('drive');

    /*
     * The only way to read a stored file.
     *
     * The documents disk lives outside the web root with 'serve' => false, so
     * there is no URL that reaches the bytes and no symlink pointing at them.
     * Both actions authorise through FilePolicy and write to the audit trail.
     */
    Route::get('/files/{file}/download', [DocumentFileController::class, 'download'])->name('files.download');
    Route::get('/files/{file}/preview', [DocumentFileController::class, 'preview'])->name('files.preview');

    // Before nothing in particular, but note it takes ids as a query string
    // rather than a path segment: it serves a selection, not one record.
    Route::get('/files-bundle', [DocumentFileController::class, 'bundle'])->name('files.bundle');

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

        Route::get('/{document}/slip', [RoutingSlipController::class, 'show'])
            ->middleware('can:documents.view.own_department')
            ->name('slip');
    });

    /*
     * Where a scanned routing slip lands.
     *
     * Short path on purpose: it is encoded in a QR square printed on A5 paper
     * and photographed in a corridor, so every character costs resolution.
     *
     * 'signed:relative' checks the path and query only, not the host — see
     * App\Support\TrackingLink for why a printed link must survive the site
     * moving. It is checked before 'auth' so a tampered link is refused
     * outright rather than sending a stranger to a sign-in page.
     */
    Route::get('/t/{document:tracking_no}', Track::class)
        ->middleware('signed:relative')
        ->name('track');

    /*
     * Settings.
     *
     * Everything but System belongs to the signed-in employee and needs no
     * permission beyond being signed in — a person may always change their own
     * password and decide how their own screens look. System is gated on the
     * same permission as Storage & Backups, because it changes the municipality.
     */
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::redirect('/', '/settings/profile')->name('index');

        Route::get('/profile', ProfileSettings::class)->name('profile');
        Route::get('/security', SecuritySettings::class)->name('security');
        Route::get('/appearance', AppearanceSettings::class)->name('appearance');
        Route::get('/preferences', PreferenceSettings::class)->name('preferences');
        Route::get('/notifications', NotificationSettings::class)->name('notifications');

        Route::get('/system', SystemSettingsScreen::class)
            ->middleware('can:settings.manage')
            ->name('system');
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

        Route::get('/notices', Announcements::class)
            ->middleware('can:public.publish')
            ->name('announcements.index');

        Route::get('/disclosures', Disclosures::class)
            ->middleware('can:public.publish')
            ->name('disclosures.index');

        Route::get('/apps', WorkspaceApps::class)
            ->middleware('can:apps.manage')
            ->name('apps.index');

        Route::get('/storage', Storage::class)
            ->middleware('can:settings.manage')
            ->name('storage.index');

        Route::get('/storage/backups/{backup}/download', [BackupController::class, 'download'])
            ->middleware('can:settings.manage')
            ->name('storage.download');
    });
});
