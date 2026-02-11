<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Shammaa\LaravelOptimizedQueries\Services\RelationBuilder;
use Shammaa\LaravelOptimizedQueries\Services\PerformanceMonitor;
use Shammaa\LaravelOptimizedQueries\Support\TranslationResolver;

class OptimizedQueryBuilder
{
    protected Builder $baseQuery;
    protected Model $model;
    protected array $relations = [];
    protected array $counts = [];
    protected array $aggregates = [];
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
    protected bool $enablePerformanceMonitoring = false;
    protected ?PerformanceMonitor $performanceMonitor = null;
    protected array $selectedColumns = [];
    protected ?string $requestedFormat = null;
    protected array $cacheTags = [];
    protected static array $requestCache = [];
    protected bool $hasTranslations = false;
    protected ?string $translationLocale = null;
    protected bool $safeMode = true;
    protected array $selectRaw = [];
    protected array $groupBys = [];
    protected array $havings = [];
    protected int $maxRelationsPerQuery = 0;
    protected int $queryTimeout = 0;

    public function __construct(Builder $baseQuery)
    {
        $this->baseQuery = $baseQuery;
        $this->model = $baseQuery->getModel();
        $this->enableCache = config('optimized-queries.enable_cache', true);
        $this->enablePerformanceMonitoring = config('optimized-queries.enable_performance_monitoring', false)
            && config('app.debug', false);
        $this->hasTranslations = TranslationResolver::modelHasTranslations($this->model);
        $this->translationLocale = app()->getLocale();

        if ($this->enablePerformanceMonitoring) {
            $this->performanceMonitor = new PerformanceMonitor();
            $this->performanceMonitor->start();
        }

        $this->safeMode = config('optimized-queries.safe_mode', true);
        $this->maxRelationsPerQuery = (int) config('optimized-queries.max_relations_per_query', 0);
        $this->queryTimeout = (int) config('optimized-queries.query_timeout', 0);
    }

    // =========================================================================
    // DYNAMIC SCOPE FORWARDING
    // =========================================================================

    public function __call(string $method, array $parameters): self
    {
        // Validate: only forward to methods that exist on the Eloquent Builder
        if (!method_exists($this->baseQuery, $method) && !method_exists($this->baseQuery->getQuery(), $method)) {
            // Check if it's a scope method (scope prefix)
            $scopeMethod = 'scope' . ucfirst($method);
            if (!method_exists($this->model, $scopeMethod)) {
                throw new \BadMethodCallException(
                    "Method [{$method}] does not exist on OptimizedQueryBuilder or Eloquent Builder. " .
                    "Check for typos or use tapQuery() to access the underlying builder directly."
                );
            }
        }

        $this->baseQuery->$method(...$parameters);
        return $this;
    }

    // =========================================================================
    // RELATION LOADING
    // =========================================================================

    /**
     * Smart relation loader - auto-detects relation type.
     *
     * Supports:
     * - ->with('author')
     * - ->with(['author', 'category', 'comments'])
     * - ->with('author', ['id', 'name'])
     * - ->with(['author' => ['id', 'name'], 'category', 'comments'])
     * - ->with(['author' => fn($q) => $q->where('active', true)])
     * - ->with(['comments' => ['columns' => ['id','body'], 'callback' => fn($q) => $q->latest()]])
     */
    public function with(string|array $relations, array|string|null $columns = null, ?\Closure $callback = null): self
    {
        if (is_array($relations)) {
            foreach ($relations as $key => $value) {
                if (is_string($key)) {
                    if ($value instanceof \Closure) {
                        $this->loadRelation($key, ['*'], $value);
                    } elseif (is_array($value) && isset($value['columns'])) {
                        $this->loadRelation($key, $value['columns'], $value['callback'] ?? null);
                    } elseif (is_array($value)) {
                        $this->loadRelation($key, $value, null);
                    }
                } elseif (is_string($value)) {
                    $this->loadRelation($value, ['*'], null);
                }
            }
            return $this;
        }

        return $this->loadRelation($relations, $columns ?? ['*'], $callback);
    }

    public function withRelation(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = ['type' => 'relation', 'name' => $relation, 'columns' => is_string($columns) ? [$columns] : $columns, 'callback' => $callback];
        return $this;
    }

    public function withCollection(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = ['type' => 'collection', 'name' => $relation, 'columns' => is_string($columns) ? [$columns] : $columns, 'callback' => $callback];
        return $this;
    }

    public function withMany(string $relation, array|string|null $columns = null, ?\Closure $callback = null): self
    {
        return $this->withCollection($relation, $columns ?? ['*'], $callback);
    }

    public function withManyToMany(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = ['type' => 'many_to_many', 'name' => $relation, 'columns' => is_string($columns) ? [$columns] : $columns, 'callback' => $callback];
        return $this;
    }

    public function withPolymorphic(string $relation, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = ['type' => 'polymorphic', 'name' => $relation, 'columns' => is_string($columns) ? [$columns] : $columns, 'callback' => $callback];
        return $this;
    }

