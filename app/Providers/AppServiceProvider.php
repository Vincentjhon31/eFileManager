<?php

namespace App\Providers;

use App\Support\Navigation;
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
        // Layouts live at resources/views/components/layouts/, the standard
        // location for <x-layouts.app>. Livewire components therefore reference
        // them as 'components.layouts.app' rather than Livewire 4's default
        // 'layouts::app' namespace — one copy of each layout, one convention.
        View::composer('components.layouts.app', function ($view) {
            $view->with('navigation', Navigation::forCurrentUser());
        });
    }
}
