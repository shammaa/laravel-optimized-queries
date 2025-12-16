<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Shammaa\LaravelOptimizedQueries\LaravelOptimizedQueriesServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations if needed
        // $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelOptimizedQueriesServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageAliases($app): array
    {
        return [
            'OptimizedQuery' => \Shammaa\LaravelOptimizedQueries\Facades\OptimizedQuery::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup cache
        $app['config']->set('cache.default', 'array');
    }
}

