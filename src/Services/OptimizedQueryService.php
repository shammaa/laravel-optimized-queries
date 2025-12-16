<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shammaa\LaravelOptimizedQueries\Builders\OptimizedQueryBuilder;

class OptimizedQueryService
{
    /**
     * Create optimized query builder from model class.
     *
     * @param string $modelClass
     * @param Builder|null $baseQuery
     * @return OptimizedQueryBuilder
     */
    public function from(string $modelClass, ?Builder $baseQuery = null): OptimizedQueryBuilder
    {
        $model = new $modelClass();
        $query = $baseQuery ?? $model->newQuery();
        
        return new OptimizedQueryBuilder($query);
    }

    /**
     * Create optimized query builder from existing query.
     *
     * @param Builder $baseQuery
     * @return OptimizedQueryBuilder
     */
    public function query(Builder $baseQuery): OptimizedQueryBuilder
    {
        return new OptimizedQueryBuilder($baseQuery);
    }
}

