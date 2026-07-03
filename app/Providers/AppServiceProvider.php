<?php

namespace App\Providers;

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
        if (class_exists(\App\Modules\ModuleViewRegistrar::class)) {
            app(\App\Modules\ModuleViewRegistrar::class)->registerEnabled();
        }
    }
}
