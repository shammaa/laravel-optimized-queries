<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Shammaa\LaravelOptimizedQueries\Builders\OptimizedQueryBuilder query(\Illuminate\Database\Eloquent\Builder $baseQuery = null)
 * @method static \Shammaa\LaravelOptimizedQueries\Builders\OptimizedQueryBuilder from(string $modelClass, \Illuminate\Database\Eloquent\Builder $baseQuery = null)
 *
 * @see \Shammaa\LaravelOptimizedQueries\Services\OptimizedQueryService
 */
class OptimizedQuery extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'optimized-query';
    }
}

