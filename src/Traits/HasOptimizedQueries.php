<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Traits;

use Shammaa\LaravelOptimizedQueries\Builders\OptimizedQueryBuilder;

trait HasOptimizedQueries
{
    /**
     * Boot the trait and register model events.
     */
    protected static function bootHasOptimizedQueries(): void
    {
        static::saved(fn ($model) => $model->clearOptimizedCache());
        static::deleted(fn ($model) => $model->clearOptimizedCache());
        static::restored(fn ($model) => $model->clearOptimizedCache());
    }

    /**
     * Clear optimized query cache for this model.
     *
     * @return void
     */
    public function clearOptimizedCache(): void
    {
        if (\Illuminate\Support\Facades\Cache::supportsTags()) {
            \Illuminate\Support\Facades\Cache::tags([$this->getTable()])->flush();
        }
    }

    /**
     * Create a new optimized query builder instance.
     *
     * @param \Illuminate\Database\Eloquent\Builder|null $baseQuery
     * @return OptimizedQueryBuilder
     */
    public static function optimizedQuery(?\Illuminate\Database\Eloquent\Builder $baseQuery = null): OptimizedQueryBuilder
    {
        $query = $baseQuery ?? static::query();
        
        return new OptimizedQueryBuilder($query);
    }

    /**
     * Optimized query - clear and professional name.
     *
     * @param \Illuminate\Database\Eloquent\Builder|null $baseQuery
     * @return OptimizedQueryBuilder
     */
    public static function optimized(?\Illuminate\Database\Eloquent\Builder $baseQuery = null): OptimizedQueryBuilder
    {
        return static::optimizedQuery($baseQuery);
    }

    /**
     * Short alias for optimized() - quick and easy.
     *
     * @param \Illuminate\Database\Eloquent\Builder|null $baseQuery
     * @return OptimizedQueryBuilder
     */
    public static function opt(?\Illuminate\Database\Eloquent\Builder $baseQuery = null): OptimizedQueryBuilder
    {
        return static::optimizedQuery($baseQuery);
    }
}

