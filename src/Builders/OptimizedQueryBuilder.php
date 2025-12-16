<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shammaa\LaravelOptimizedQueries\Services\RelationBuilder;
use Shammaa\LaravelOptimizedQueries\Services\PerformanceMonitor;

class OptimizedQueryBuilder
{
    protected Builder $baseQuery;
    protected Model $model;
    protected array $relations = [];
    protected array $counts = [];
    protected array $wheres = [];
    protected array $orderBys = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $searchTerms = [];
    protected array $searchFields = [];
    protected array $searchRelations = [];
    protected bool $useFullTextSearch = false;
    protected ?int $cacheTtl = null;
    protected bool $enableCache = true;
    protected ?string $cacheKey = null;
    protected bool $enablePerformanceMonitoring = true;
    protected ?PerformanceMonitor $performanceMonitor = null;

    public function __construct(Builder $baseQuery)
    {
        $this->baseQuery = $baseQuery;
        $this->model = $baseQuery->getModel();
        $this->enableCache = config('optimized-queries.enable_cache', true);
        $this->enablePerformanceMonitoring = config('optimized-queries.enable_performance_monitoring', true);
        
        if ($this->enablePerformanceMonitoring) {
            $this->performanceMonitor = new PerformanceMonitor();
            $this->performanceMonitor->start();
        }
    }

    /**
     * Load a single relation (belongsTo, hasOne) as JSON object.
     *
     * @param string $relation
     * @param array|string $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withRelation(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = [
            'type' => 'relation',
            'name' => $relation,
            'columns' => is_string($columns) ? [$columns] : $columns,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Smart relation loader - automatically detects relation type!
     * 
     * This method automatically detects the relation type and uses the appropriate handler:
     * - BelongsTo, HasOne -> JSON object (single relation)
     * - HasMany -> JSON array (collection)
     * - BelongsToMany -> JSON array (many-to-many)
     * - MorphTo, MorphOne, MorphMany -> Polymorphic handling
     * 
     * Note: If columns are not specified, it will auto-detect from model's $fillable.
     * To specify columns explicitly, pass them as second parameter:
     * ->with('author', ['id', 'name', 'email'])
     *
     * @param string $relation
     * @param array|string|null $columns Use ['*'] for auto-detect, or specify columns array
     * @param \Closure|null $callback
     * @return $this
     */
    public function with(string $relation, array|string|null $columns = null, ?\Closure $callback = null): self
    {
        // If columns not specified, use auto-detect
        $columns = $columns ?? ['*'];
        
        // Auto-detect relation type
        $relationType = $this->detectRelationType($relation);
        
        return match ($relationType) {
            'single' => $this->withRelation($relation, $columns, $callback),
            'collection' => $this->withCollection($relation, $columns, $callback),
            'many_to_many' => $this->withManyToMany($relation, $columns, $callback),
            'polymorphic' => $this->withPolymorphic($relation, $columns, $callback),
            'nested' => $this->withNested($relation, $columns, $callback),
            default => $this->withRelation($relation, $columns, $callback), // Fallback to single
        };
    }

    /**
     * Detect the type of relation automatically.
     *
     * @param string $relationName
     * @return string 'single'|'collection'|'many_to_many'|'polymorphic'|'nested'
     */
    protected function detectRelationType(string $relationName): string
    {
        // Check if it's a nested relation (contains dots)
        if (str_contains($relationName, '.')) {
            return 'nested';
        }

        // Check if relation exists on the model
        if (!method_exists($this->model, $relationName)) {
            // If relation doesn't exist, default to single relation
            return 'single';
        }

        try {
            $relation = $this->model->{$relationName}();
            
            if (!$relation instanceof Relation) {
                return 'single'; // Fallback
            }

            // Detect relation type
            return match (true) {
                $relation instanceof BelongsToMany => 'many_to_many',
                $relation instanceof HasMany => 'collection',
                $relation instanceof BelongsTo => 'single',
                $relation instanceof HasOne => 'single',
                $relation instanceof MorphTo,
                $relation instanceof MorphOne,
                $relation instanceof MorphMany => 'polymorphic',
                default => 'single', // Fallback for unknown types
            };
        } catch (\Exception $e) {
            // If there's an error getting the relation, default to single
            Log::warning("Could not detect relation type for '{$relationName}': " . $e->getMessage());
            return 'single';
        }
    }

