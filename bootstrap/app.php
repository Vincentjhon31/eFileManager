<?php

use App\Exceptions\DriveException;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every authenticated web request so that deactivating an
        // employee takes effect immediately rather than at session expiry.
        $middleware->web(append: [
            EnsureUserIsActive::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Drive failures that reach a full page request rather than a Livewire
         * action — in practice, a stored file whose bytes have gone missing.
         * The message says what happened and what to do about it, which is
         * more use to a clerk than a generic 500, and it is the sort of thing
         * MIS needs told rather than swallowed.
         */
        $exceptions->render(function (DriveException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 500)
                : response()->view('errors.drive', ['message' => $e->getMessage()], 500);
        });
    })->create();