    public function withNested(string $relationPath, array|string $columns = ['*'], ?\Closure $callback = null): self
    {
        $this->relations[] = ['type' => 'nested', 'name' => $relationPath, 'columns' => is_string($columns) ? [$columns] : $columns, 'callback' => $callback];
        return $this;
    }

    public function withColumns(string $relation, array $columns, ?\Closure $callback = null): self
    {
        return $this->withRelation($relation, $columns, $callback);
    }

    public function withManyColumns(string $relation, array $columns, ?\Closure $callback = null): self
    {
        return $this->withCollection($relation, $columns, $callback);
    }

    public function withCount(string $relation, ?\Closure $callback = null): self
    {
        $this->counts[] = ['name' => $relation, 'callback' => $callback];
        return $this;
    }

    /**
     * Add aggregate subquery for a relation (sum, avg, min, max).
     */
    public function withAggregate(string $relation, string $column, string $function = 'sum'): self
    {
        $this->aggregates[] = ['relation' => $relation, 'column' => $column, 'function' => strtoupper($function)];
        return $this;
    }

    public function withSum(string $relation, string $column): self { return $this->withAggregate($relation, $column, 'SUM'); }
    public function withAvg(string $relation, string $column): self { return $this->withAggregate($relation, $column, 'AVG'); }
    public function withMin(string $relation, string $column): self { return $this->withAggregate($relation, $column, 'MIN'); }
    public function withMax(string $relation, string $column): self { return $this->withAggregate($relation, $column, 'MAX'); }

    // =========================================================================
    // QUERY BUILDING - WHERE / ORDER / GROUP / LIMIT
    // =========================================================================