    /**
     * Specify columns explicitly for a relation (clearer syntax).
     *
     * @param string $relation
     * @param array $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withColumns(string $relation, array $columns, ?\Closure $callback = null): self
    {
        return $this->withRelation($relation, $columns, $callback);
    }

    /**
     * Short alias for withCollection - easier to type.
     * 
     * Note: If columns are not specified, it will auto-detect from model's $fillable.
     * To specify columns explicitly, pass them as second parameter:
     * ->withMany('promocodes', ['id', 'code', 'discount'])
     *
     * @param string $relation
     * @param array|string|null $columns Use ['*'] for auto-detect, or specify columns array
     * @param \Closure|null $callback
     * @return $this
     */
    public function withMany(string $relation, array|string|null $columns = null, ?\Closure $callback = null): self
    {
        // If columns not specified, use auto-detect
        $columns = $columns ?? ['*'];
        return $this->withCollection($relation, $columns, $callback);
    }

    /**
     * Specify columns explicitly for a collection relation (clearer syntax).
     *
     * @param string $relation
     * @param array $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withManyColumns(string $relation, array $columns, ?\Closure $callback = null): self
    {
        return $this->withCollection($relation, $columns, $callback);
    }

    /**
     * Load a collection relation (hasMany, hasManyThrough) as JSON array.
     *
     * @param string $relation
     * @param array|string $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withCollection(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = [
            'type' => 'collection',
            'name' => $relation,
            'columns' => is_string($columns) ? [$columns] : $columns,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Load nested relations (e.g., 'profile.company.country').
     *
     * @param string $relationPath
     * @param array|string $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withNested(string $relationPath, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = [
            'type' => 'nested',
            'name' => $relationPath,
            'columns' => is_string($columns) ? [$columns] : $columns,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Load belongsToMany relation.
     *
     * @param string $relation
     * @param array|string $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withManyToMany(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = [
            'type' => 'many_to_many',
            'name' => $relation,
            'columns' => is_string($columns) ? [$columns] : $columns,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Load polymorphic relation.
     *
     * @param string $relation
     * @param array|string $columns
     * @param \Closure|null $callback
     * @return $this
     */
    public function withPolymorphic(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = [
            'type' => 'polymorphic',
            'name' => $relation,
            'columns' => is_string($columns) ? [$columns] : $columns,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Count related records.
     *
     * @param string $relation
     * @param \Closure|null $callback
     * @return $this
     */
    public function withCount(string $relation, ?\Closure $callback = null): self
    {
        $this->counts[] = [
            'name' => $relation,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Add where clause.
     *
     * @param string|array $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->wheres[] = ['column' => $key, 'operator' => '=', 'value' => $val];
            }
        } else {
            $this->wheres[] = [
                'column' => $column,
                'operator' => $value === null ? '=' : $operator,
                'value' => $value ?? $operator,
            ];
        }

        return $this;
    }

    /**
     * Add where clause for active records (common use case).
     *
     * @return $this
     */
    public function active(): self
    {
        return $this->where('is_active', true);
    }

    /**
     * Add where clause for published records (common use case).
     *
     * @return $this
     */
    public function published(): self
    {
        return $this->where('published', true);
    }

    /**
     * Add where clause for latest records.
     *
     * @param string $column
     * @return $this
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add where clause for oldest records.
     *
     * @param string $column
     * @return $this
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Add whereIn clause.
     *
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'column' => $column,
            'operator' => 'IN',
            'value' => $values,
        ];

        return $this;
    }

    /**
     * Search in multiple fields (fast LIKE search).
     *
     * @param string $term Search term
     * @param array|string|null $fields Fields to search in (null = auto-detect from $fillable)
     * @return $this
     */
    public function search(string $term, array|string|null $fields = null): self
    {
        if (empty(trim($term))) {
            return $this;
        }

        $this->searchTerms[] = trim($term);
        
        if ($fields === null) {
            // Auto-detect searchable fields from model's $fillable
            $fillable = $this->model->getFillable();
            $this->searchFields = array_filter($fillable, function($field) {
                // Exclude non-searchable fields
                return !in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at']);
            });
        } else {
            $this->searchFields = is_string($fields) ? [$fields] : $fields;
        }

        return $this;
    }

    /**
     * Search in specific fields only.
     *
     * @param string $term Search term
     * @param array|string $fields Fields to search in
     * @return $this
     */
    public function searchIn(string $term, array|string $fields): self
    {
        return $this->search($term, $fields);
    }

    /**
     * Search in relation fields (e.g., search in author name).
     *
     * @param string $relation Relation name
     * @param string $term Search term
     * @param array|string $fields Fields in relation to search
     * @return $this
     */
    public function searchRelation(string $relation, string $term, array|string $fields): self
    {
        if (empty(trim($term))) {
            return $this;
        }

        $this->searchRelations[] = [
            'relation' => $relation,
            'term' => trim($term),
            'fields' => is_string($fields) ? [$fields] : $fields,
        ];

        return $this;
    }

    /**
     * Enable full-text search (for databases that support it).
     *
     * @param bool $enable
     * @return $this
     */
    public function useFullTextSearch(bool $enable = true): self
    {
        $this->useFullTextSearch = $enable;
        return $this;
    }

    /**
     * Add orderBy clause.
     *
     * @param string $column
     * @param string $direction
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBys[] = [
            'column' => $column,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
        ];

        return $this;
    }

    /**
     * Set limit.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): self
    {
        $maxLimit = config('optimized-queries.max_limit', 1000);
        $this->limit = min($limit, $maxLimit);

        return $this;
    }

    /**
     * Set offset.
     *
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Enable caching for this query.
     *
     * @param int|null $ttl
     * @return $this
     */
    public function cache(?int $ttl = null): self
    {
        $this->enableCache = true;
        $this->cacheTtl = $ttl ?? config('optimized-queries.default_cache_ttl', 3600);

        return $this;
    }

    /**
     * Disable caching for this query.
     *
     * @return $this
     */
    public function withoutCache(): self
    {
        $this->enableCache = false;

        return $this;
    }

    /**
     * Set custom cache key.
     *
     * @param string $key
     * @return $this
     */
    public function cacheKey(string $key): self
    {
        $this->cacheKey = $key;

        return $this;
    }

    /**
     * Execute query and get results.
     *
     * @param string $format 'array' or 'eloquent'
     * @return Collection
     */
    public function get(string $format = 'array'): Collection
    {
        // Check cache
        if ($this->enableCache) {
            $cacheKey = $this->getCacheKey();
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $this->formatResults($cached, $format);
            }
        }

        // Build and execute query
        $sql = $this->buildQuery();
        $results = $this->executeQuery($sql);

        // Stop performance monitoring after query execution
        if ($this->performanceMonitor) {
            $this->performanceMonitor->stop();
        }

        // Cache results
        if ($this->enableCache && $this->cacheTtl) {
            Cache::put($cacheKey, $results, $this->cacheTtl);
        }

        return $this->formatResults($results, $format);
    }

