<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

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
        // Gunakan custom pagination view dengan tema biru
        Paginator::defaultView('vendor.pagination.custom-blue');
        Paginator::defaultSimpleView('vendor.pagination.custom-blue');
    }
}
