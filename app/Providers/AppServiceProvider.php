<?php

namespace App\Providers;

use App\Services\VersionService;
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
        // Version déployée affichée dans le pied de page de l'administration.
        View::composer('admin.layout', function ($view) {
            $view->with('appVersion', VersionService::current());
        });
    }
}