    /**
     * Get performance statistics.
     * Note: This should be called after get() to get accurate results.
     *
     * @return array
     */
    public function getPerformance(): array
    {
        if (!$this->performanceMonitor) {
            return [
                'query_count' => 1,
                'execution_time_ms' => 0,
                'total_query_time_ms' => 0,
                'queries' => [],
            ];
        }

        // Get results (monitoring was stopped in get())
        // If get() hasn't been called yet, stop monitoring now
        return $this->performanceMonitor->stop();
    }

    /**
     * Compare performance with traditional Eloquent query.
     *
     * @param \Closure $traditionalQuery Callback that executes traditional query
     * @return array
     */
    public function comparePerformance(\Closure $traditionalQuery): array
    {
        // Measure traditional query
        $beforeMonitor = new PerformanceMonitor();
        $beforeMonitor->start();
        $traditionalQuery();
        $before = $beforeMonitor->stop();

        // Measure optimized query
        $after = $this->getPerformance();

        return PerformanceMonitor::compare($before, $after);
    }

    /**
     * Get first result.
     *
     * @param string $format
     * @return array|Model|null
     */
    public function first(string $format = 'array'): array|Model|null
    {
        return $this->limit(1)->get($format)->first();
    }

    /**
     * Paginate results.
     *
     * @param int $perPage
     * @param string $pageName
     * @param int|null $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null)
    {
        $page = $page ?? request()->input($pageName, 1);
        $offset = ($page - 1) * $perPage;

        $total = $this->getBaseQueryCount();
        $results = $this->offset($offset)->limit($perPage)->get();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Get SQL query string.
     *
     * @return string
     */
    public function toSql(): string
    {
        return $this->buildQuery();
    }

