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
        $this->app->bind(
            \App\Contracts\CreateBatchServiceInterface::class,
            \App\Services\CreateBatchService::class
        );

        $this->app->bind(
            \App\Contracts\ProcessBatchServiceInterface::class,
            \App\Services\ProcessBatchService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
