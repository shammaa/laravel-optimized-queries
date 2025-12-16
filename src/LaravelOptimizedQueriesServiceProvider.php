<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries;

use Illuminate\Support\ServiceProvider;

class LaravelOptimizedQueriesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/optimized-queries.php',
            'optimized-queries'
        );

        $this->app->singleton('optimized-query', function ($app) {
            return new \Shammaa\LaravelOptimizedQueries\Services\OptimizedQueryService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/optimized-queries.php' => config_path('optimized-queries.php'),
        ], 'optimized-queries-config');
    }
}