    /**
     * Get query bindings.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->baseQuery->getBindings();
    }

    /**
     * Debug query - log SQL and execution time.
     *
     * @return $this
     */
    public function debug(): self
    {
        $startTime = microtime(true);
        $sql = $this->toSql();
        $executionTime = (microtime(true) - $startTime) * 1000;

        $performance = $this->getPerformance();

        Log::info('Optimized Query Debug', [
            'sql' => $sql,
            'bindings' => $this->getBindings(),
            'execution_time_ms' => round($executionTime, 2),
            'query_count' => $performance['query_count'] ?? 1,
            'performance' => $performance,
        ]);

        return $this;
    }

    /**
     * Show performance summary (for debugging/development).
     *
     * @return array
     */
    public function showPerformance(): array
    {
        $performance = $this->getPerformance();
        
        if (empty($performance)) {
            return ['message' => 'Performance monitoring is disabled'];
        }

        return [
            'summary' => [
                'queries' => $performance['query_count'] . ' query' . ($performance['query_count'] !== 1 ? 's' : ''),
                'execution_time' => $performance['execution_time_ms'] . 'ms',
                'total_query_time' => $performance['total_query_time_ms'] . 'ms',
            ],
            'details' => $performance,
        ];
    }

    /**
     * Build the optimized SQL query.
     *
     * @return string
     */
    protected function buildQuery(): string
    {
        $relationBuilder = new RelationBuilder($this->model, $this->baseQuery);
        
        // Build base query - use base query as subquery if it has complex conditions
        $baseTable = $this->model->getTable();
        $baseColumns = $this->getBaseColumns();
        
        $selects = array_map(function ($col) use ($baseTable) {
            return "{$baseTable}.{$col}";
        }, $baseColumns);

        // Add relation JSON aggregations
        foreach ($this->relations as $relation) {
            $jsonColumn = $relationBuilder->buildRelationJson($relation);
            if ($jsonColumn) {
                $selects[] = $jsonColumn;
            }
        }

        // Add counts
        foreach ($this->counts as $count) {
            $countColumn = $relationBuilder->buildCount($count);
            if ($countColumn) {
                $selects[] = $countColumn;
            }
        }

        // Build WHERE clauses
        $wheres = $this->buildWheres();
        
        // Build search conditions
        $searchWheres = $this->buildSearchWheres();
        if (!empty($searchWheres)) {
            $wheres = array_merge($wheres, $searchWheres);
        }
        
        // Build ORDER BY
        $orderBys = $this->buildOrderBys();

        // Check if base query has conditions - if so, use it as subquery
        $baseQuerySql = $this->baseQuery->toSql();
        $hasBaseConditions = !empty($this->baseQuery->getQuery()->wheres);

        if ($hasBaseConditions && count($this->wheres) === 0 && empty($this->orderBys)) {
            // Use base query as subquery
            $sql = "SELECT " . implode(', ', $selects) . " FROM ({$baseQuerySql}) AS {$baseTable}";
        } else {
            // Build final SQL
            $sql = "SELECT " . implode(', ', $selects) . " FROM {$baseTable}";
            
            if (!empty($wheres)) {
                $sql .= " WHERE " . implode(' AND ', $wheres);
            }
            
            if (!empty($orderBys)) {
                $sql .= " ORDER BY " . implode(', ', $orderBys);
            }
        }
        
        if ($this->limit) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Get base model columns.
     *
     * @return array
     */
    protected function getBaseColumns(): array
    {
        $fillable = $this->model->getFillable();
        
        if (empty($fillable)) {
            // Try to get from config cache
            $table = $this->model->getTable();
            $cached = config("optimized-queries.column_cache.{$table}");
            if ($cached) {
                return $cached;
            }
            
            // Fallback: get all columns from table
            return $this->getTableColumns($table);
        }

        // Always include primary key and timestamps
        $columns = [$this->model->getKeyName()];
        
        if ($this->model->usesTimestamps()) {
            $columns[] = $this->model->getCreatedAtColumn();
            $columns[] = $this->model->getUpdatedAtColumn();
        }
        
        if (method_exists($this->model, 'getDeletedAtColumn')) {
            $columns[] = $this->model->getDeletedAtColumn();
        }

        return array_unique(array_merge($columns, $fillable));
    }

    /**
     * Get table columns from database.
     *
     * @param string $table
     * @return array
     */
    protected function getTableColumns(string $table): array
    {
        $columns = DB::select("SHOW COLUMNS FROM {$table}");
        return array_column($columns, 'Field');
    }

    /**
     * Build WHERE clauses.
     *
     * @return array
     */
    protected function buildWheres(): array
    {
        $wheres = [];
        $table = $this->model->getTable();

        // Add custom wheres
        foreach ($this->wheres as $where) {
            $column = "{$table}.{$where['column']}";
            
            if ($where['operator'] === 'IN') {
                $placeholders = implode(',', array_fill(0, count($where['value']), '?'));
                $wheres[] = "{$column} IN ({$placeholders})";
            } else {
                $wheres[] = "{$column} {$where['operator']} ?";
            }
        }

        return $wheres;
    }

    /**
     * Build WHERE clauses from base query.
     *
     * @return array
     */
    protected function buildBaseQueryWheres(): array
    {
        $wheres = [];
        $baseQuery = $this->baseQuery->getQuery();
        
        if (!isset($baseQuery->wheres) || empty($baseQuery->wheres)) {
            return $wheres;
        }

        // Simple implementation - convert base query wheres to SQL
        // For complex queries, the base query will be used as subquery
        $table = $this->model->getTable();
        
        foreach ($baseQuery->wheres as $where) {
            if (isset($where['column'])) {
                $column = strpos($where['column'], '.') !== false 
                    ? $where['column'] 
                    : "{$table}.{$where['column']}";
                
                $operator = $where['operator'] ?? '=';
                
                if ($operator === 'In' && isset($where['values'])) {
                    $placeholders = implode(',', array_fill(0, count($where['values']), '?'));
                    $wheres[] = "{$column} IN ({$placeholders})";
                } else {
                    $wheres[] = "{$column} {$operator} ?";
                }
            }
        }

        return $wheres;
    }

    /**
     * Build ORDER BY clauses.
     *
     * @return array
     */
    protected function buildOrderBys(): array
    {
        $orderBys = [];
        $table = $this->model->getTable();

        foreach ($this->orderBys as $orderBy) {
            $orderBys[] = "{$table}.{$orderBy['column']} {$orderBy['direction']}";
        }

        return $orderBys;
    }

    /**
     * Build search WHERE clauses.
     *
     * @return array
     */
    protected function buildSearchWheres(): array
    {
        $wheres = [];
        $table = $this->model->getTable();
        $driver = DB::connection()->getDriverName();

        // Build main table search
        if (!empty($this->searchTerms) && !empty($this->searchFields)) {
            foreach ($this->searchTerms as $term) {
                $conditions = [];
                
                foreach ($this->searchFields as $field) {
                    $column = "{$table}.{$field}";
                    
                    if ($this->useFullTextSearch && $this->supportsFullTextSearch($driver)) {
                        // Use full-text search if supported
                        $conditions[] = "MATCH({$column}) AGAINST(? IN BOOLEAN MODE)";
                    } else {
                        // Use LIKE search (works everywhere)
                        $conditions[] = "{$column} LIKE ?";
                    }
                }
                
                if (!empty($conditions)) {
                    $wheres[] = '(' . implode(' OR ', $conditions) . ')';
                }
            }
        }

        // Build relation search (requires subqueries)
        foreach ($this->searchRelations as $searchRelation) {
            $relation = $this->model->{$searchRelation['relation']}();
            $relatedModel = $relation->getRelated();
            $relatedTable = $relatedModel->getTable();
            $foreignKey = $this->getRelationForeignKey($relation);
            $localKey = $this->getRelationLocalKey($relation);
            
            $conditions = [];
            foreach ($searchRelation['fields'] as $field) {
                $column = "{$relatedTable}.{$field}";
                if ($this->useFullTextSearch && $this->supportsFullTextSearch($driver)) {
                    $conditions[] = "MATCH({$column}) AGAINST(? IN BOOLEAN MODE)";
                } else {
                    $conditions[] = "{$column} LIKE ?";
                }
            }
            
            if (!empty($conditions)) {
                $baseTable = $this->model->getTable();
                $baseKey = $this->model->getKeyName();
                $wheres[] = "{$baseTable}.{$baseKey} IN (
                    SELECT {$relatedTable}.{$foreignKey} 
                    FROM {$relatedTable} 
                    WHERE " . implode(' OR ', $conditions) . "
                )";
            }
        }

        return $wheres;
    }

