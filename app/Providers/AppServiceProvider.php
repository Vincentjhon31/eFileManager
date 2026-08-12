<?php

namespace App\Providers;

use App\Services\SystemSettings;
use App\Support\Navigation;
use App\Support\Tour;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The LGU's own answers, laid over the config files.
         *
         * First, so that everything booted afterwards — the session lifetime,
         * the digest schedule, the drive's upload ceiling — sees the
         * municipality's setting rather than the file's default. Nothing
         * downstream knows these are settable: they keep reading config().
         *
         * Safe on a database that is not there yet: `migrate` on a fresh
         * install runs through this provider before the settings table exists,
         * and SystemSettings falls back to the config files rather than
         * failing the boot.
         */
        $this->app->make(SystemSettings::class)->applyToConfig();

        // Layouts live at resources/views/components/layouts/, the standard
        // location for <x-layouts.app>. Livewire components therefore reference
        // them as 'components.layouts.app' rather than Livewire 4's default
        // 'layouts::app' namespace — one copy of each layout, one convention.
        View::composer('components.layouts.app', function ($view) {
            $view->with('navigation', Navigation::forCurrentUser());
            $view->with('tourSteps', Tour::stepsFor());
            $view->with('tourAutoStart', Auth::user()?->needsTour() ?? false);
        });
    }
}