    public function select(array|string $columns): self
    {
        $this->selectedColumns = is_string($columns) ? [$columns] : $columns;
        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->selectRaw[] = ['expression' => $expression, 'bindings' => $bindings];
        return $this;
    }

    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        $this->baseQuery->where($column, $operator, $value);
        return $this;
    }

    public function whereIn(string $column, array $values): self { $this->baseQuery->whereIn($column, $values); return $this; }
    public function whereNotIn(string $column, array $values): self { $this->baseQuery->whereNotIn($column, $values); return $this; }
    public function whereNull(string $column): self { $this->baseQuery->whereNull($column); return $this; }
    public function whereNotNull(string $column): self { $this->baseQuery->whereNotNull($column); return $this; }
    public function whereBetween(string $column, array $values): self { $this->baseQuery->whereBetween($column, $values); return $this; }
    public function whereNotBetween(string $column, array $values): self { $this->baseQuery->whereNotBetween($column, $values); return $this; }
    public function whereDate(string $column, string $operator, ?string $value = null): self { $this->baseQuery->whereDate($column, $operator, $value); return $this; }
    public function whereYear(string $column, string $operator, ?string $value = null): self { $this->baseQuery->whereYear($column, $operator, $value); return $this; }
    public function whereMonth(string $column, string $operator, ?string $value = null): self { $this->baseQuery->whereMonth($column, $operator, $value); return $this; }
    public function whereDay(string $column, string $operator, ?string $value = null): self { $this->baseQuery->whereDay($column, $operator, $value); return $this; }
    public function whereTime(string $column, string $operator, ?string $value = null): self { $this->baseQuery->whereTime($column, $operator, $value); return $this; }
    public function whereColumn(string $first, string $operator, ?string $second = null): self { $this->baseQuery->whereColumn($first, $operator, $second); return $this; }
    public function whereRaw(string $expression, array $bindings = []): self { $this->baseQuery->whereRaw($expression, $bindings); return $this; }
    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): self { $this->baseQuery->orWhere($column, $operator, $value); return $this; }
    public function orWhereIn(string $column, array $values): self { $this->baseQuery->orWhereIn($column, $values); return $this; }
    public function orWhereNull(string $column): self { $this->baseQuery->orWhereNull($column); return $this; }
    public function orWhereNotNull(string $column): self { $this->baseQuery->orWhereNotNull($column); return $this; }

    public function whereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        $this->baseQuery->whereHas($relation, $callback, $operator, $count);
        return $this;
    }

    public function whereDoesntHave(string $relation, ?\Closure $callback = null): self { $this->baseQuery->whereDoesntHave($relation, $callback); return $this; }
    public function orWhereHas(string $relation, ?\Closure $callback = null, string $operator = '>=', int $count = 1): self { $this->baseQuery->orWhereHas($relation, $callback, $operator, $count); return $this; }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBys[] = ['column' => $column, 'direction' => strtolower($direction)];
        return $this;
    }

    public function orderByDesc(string $column): self { return $this->orderBy($column, 'desc'); }
    public function latest(string $column = 'created_at'): self { return $this->orderBy($column, 'desc'); }
    public function oldest(string $column = 'created_at'): self { return $this->orderBy($column, 'asc'); }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->baseQuery->orderByRaw($sql, $bindings);
        return $this;
    }

    public function inRandomOrder(): self { $this->baseQuery->inRandomOrder(); return $this; }

    public function groupBy(string ...$columns): self { $this->groupBys = array_merge($this->groupBys, $columns); return $this; }
    public function having(string $column, string $operator, mixed $value): self { $this->havings[] = compact('column', 'operator', 'value'); return $this; }
    public function havingRaw(string $sql, array $bindings = []): self { $this->baseQuery->havingRaw($sql, $bindings); return $this; }

    public function limit(int $limit): self
    {
        $this->limit = min($limit, config('optimized-queries.max_limit', 1000));
        return $this;
    }

    public function take(int $count): self { return $this->limit($count); }
    public function offset(int $offset): self { $this->offset = $offset; return $this; }
    public function skip(int $count): self { return $this->offset($count); }

    public function distinct(): self { $this->baseQuery->distinct(); return $this; }

    /**
     * Conditional method chaining.
     */
    public function when(mixed $condition, \Closure $callback, ?\Closure $default = null): self
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default) {
            $default($this, $condition);
        }
        return $this;
    }

    /**
     * Opposite of when().
     */
    public function unless(mixed $condition, \Closure $callback, ?\Closure $default = null): self
    {
        return $this->when(!$condition, $callback, $default);
    }

    /**
     * Tap into the builder without modifying it.
     */
    public function tap(\Closure $callback): self
    {
        $callback($this);
        return $this;
    }

    /**
     * Access the underlying Eloquent builder directly.
     */
    public function tapQuery(\Closure $callback): self
    {
        $callback($this->baseQuery);
        return $this;
    }

    // =========================================================================
    // TRANSLATION SUPPORT
    // =========================================================================

    public function locale(string $locale): self { $this->translationLocale = $locale; return $this; }
    public function hasTranslationSupport(): bool { return $this->hasTranslations; }

    public function withTranslation(?string $locale = null): self
    {
        if (!$this->hasTranslations) return $this;
        $this->translationLocale = $locale ?? $this->translationLocale;
        if (method_exists($this->model, 'scopeWithTranslation')) {
            $this->baseQuery->withTranslation($this->translationLocale);
        }
        return $this;
    }

    public function whereTranslation(string $field, string $operator, mixed $value = null, ?string $locale = null): self
    {
        if (!$this->hasTranslations) return $this->where($field, $operator, $value);
        if ($value === null) { $value = $operator; $operator = '='; }
        $locale = $locale ?? $this->translationLocale;

        if (method_exists($this->model, 'scopeWhereTranslation')) {
            $this->baseQuery->whereTranslation($field, $operator, $value, $locale);
        } else {
            $this->baseQuery->whereHas('translations', fn($q) => $q->where('locale', $locale)->where($field, $operator, $value));
        }
        return $this;
    }

    public function whereTranslatedSlug(string $slug, ?string $locale = null, string $slugColumn = 'slug'): self
    {
        if (!$this->hasTranslations) return $this->where($slugColumn, $slug);
        $translatableFields = TranslationResolver::getTranslatableFields($this->model);
        if (!in_array($slugColumn, $translatableFields)) return $this->where($slugColumn, $slug);

        if (method_exists($this->model, 'scopeWhereTranslatedSlug')) {
            $this->baseQuery->whereTranslatedSlug($slug, $locale, $slugColumn);
        } else {
            $this->baseQuery->whereHas('translations', function ($q) use ($slug, $locale, $slugColumn) {
                $q->where($slugColumn, $slug);
                if ($locale) $q->where('locale', $locale);
            });
        }
        return $this;
    }

    public function orderByTranslation(string $field, string $direction = 'asc', ?string $locale = null): self
    {
        if (!$this->hasTranslations) return $this->orderBy($field, $direction);
        $locale = $locale ?? $this->translationLocale;
        if (method_exists($this->model, 'scopeOrderByTranslation')) $this->baseQuery->orderByTranslation($field, $direction, $locale);
        return $this;
    }

    public function emptyTranslation(?string $locale = null): self
    {
        if (!$this->hasTranslations) return $this;
        $locale = $locale ?? $this->translationLocale;
        if (method_exists($this->model, 'scopeEmptyTranslation')) { $this->baseQuery->emptyTranslation($locale); }
        else { $this->baseQuery->whereDoesntHave('translations', fn($q) => $q->where('locale', $locale)); }
        return $this;
    }

    public function searchTranslation(string $term, array|string|null $fields = null, ?string $locale = null): self
    {
        if (!$this->hasTranslations || empty(trim($term))) return $this;
        $locale = $locale ?? $this->translationLocale;
        $fields = is_string($fields) ? [$fields] : ($fields ?? TranslationResolver::getTranslatableFields($this->model));
        $this->baseQuery->whereHas('translations', function ($q) use ($term, $fields, $locale) {
            $q->where('locale', $locale)->where(function ($sq) use ($term, $fields) {
                foreach ($fields as $f) $sq->orWhere($f, 'LIKE', '%' . trim($term) . '%');
            });
        });
        return $this;
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    public function search(string $term, array|string|null $fields = null): self
    {
        if (empty(trim($term))) return $this;
        $this->searchTerms[] = trim($term);
        if ($fields === null) {
            $this->searchFields = array_filter($this->model->getFillable(), fn($f) => !in_array($f, ['id', 'password', 'remember_token', 'created_at', 'updated_at', 'deleted_at']));
        } else {
            $this->searchFields = is_string($fields) ? [$fields] : $fields;
        }
        return $this;
    }

    public function searchIn(string $term, array|string $fields): self { return $this->search($term, $fields); }

    public function searchRelation(string $relation, string $term, array|string $fields): self
    {
        if (empty(trim($term))) return $this;
        $this->searchRelations[] = ['relation' => $relation, 'term' => trim($term), 'fields' => is_string($fields) ? [$fields] : $fields];
        return $this;
    }

    public function useFullTextSearch(bool $enable = true): self { $this->useFullTextSearch = $enable; return $this; }

    // =========================================================================
    // CACHING
    // =========================================================================

    public function cache(?int $ttl = null, array $tags = []): self
    {
        $this->enableCache = true;
        $this->cacheTtl = $ttl ?? config('optimized-queries.default_cache_ttl', 3600);
        $this->cacheTags = !empty($tags) ? $tags : [$this->model->getTable()];
        return $this;
    }

    public function tags(array $tags): self { $this->cacheTags = $tags; return $this; }
    public function withoutCache(): self { $this->enableCache = false; return $this; }
    public function cacheKey(string $key): self { $this->cacheKey = $key; return $this; }
    public static function clearRequestCache(): void { self::$requestCache = []; }

    // =========================================================================
    // OUTPUT FORMAT
    // =========================================================================

    public function asObject(): self { $this->requestedFormat = 'object'; return $this; }
    public function asEloquent(): self { $this->requestedFormat = 'eloquent'; return $this; }
    public function asArray(): self { $this->requestedFormat = 'array'; return $this; }
    public function safeMode(bool $enable = true): self { $this->safeMode = $enable; return $this; }

    // =========================================================================
    // EXECUTION
    // =========================================================================

    public function get(?string $format = null): Collection
    {
        $format = $format ?? $this->requestedFormat ?? config('optimized-queries.default_format', 'array');
        $cacheKey = $this->getCacheKey();

        // 1. Request Cache
        if (isset(self::$requestCache[$cacheKey])) {
            return $this->formatResults(self::$requestCache[$cacheKey], $format);
        }

        // 2. External Cache
        if ($this->enableCache && $this->cacheTtl) {
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                self::$requestCache[$cacheKey] = $cached;
                return $format === 'array' ? collect($cached) : $this->formatResults($cached, $format);
            }
        }

        // 3. Execute
        try {
            $results = $this->executeOptimizedQuery();
        } catch (\Throwable $e) {
            if ($this->safeMode) {
                Log::warning("OptimizedQuery: Falling back to Eloquent. Error: {$e->getMessage()}");
                return $this->executeFallbackQuery();
            }
            throw $e;
        }

        if ($this->performanceMonitor) $this->performanceMonitor->stop();

        $this->storeInCache($cacheKey, $results);
        self::$requestCache[$cacheKey] = $results;

        return $format === 'array' ? collect($results) : $this->formatResults($results, $format);
    }

    public function first(?string $format = null): object|array|null { return $this->limit(1)->get($format)->first(); }

    public function firstOrFail(?string $format = null): object|array
    {
        $result = $this->first($format);
        if (is_null($result)) throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(get_class($this->model));
        return $result;
    }

    public function find(mixed $id, ?string $format = null): object|array|null { return $this->where($this->model->getKeyName(), $id)->first($format); }

    public function findOrFail(mixed $id, ?string $format = null): object|array
    {
        $result = $this->find($id, $format);
        if (is_null($result)) throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(get_class($this->model), [$id]);
        return $result;
    }

    public function findBySlug(string $slug, ?string $locale = null, ?string $format = null): object|array|null { return $this->whereTranslatedSlug($slug, $locale)->first($format); }

    public function findBySlugOrFail(string $slug, ?string $locale = null, ?string $format = null): object|array
    {
        $result = $this->findBySlug($slug, $locale, $format);
        if (is_null($result)) throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(get_class($this->model), [$slug]);
        return $result;
    }

    public function toApi(): array { return $this->get('array')->all(); }
    public function count(): int { return (clone $this->baseQuery)->count(); }
    public function exists(): bool { return (clone $this->baseQuery)->exists(); }
    public function doesntExist(): bool { return !$this->exists(); }

    public function value(string $column) { $r = $this->first('array'); return $r ? ($r[$column] ?? null) : null; }
    public function pluck(string $column, ?string $key = null): Collection { return $this->get('array')->pluck($column, $key); }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null)
    {
        $page = $page ?? (int) request()->input($pageName, 1);
        $total = (clone $this->baseQuery)->count();
        $results = $this->offset(($page - 1) * $perPage)->limit($perPage)->get();
        return new \Illuminate\Pagination\LengthAwarePaginator($results, $total, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null)
    {
        $page = $page ?? (int) request()->input($pageName, 1);
        $results = $this->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $hasMore = $results->count() > $perPage;
        return new \Illuminate\Pagination\Paginator($hasMore ? $results->take($perPage) : $results, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;
        do {
            $results = (clone $this)->offset(($page - 1) * $count)->limit($count)->get();
            if ($results->isEmpty()) break;
            if ($callback($results, $page) === false) return false;
            $page++;
        } while ($results->count() === $count);
        return true;
    }

    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        $builder = $this;
        return LazyCollection::make(function () use ($builder, $chunkSize) {
            $page = 0;
            do {
                $results = (clone $builder)->offset($page * $chunkSize)->limit($chunkSize)->get();
                foreach ($results as $item) yield $item;
                $page++;
            } while ($results->count() === $chunkSize);
        });
    }

    // =========================================================================
    // DEBUG
    // =========================================================================

    public function toSql(): string { return $this->buildOptimizedQuery()->toSql(); }
    public function getBindings(): array { return $this->buildOptimizedQuery()->getBindings(); }
    public function dd(): never { dd(['sql' => $this->toSql(), 'bindings' => $this->getBindings()]); }
    public function dump(): self { dump(['sql' => $this->toSql(), 'bindings' => $this->getBindings()]); return $this; }

    public function getPerformance(): array
    {
        if (!$this->performanceMonitor) return ['query_count' => 1, 'execution_time_ms' => 0, 'total_query_time_ms' => 0, 'queries' => []];
        return $this->performanceMonitor->stop();
    }

    public function comparePerformance(\Closure $traditionalQuery): array
    {
        $bm = new PerformanceMonitor(); $bm->start(); $traditionalQuery(); $before = $bm->stop();
        return PerformanceMonitor::compare($before, $this->getPerformance());
    }

    /**
     * Show performance summary.
     */
    public function showPerformance(): array
    {
        $p = $this->getPerformance();
        return ['summary' => ['queries' => $p['query_count'] . ' query' . ($p['query_count'] !== 1 ? 's' : ''), 'execution_time' => $p['execution_time_ms'] . 'ms', 'total_query_time' => $p['total_query_time_ms'] . 'ms'], 'details' => $p];
    }

    /**
     * Debug: log query.
     */
    public function debug(): self
    {
        $q = $this->buildOptimizedQuery();
        Log::info('Optimized Query Debug', ['sql' => $q->toSql(), 'bindings' => $q->getBindings()]);
        return $this;
    }

    // =========================================================================
    // CORE ENGINE - Uses Laravel Query Builder natively
    // =========================================================================

    /**
     * Build the optimized query using Laravel's query builder.
     * 
     * KEY ARCHITECTURE:
     * Instead of building raw SQL and managing bindings manually,
     * we leverage Eloquent's query builder and add JSON subqueries
     * via addSelect(DB::raw(...)). Laravel manages all bindings correctly.
     */
    protected function buildOptimizedQuery(): Builder
    {
        $query = clone $this->baseQuery;
        $baseTable = $this->model->getTable();
        $baseColumns = $this->getBaseColumns();

        // Reset SELECT, set our columns
        $query->getQuery()->columns = [];

        // Translation join for base model
        $transFields = [];
        $transAlias = 'main_trans';

        if ($this->hasTranslations && $this->translationLocale) {
            $meta = TranslationResolver::getMetadata($this->model);
            if (!empty($meta['fields']) && $meta['table']) {
                $transFields = $meta['fields'];
                $transTable = $meta['table'];
                $foreignKey = $meta['foreign_key'];
                $modelKey = $this->model->getKeyName();
                $locale = $this->translationLocale;

                $query->leftJoin("{$transTable} as {$transAlias}", function ($join) use ($baseTable, $modelKey, $transAlias, $foreignKey, $locale) {
                    $join->on("{$baseTable}.{$modelKey}", '=', "{$transAlias}.{$foreignKey}")
                         ->where("{$transAlias}.locale", '=', $locale);
                });
            }
        }

        // Add base columns
        foreach ($baseColumns as $col) {
            if (in_array($col, $transFields)) {
                $query->addSelect(DB::raw("{$transAlias}.{$col} AS `{$col}`"));
            } else {
                $query->addSelect("{$baseTable}.{$col}");
            }
        }

        // Add raw selects
        foreach ($this->selectRaw as $raw) {
            $query->selectRaw($raw['expression'], $raw['bindings']);
        }

        // Build relation JSON subqueries
        $relationBuilder = new RelationBuilder($this->model, $query);
        $relationBuilder->setLocale($this->translationLocale);

        foreach ($this->relations as $relation) {
            $jsonSql = $relationBuilder->buildRelationJson($relation);
            if ($jsonSql) {
                $query->addSelect(DB::raw($jsonSql));
            }
        }

        // Add relation bindings to 'select' type so Laravel orders them correctly
        foreach ($relationBuilder->getBindings() as $binding) {
            $query->getQuery()->addBinding($binding, 'select');
        }

        // Add counts
        foreach ($this->counts as $count) {
            $countSql = $relationBuilder->buildCount($count);
            if ($countSql) {
                $query->addSelect(DB::raw($countSql));
            }
        }

        // Add aggregates (withSum, withAvg, etc.)
        foreach ($this->aggregates as $agg) {
            $aggSql = $this->buildAggregateSubquery($agg);
            if ($aggSql) {
                $query->addSelect(DB::raw($aggSql));
            }
        }

        // Apply search conditions
        $this->applySearchConditions($query);

        // Apply order by
        foreach ($this->orderBys as $ob) {
            if (in_array($ob['column'], $transFields)) {
                $query->orderBy("{$transAlias}.{$ob['column']}", $ob['direction']);
            } else {
                $query->orderBy($ob['column'], $ob['direction']);
            }
        }

        // Apply groupBy
        if (!empty($this->groupBys)) $query->groupBy(...$this->groupBys);

        // Apply having
        foreach ($this->havings as $h) $query->having($h['column'], $h['operator'], $h['value']);

        // Apply limit/offset
        if ($this->limit) $query->limit($this->limit);
        if ($this->offset) $query->offset($this->offset);

        return $query;
    }

    /**
     * Execute the optimized query.
     */
    protected function executeOptimizedQuery(): array
    {
        // Smart splitting: if too many relations, split into base + relation queries
        $maxRel = $this->maxRelationsPerQuery;
        if ($maxRel > 0 && count($this->relations) > $maxRel) {
            return $this->executeWithSplitting($maxRel);
        }

        // Complexity warning
        $totalSubqueries = count($this->relations) + count($this->counts) + count($this->aggregates);
        if ($totalSubqueries > 8 && config('app.debug', false)) {
            Log::warning("OptimizedQuery: High complexity query with {$totalSubqueries} subqueries on " . get_class($this->model) . ". Consider splitting or using max_relations_per_query config.");
        }

        $query = $this->buildOptimizedQuery();

        if (config('optimized-queries.enable_query_logging', false)) {
            Log::info('OptimizedQuery SQL', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);
        }

        // Execute with optional timeout
        if ($this->queryTimeout > 0) {
            return $this->executeWithTimeout($query, $this->queryTimeout);
        }

        // Execute raw to bypass model hydration (fastest)
        $results = DB::select($query->toSql(), $query->getBindings());

        return array_map(fn($row) => (array) $row, $results);
    }

    /**
     * Execute query with database-level timeout protection.
     */
    protected function executeWithTimeout(Builder $query, int $seconds): array
    {
        $driver = DB::connection()->getDriverName();

        try {
            // Set statement timeout based on driver
            match ($driver) {
                'mysql', 'mariadb' => DB::statement("SET SESSION MAX_EXECUTION_TIME = " . ($seconds * 1000)),
                'pgsql' => DB::statement("SET LOCAL statement_timeout = '" . ($seconds * 1000) . "ms'"),
                default => null,
            };

            $results = DB::select($query->toSql(), $query->getBindings());

            return array_map(fn($row) => (array) $row, $results);
        } catch (\Throwable $e) {
            Log::warning("OptimizedQuery: Query timeout after {$seconds}s on " . get_class($this->model) . ": {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Split a complex query into multiple simpler queries.
     * First query gets base data + first batch of relations.
     * Subsequent queries add remaining relations by ID matching.
     */
    protected function executeWithSplitting(int $maxPerQuery): array
    {
        $allRelations = $this->relations;
        $batches = array_chunk($allRelations, $maxPerQuery);

        // First batch: execute with base query conditions
        $this->relations = $batches[0];
        $query = $this->buildOptimizedQuery();
        $results = DB::select($query->toSql(), $query->getBindings());
        $results = array_map(fn($row) => (array) $row, $results);

        if (empty($results) || count($batches) <= 1) {
            $this->relations = $allRelations;
            return $results;
        }

        // Get IDs from first batch results
        $primaryKey = $this->model->getKeyName();
        $ids = array_column($results, $primaryKey);
        $indexedResults = [];
        foreach ($results as $row) {
            $indexedResults[$row[$primaryKey]] = $row;
        }

        // Subsequent batches: query by IDs only
        for ($i = 1; $i < count($batches); $i++) {
            $this->relations = $batches[$i];
            $batchQuery = clone $this->baseQuery;
            $batchQuery->whereIn($this->model->getTable() . '.' . $primaryKey, $ids);
            $origBase = $this->baseQuery;
            $this->baseQuery = $batchQuery;

            try {
                $batchBuilt = $this->buildOptimizedQuery();
                $batchResults = DB::select($batchBuilt->toSql(), $batchBuilt->getBindings());

                // Merge batch relation data into main results
                foreach ($batchResults as $batchRow) {
                    $batchRow = (array) $batchRow;
                    $id = $batchRow[$primaryKey] ?? null;
                    if ($id && isset($indexedResults[$id])) {
                        foreach ($batches[$i] as $rel) {
                            $alias = str_replace('.', '_', $rel['name']);
                            if (isset($batchRow[$alias])) {
                                $indexedResults[$id][$alias] = $batchRow[$alias];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("OptimizedQuery: Batch {$i} failed: {$e->getMessage()}");
            }

            $this->baseQuery = $origBase;
        }

        $this->relations = $allRelations;
        return array_values($indexedResults);
    }

    /**
     * Fallback to standard Eloquent when optimized query fails.
     */
    protected function executeFallbackQuery(): Collection
    {
        $fallback = clone $this->baseQuery;
        $rels = [];
        foreach ($this->relations as $r) {
            $cb = $r['callback'] ?? null;
            if ($cb) { $rels[$r['name']] = $cb; } else { $rels[] = $r['name']; }
        }
        if (!empty($rels)) $fallback->with($rels);
        foreach ($this->counts as $c) $fallback->withCount($c['name']);
        foreach ($this->orderBys as $ob) $fallback->orderBy($ob['column'], $ob['direction']);
        if ($this->limit) $fallback->limit($this->limit);
        if ($this->offset) $fallback->offset($this->offset);
        return $fallback->get();
    }

    protected function applySearchConditions(Builder $query): void
    {
        if (!empty($this->searchTerms) && !empty($this->searchFields)) {
            foreach ($this->searchTerms as $term) {
                $query->where(function ($q) use ($term) {
                    foreach ($this->searchFields as $field) {
                        if ($this->useFullTextSearch) {
                            $q->orWhereRaw("MATCH({$field}) AGAINST(? IN BOOLEAN MODE)", [$term]);
                        } else {
                            $q->orWhere($field, 'LIKE', '%' . $term . '%');
                        }
                    }
                });
            }
        }

        foreach ($this->searchRelations as $sr) {
            $query->whereHas($sr['relation'], function ($q) use ($sr) {
                $q->where(function ($sq) use ($sr) {
                    foreach ($sr['fields'] as $f) {
                        $this->useFullTextSearch
                            ? $sq->orWhereRaw("MATCH({$f}) AGAINST(? IN BOOLEAN MODE)", [$sr['term']])
                            : $sq->orWhere($f, 'LIKE', '%' . $sr['term'] . '%');
                    }
                });
            });
        }
    }

    protected function buildAggregateSubquery(array $agg): ?string
    {
        $relationName = $agg['relation'];
        if (!method_exists($this->model, $relationName)) return null;
        $relation = $this->model->{$relationName}();
        if (!$relation instanceof Relation) return null;

        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = method_exists($relation, 'getForeignKeyName') ? $relation->getForeignKeyName() : $this->model->getForeignKey();
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();
        $func = $agg['function'];
        $col = $agg['column'];
        $alias = strtolower($func) . '_' . $relationName . '_' . $col;

        return "(SELECT {$func}({$relatedTable}.{$col}) FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}) AS {$alias}";
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    protected function detectRelationType(string $relationName): string
    {
        if (str_contains($relationName, '.')) return 'nested';
        if (!method_exists($this->model, $relationName)) return 'single';
        try {
            $r = $this->model->{$relationName}();
            if (!$r instanceof Relation) return 'single';
            return match (true) {
                $r instanceof MorphToMany => 'many_to_many',
                $r instanceof BelongsToMany => 'many_to_many',
                $r instanceof HasManyThrough, $r instanceof HasMany, $r instanceof MorphMany => 'collection',
                $r instanceof BelongsTo, $r instanceof HasOne, $r instanceof MorphOne => 'single',
                $r instanceof MorphTo => 'polymorphic',
                default => 'single',
            };
        } catch (\Exception $e) { return 'single'; }
    }

    protected function loadRelation(string $relation, array|string $columns, ?\Closure $callback): self
    {
        $columns = is_string($columns) ? [$columns] : $columns;
        return match ($this->detectRelationType($relation)) {
            'collection' => $this->withCollection($relation, $columns, $callback),
            'many_to_many' => $this->withManyToMany($relation, $columns, $callback),
            'polymorphic' => $this->withPolymorphic($relation, $columns, $callback),
            'nested' => $this->withNested($relation, $columns, $callback),
            default => $this->withRelation($relation, $columns, $callback),
        };
    }

    protected function getBaseColumns(): array
    {
        if (!empty($this->selectedColumns)) return $this->selectedColumns;
        $fillable = $this->model->getFillable();
        if (empty($fillable)) {
            $table = $this->model->getTable();
            $cached = config("optimized-queries.column_cache.{$table}");
            return $cached ? $this->excludeTranslatableColumns($cached) : $this->getTableColumns($table);
        }
        $cols = [$this->model->getKeyName()];
        if ($this->model->usesTimestamps()) { $cols[] = $this->model->getCreatedAtColumn(); $cols[] = $this->model->getUpdatedAtColumn(); }
        if (method_exists($this->model, 'getDeletedAtColumn')) $cols[] = $this->model->getDeletedAtColumn();
        $all = array_unique(array_merge($cols, $fillable));
        return (!$this->translationLocale && $this->hasTranslations) ? $this->excludeTranslatableColumns($all) : $all;
    }

    protected function excludeTranslatableColumns(array $columns): array
    {
        if (!$this->hasTranslations) return $columns;
        $trans = TranslationResolver::getTranslatableFields($this->model);
        return empty($trans) ? $columns : array_values(array_diff($columns, $trans));
    }

    protected function getTableColumns(string $table): array
    {
        $driver = DB::connection()->getDriverName();
        return match ($driver) {
            'mysql', 'mariadb' => array_column(DB::select("SHOW COLUMNS FROM {$table}"), 'Field'),
            'pgsql' => array_column(DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ?", [$table]), 'column_name'),
            'sqlite' => array_column(DB::select("PRAGMA table_info({$table})"), 'name'),
            default => array_column(DB::select("SHOW COLUMNS FROM {$table}"), 'Field'),
        };
    }

    protected function getFromCache(string $key): ?array
    {
        try {
            $store = Cache::getStore();
            $cache = ($store instanceof \Illuminate\Cache\TaggableStore && !empty($this->cacheTags)) ? Cache::tags($this->cacheTags) : Cache::store();
            return $cache->get($key);
        } catch (\Throwable $e) { return null; }
    }

    protected function storeInCache(string $key, array $results): void
    {
        if (!$this->enableCache || !$this->cacheTtl) return;
        try {
            $store = Cache::getStore();
            $cache = ($store instanceof \Illuminate\Cache\TaggableStore && !empty($this->cacheTags)) ? Cache::tags($this->cacheTags) : Cache::store();
            $cache->put($key, $results, $this->cacheTtl);
        } catch (\Throwable $e) { Log::warning("OptimizedQuery cache write failed: {$e->getMessage()}"); }
    }

    protected function getCacheKey(): string
    {
        if ($this->cacheKey) return config('optimized-queries.cache_prefix', 'optimized_queries:') . $this->cacheKey;
        $key = md5(serialize([get_class($this->model), $this->relations, $this->counts, $this->selectedColumns, $this->orderBys, $this->limit, $this->offset, $this->searchTerms, $this->translationLocale, $this->baseQuery->toSql(), $this->baseQuery->getBindings()]));
        return config('optimized-queries.cache_prefix', 'optimized_queries:') . $key;
    }

    protected function formatResults(array $results, string $format): Collection
    {
        $formatted = [];
        $asArray = ($format === 'array');
        foreach ($results as $result) {
            $relationResults = [];
            foreach ($this->relations as $rel) {
                $name = $rel['name'];
                $alias = str_replace('.', '_', $name);
                $key = isset($result[$alias]) ? $alias : (isset($result[$name]) ? $name : null);
                if ($key) {
                    $raw = $result[$key];
                    $decoded = is_string($raw) ? json_decode($raw, $asArray) : $raw;
                    if ($decoded === null) $decoded = in_array($rel['type'], ['collection', 'many_to_many']) ? ($asArray ? [] : []) : null;
                    if ($format === 'eloquent') { $relationResults[$name] = $this->hydrateRelation($rel, $decoded); unset($result[$key]); }
                    else { $result[$name] = $decoded; if ($key !== $name) unset($result[$key]); }
                }
            }
            if ($format === 'eloquent') {
                $m = $this->model->newInstance([], true); $m->setRawAttributes((array)$result, true);
                foreach ($relationResults as $n => $v) $m->setRelation($n, $v);
                $formatted[] = $m;
            } elseif ($format === 'object') { $formatted[] = (object)$result; }
            else { $formatted[] = $result; }
        }
        return collect($formatted);
    }

    protected function hydrateRelation(array $config, mixed $decoded): mixed
    {
        if (empty($decoded)) return in_array($config['type'], ['collection', 'many_to_many']) ? collect() : null;
        $name = explode('.', $config['name'])[0];
        if (!method_exists($this->model, $name)) return $decoded;
        $cls = get_class($this->model->$name()->getRelated());
        if (in_array($config['type'], ['collection', 'many_to_many'])) {
            return collect($decoded)->map(function ($item) use ($cls) { $m = new $cls(); $m->setRawAttributes((array)$item, true); $m->exists = true; return $m; });
        }
        $m = new $cls(); $m->setRawAttributes((array)$decoded, true); $m->exists = true; return $m;
    }
}