    /**
     * Get relation foreign key.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @return string
     */
    protected function getRelationForeignKey(\Illuminate\Database\Eloquent\Relations\Relation $relation): string
    {
        if (method_exists($relation, 'getForeignKeyName')) {
            return $relation->getForeignKeyName();
        }
        if (method_exists($relation, 'getForeignKey')) {
            return $relation->getForeignKey();
        }
        return $this->model->getForeignKey();
    }

    /**
     * Get relation local key.
     *
     * @param \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @return string
     */
    protected function getRelationLocalKey(\Illuminate\Database\Eloquent\Relations\Relation $relation): string
    {
        if (method_exists($relation, 'getOwnerKeyName')) {
            return $relation->getOwnerKeyName();
        }
        if (method_exists($relation, 'getLocalKeyName')) {
            return $relation->getLocalKeyName();
        }
        return $this->model->getKeyName();
    }

    /**
     * Check if database driver supports full-text search.
     *
     * @param string $driver
     * @return bool
     */
    protected function supportsFullTextSearch(string $driver): bool
    {
        return in_array($driver, ['mysql', 'pgsql']);
    }

    /**
     * Execute query and return results.
     *
     * @param string $sql
     * @return array
     */
    protected function executeQuery(string $sql): array
    {
        if (config('optimized-queries.enable_query_logging', false)) {
            Log::info('Optimized Query', ['sql' => $sql, 'bindings' => $this->getBindings()]);
        }

        // Merge bindings from base query and custom wheres
        $bindings = array_merge(
            $this->baseQuery->getBindings(),
            $this->getCustomBindings()
        );

        $results = DB::select($sql, $bindings);
        
        return array_map(function ($row) {
            return (array) $row;
        }, $results);
    }

