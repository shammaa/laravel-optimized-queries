<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Query Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of records that can be fetched in a single query.
    | This is a safety measure to prevent memory issues.
    |
    */
    'max_limit' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Default Output Format
    |--------------------------------------------------------------------------
    |
    | This option defines the default output format for the optimized results.
    | Options: 'array', 'eloquent', 'object'
    |
    | 'eloquent': Returns a collection of Eloquent models (standard Laravel behavior).
    | 'array': Returns a collection of associative arrays (fastest).
    | 'object': Returns a collection of stdClass objects.
    |
    */
    'default_format' => 'array',

    /*
    |--------------------------------------------------------------------------
    | Enable Query Caching
    |--------------------------------------------------------------------------
    |
    | When enabled, query results will be cached automatically.
    | Cache TTL is configurable per query.
    |
    */
    'enable_cache' => env('OPTIMIZED_QUERIES_CACHE', true),

    /*
    |--------------------------------------------------------------------------
    | Default Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Default cache time-to-live in seconds.
    | Can be overridden per query using ->cache(seconds) method.
    |
    */
    'default_cache_ttl' => env('OPTIMIZED_QUERIES_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for cache keys to avoid conflicts.
    |
    */
    'cache_prefix' => 'optimized_queries:',

    /*
    |--------------------------------------------------------------------------
    | Enable Query Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, generated SQL queries will be logged.
    | Useful for debugging and optimization.
    |
    */
    'enable_query_logging' => env('OPTIMIZED_QUERIES_LOG', false),

    /*
    |--------------------------------------------------------------------------
    | Enable Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | When enabled, query performance will be automatically monitored.
    | This allows you to see query count and execution time improvements.
    |
    */
    'enable_performance_monitoring' => env('OPTIMIZED_QUERIES_PERFORMANCE_MONITORING', true),

    /*
    |--------------------------------------------------------------------------
    | Column Cache
    |--------------------------------------------------------------------------
    |
    | Cache table columns for models without $fillable property.
    | This improves performance by avoiding metadata queries.
    |
    */
    'column_cache' => [
        // 'users' => ['id', 'name', 'email', 'created_at', 'updated_at'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Database Drivers
    |--------------------------------------------------------------------------
    |
    | List of database drivers that support JSON aggregation.
    | MySQL 5.7+, PostgreSQL 9.4+, SQLite 3.38+
    |
    */
    'supported_drivers' => [
        'mysql',
        'pgsql',
        'sqlite',
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Aggregation Function
    |--------------------------------------------------------------------------
    |
    | Database-specific JSON aggregation function.
    | 'auto' will detect automatically based on driver.
    |
    */
    'json_function' => 'auto', // 'auto', 'json_object', 'json_build_object'
];