    /**
     * Get bindings from custom where clauses.
     *
     * @return array
     */
    protected function getCustomBindings(): array
    {
        $bindings = [];

        foreach ($this->wheres as $where) {
            if ($where['operator'] === 'IN' && is_array($where['value'])) {
                $bindings = array_merge($bindings, $where['value']);
            } else {
                $bindings[] = $where['value'];
            }
        }

        // Add search bindings
        foreach ($this->searchTerms as $term) {
            if (!$this->useFullTextSearch) {
                $searchPattern = '%' . $term . '%';
                // Add binding for each search field
                foreach ($this->searchFields as $field) {
                    $bindings[] = $searchPattern;
                }
            } else {
                // Full-text search binding
                $bindings[] = $term;
            }
        }

        // Add relation search bindings
        foreach ($this->searchRelations as $searchRelation) {
            $searchPattern = '%' . $searchRelation['term'] . '%';
            foreach ($searchRelation['fields'] as $field) {
                if (!$this->useFullTextSearch) {
                    $bindings[] = $searchPattern;
                } else {
                    $bindings[] = $searchRelation['term'];
                }
            }
        }

        return $bindings;
    }

    /**
     * Format results based on requested format.
     *
     * @param array $results
     * @param string $format
     * @return Collection
     */
    protected function formatResults(array $results, string $format): Collection
    {
        $formatted = [];

        foreach ($results as $result) {
            // Decode JSON columns
            foreach ($this->relations as $relation) {
                $relationName = $relation['name'];
                if (isset($result[$relationName])) {
                    $decoded = json_decode($result[$relationName], true);
                    $result[$relationName] = $decoded ?? ($relation['type'] === 'collection' ? [] : null);
                }
            }

            if ($format === 'eloquent') {
                $formatted[] = $this->model->newInstance($result);
            } else {
                $formatted[] = $result;
            }
        }

        return collect($formatted);
    }

    /**
     * Get cache key for this query.
     *
     * @return string
     */
    protected function getCacheKey(): string
    {
        if ($this->cacheKey) {
            return config('optimized-queries.cache_prefix', 'optimized_queries:') . $this->cacheKey;
        }

        $key = md5(serialize([
            $this->toSql(),
            $this->getBindings(),
            $this->relations,
            $this->counts,
        ]));

        return config('optimized-queries.cache_prefix', 'optimized_queries:') . $key;
    }

    /**
     * Get base query count.
     *
     * @return int
     */
    protected function getBaseQueryCount(): int
    {
        return (clone $this->baseQuery)->count();
    }
}

