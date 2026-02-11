# Laravel Optimized Queries

<p align="center">
<a href="https://packagist.org/packages/shammaa/laravel-optimized-queries"><img src="https://img.shields.io/packagist/v/shammaa/laravel-optimized-queries.svg" alt="Latest Version"></a>
<a href="https://packagist.org/packages/shammaa/laravel-optimized-queries"><img src="https://img.shields.io/packagist/dt/shammaa/laravel-optimized-queries.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/shammaa/laravel-optimized-queries"><img src="https://img.shields.io/packagist/l/shammaa/laravel-optimized-queries.svg" alt="License"></a>
</p>

**Transform 5-15 Eloquent queries into a single optimized SQL statement** using JSON aggregation. Reduce database calls, improve response time, and keep Eloquent's clean syntax.

```php
// ❌ Traditional: 4 queries
$articles = Article::with(['author', 'category', 'comments'])->get();

// ✅ Optimized: 1 query — same result, 5-10x faster
$articles = Article::optimized()
    ->with('author')
    ->with('category')
    ->with('comments')
    ->get();
```

---

## ✨ Features

- 🚀 **Single SQL Query** — Combines relations into one statement via JSON aggregation
- 🔍 **Auto-Detection** — Automatically detects relation types (BelongsTo, HasMany, BelongsToMany, etc.)
- 📊 **Aggregate Subqueries** — `withSum()`, `withAvg()`, `withMin()`, `withMax()`
- 🔗 **Nested Relations** — `author.profile.company`
- 🎯 **Conditional Chaining** — `when()`, `unless()`, `tap()`, `tapQuery()`
- 🌍 **Translation Support** — Auto-integration with `shammaa/laravel-model-translations`
- 💾 **Built-in Caching** — Request cache + external cache with tags
- 🛡️ **Safe Mode** — Falls back to standard Eloquent if query fails
- 📖 **Pagination** — `paginate()` and `simplePaginate()`
- 📦 **Chunking** — `chunk()` and `lazy()` for large datasets
- 🔧 **Debugging** — `toSql()`, `dump()`, `debug()`, `showPerformance()`
- 🗄️ **Multi-Database** — MySQL, MariaDB, PostgreSQL, SQLite

---

## 📦 Installation

```bash
composer require shammaa/laravel-optimized-queries
```

Publish configuration (optional):

```bash
php artisan vendor:publish --provider="Shammaa\LaravelOptimizedQueries\LaravelOptimizedQueriesServiceProvider"
```

---

## 🚀 Quick Start

### 1. Add the Trait

```php
use Shammaa\LaravelOptimizedQueries\Traits\HasOptimizedQueries;

class Article extends Model
{
    use HasOptimizedQueries;

    protected $fillable = ['title', 'slug', 'content', 'user_id', 'category_id'];
}
```

### 2. Write Queries

```php
// Basic — loads author + category + comments in ONE query
$articles = Article::optimized()
    ->with('author')
    ->with('category')
    ->with('comments')
    ->where('published', true)
    ->latest()
    ->limit(20)
    ->get();
```

**That's it!** The package auto-detects that `author` is BelongsTo, `category` is BelongsTo, and `comments` is HasMany.

---

## 📖 Usage Guide

### Loading Relations

```php
// Single relation
->with('author')

// Select specific columns
->with('author', ['id', 'name', 'avatar'])

// Multiple relations
->with(['author', 'category', 'comments'])

// Multiple with columns
->with([
    'author' => ['id', 'name'],
    'category' => ['id', 'name', 'slug'],
    'comments'
])

// With callback filter
->with(['comments' => fn($q) => $q->where('approved', true)->latest()])

// With columns + callback
->with(['comments' => [
    'columns' => ['id', 'body', 'created_at'],
    'callback' => fn($q) => $q->latest()->take(5)
]])
```

### Counting Relations

```php
$articles = Article::optimized()
    ->with('author')
    ->withCount('comments')
    ->withCount('likes')
    ->get();

// Result: each article has 'comments_count' and 'likes_count'
```

### Aggregate Subqueries

```php
$products = Product::optimized()
    ->with('category')
    ->withSum('orderItems', 'quantity')    // sum_orderItems_quantity
    ->withAvg('reviews', 'rating')         // avg_reviews_rating
    ->withMin('variants', 'price')         // min_variants_price
    ->withMax('variants', 'price')         // max_variants_price
    ->get();
```

### WHERE Conditions

```php
->where('published', true)
->where('views', '>', 100)
->whereIn('category_id', [1, 2, 3])
->whereNotNull('published_at')
->whereBetween('price', [10, 50])
->whereDate('created_at', '>', '2025-01-01')
->whereHas('comments', fn($q) => $q->where('approved', true))
->whereDoesntHave('reports')
```

### Conditional Chaining

Build queries dynamically based on conditions:

```php
$articles = Article::optimized()
    ->with('author')
    ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
    ->when($request->search, fn($q) => $q->search($request->search, ['title', 'content']))
    ->unless($user->isAdmin(), fn($q) => $q->where('published', true))
    ->latest()
    ->paginate(20);
```

### Scoped Queries

Create optimized queries with pre-applied conditions:

```php
$activeProducts = Product::scopedOptimized(
    fn($q) => $q->where('active', true)->where('stock', '>', 0)
)
    ->with('category')
    ->with('images')
    ->latest()
    ->get();
```

### Nested Relations

```php
$articles = Article::optimized()
    ->with('author.profile')       // Nested: author -> profile
    ->with('category')
    ->get();
```

### Searching

```php
// Search in model columns
$results = Article::optimized()
    ->with('author')
    ->search('laravel', ['title', 'content'])
    ->get();

// Search in related model
$results = Article::optimized()
    ->with('author')
    ->searchRelation('comments', 'great', ['body'])
    ->get();
```

### Ordering

```php
->orderBy('created_at', 'desc')
->orderByDesc('views')
->latest()                          // = orderByDesc('created_at')
->oldest()                          // = orderBy('created_at')
->inRandomOrder()
```

---

## 📄 Pagination & Large Datasets

### Pagination

```php
// Standard pagination (with total count)
$articles = Article::optimized()
    ->with('author')
    ->paginate(20);

// Simple pagination (faster — no count query)
$articles = Article::optimized()
    ->with('author')
    ->simplePaginate(20);
```

### Chunking

```php
// Process large datasets in chunks
Article::optimized()
    ->with('author')
    ->where('published', true)
    ->chunk(500, function ($articles) {
        foreach ($articles as $article) {
            // process...
        }
    });
```

### Lazy Collections

```php
// Memory-efficient iteration
Article::optimized()
    ->with('author')
    ->lazy(1000)
    ->each(function ($article) {
        // process one at a time...
    });
```

---

## 🌍 Translation Support

Automatic integration with `shammaa/laravel-model-translations`:

```php
// Load with specific locale
$articles = Article::optimized()
    ->with('category')
    ->locale('ar')
    ->get();

// Search in translations
$articles = Article::optimized()
    ->searchTranslation('لارافيل', ['title', 'content'], 'ar')
    ->get();

// Filter by translation
$articles = Article::optimized()
    ->whereTranslation('title', 'LIKE', '%Laravel%', 'en')
    ->get();

// Order by translation field
$articles = Article::optimized()
    ->orderByTranslation('title', 'asc', 'ar')
    ->get();

// Find by translated slug
$article = Article::optimized()
    ->with('author')
    ->whereTranslatedSlug('my-article-slug', 'en')
    ->first();
```

---

## 💾 Caching

```php
// Cache for 1 hour
$articles = Article::optimized()
    ->with('author')
    ->cache(3600)
    ->get();

// Cache with tags (Redis/Memcached)
$articles = Article::optimized()
    ->with('author')
    ->cache(3600, ['articles', 'homepage'])
    ->get();

// Custom cache key
$articles = Article::optimized()
    ->with('author')
    ->cacheKey('homepage_articles')
    ->cache(7200)
    ->get();

// Bypass cache
$articles = Article::optimized()
    ->with('author')
    ->withoutCache()
    ->get();
```

Cache auto-clears when models are saved or deleted.

---

## 🔧 Output Formats

```php
// Arrays (default — fastest)
$articles = Article::optimized()->with('author')->get();

// Eloquent models
$articles = Article::optimized()->with('author')->asEloquent()->get();

// stdClass objects
$articles = Article::optimized()->with('author')->asObject()->get();

// Explicit format
$articles = Article::optimized()->with('author')->get('eloquent');
```

---

## 🔧 Retrieval Methods

```php
// Get all matching records
->get()

// Get first record
->first()

// Get first or throw 404
->firstOrFail()

// Find by ID
->find(1)

// Find by ID or throw 404
->findOrFail(1)

// Find by slug (with translations)
->findBySlug('my-article')
->findBySlugOrFail('my-article')

// Count
->count()

// Check existence
->exists()
->doesntExist()

// Get single column value
->value('title')

// Pluck column
->pluck('title')
->pluck('title', 'id')

// API-ready response
->toApi()
```

---

## 🕵️ Debugging

```php
// See the generated SQL
$sql = Article::optimized()->with('author')->toSql();

// Dump SQL + bindings
Article::optimized()->with('author')->dump();

// Die & dump
Article::optimized()->with('author')->dd();

// Log to Laravel log
Article::optimized()->with('author')->debug()->get();

// Performance monitoring
$articles = Article::optimized()
    ->with('author')
    ->with('comments')
    ->get();

// Show performance after get()
Article::optimized()->with('author')->showPerformance();
```

---

## 🔀 Using the Facade

```php
use Shammaa\LaravelOptimizedQueries\Facades\OptimizedQuery;

// From model class
$articles = OptimizedQuery::from(Article::class)
    ->with('author')
    ->get();

// From existing query
$query = Article::where('published', true);
$articles = OptimizedQuery::query($query)
    ->with('author')
    ->get();
```

---

## ⚙️ Configuration

```php
// config/optimized-queries.php

return [
    'max_limit' => 1000,                    // Safety limit for records
    'default_format' => 'array',             // 'array', 'eloquent', 'object'
    'enable_cache' => env('OPTIMIZED_QUERIES_CACHE', true),
    'default_cache_ttl' => env('OPTIMIZED_QUERIES_CACHE_TTL', 3600),
    'cache_prefix' => 'optimized_queries:',
    'enable_query_logging' => env('OPTIMIZED_QUERIES_LOG', false),
    'enable_performance_monitoring' => env('OPTIMIZED_QUERIES_PERFORMANCE_MONITORING', false),
    'safe_mode' => env('OPTIMIZED_QUERIES_SAFE_MODE', true),
    'max_relations_per_query' => env('OPTIMIZED_QUERIES_MAX_RELATIONS', 0),
    'query_timeout' => env('OPTIMIZED_QUERIES_TIMEOUT', 0),
    'supported_drivers' => ['mysql', 'mariadb', 'pgsql', 'sqlite'],
    'json_function' => 'auto',
];
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OPTIMIZED_QUERIES_CACHE` | `true` | Enable/disable caching |
| `OPTIMIZED_QUERIES_CACHE_TTL` | `3600` | Default cache TTL (seconds) |
| `OPTIMIZED_QUERIES_LOG` | `false` | Log generated SQL queries |
| `OPTIMIZED_QUERIES_PERFORMANCE_MONITORING` | `false` | Enable performance tracking |
| `OPTIMIZED_QUERIES_SAFE_MODE` | `true` | Fallback to Eloquent on failure |
| `OPTIMIZED_QUERIES_MAX_RELATIONS` | `0` | Max relations per query (0 = unlimited) |
| `OPTIMIZED_QUERIES_TIMEOUT` | `0` | Query timeout in seconds (0 = no limit) |

---

## 🛡️ Safe Mode

When `safe_mode` is enabled (default), the package automatically falls back to standard Eloquent if the optimized query encounters any issue:

```php
// If the optimized query fails, it silently falls back to Eloquent
// A warning is logged for debugging
$articles = Article::optimized()
    ->with('author')
    ->get(); // Always returns results, never crashes

// Disable safe mode for debugging
$articles = Article::optimized()
    ->with('author')
    ->safeMode(false)
    ->get(); // Will throw exception on failure
```

---

## ⚡ Performance

### How It Works

Traditional Eloquent eager loading executes **one query per relation**:

```
SELECT * FROM articles WHERE published = 1           -- 1 query
SELECT * FROM users WHERE id IN (1, 2, 3, ...)       -- 2 queries
SELECT * FROM categories WHERE id IN (...)           -- 3 queries
SELECT * FROM comments WHERE article_id IN (...)     -- 4 queries
```

This package combines everything into a **single query** using JSON subqueries:

```sql
SELECT
    articles.*,
    (SELECT JSON_OBJECT('id', users.id, 'name', users.name)
     FROM users WHERE users.id = articles.user_id LIMIT 1) AS author,
    (SELECT CONCAT('[', GROUP_CONCAT(JSON_OBJECT('id', comments.id, 'body', comments.body)), ']')
     FROM comments WHERE comments.article_id = articles.id) AS comments
FROM articles
WHERE articles.published = 1
```

### Real Numbers

| Metric | Traditional | Optimized | Improvement |
|--------|:-----------:|:---------:|:-----------:|
| SQL Queries | 4-15 | **1** | -93% |
| Response Time | 150-400ms | **25-60ms** | 5-10x faster |
| Memory Usage | High | **Lower** | ~40% less |
| Database Connections | Multiple | **Single** | -93% |

### Best Practices

```php
// ✅ Always paginate for lists
->paginate(20)

// ✅ Select only needed columns
->with('author', ['id', 'name'])

// ✅ Use cache for repeated queries
->cache(3600)

// ✅ Limit results
->limit(100)

// ✅ Use chunk() for background processing
->chunk(500, fn($batch) => ...)
```

### 🏗️ Large-Scale Sites (E-Commerce, High Traffic)

For large datasets (100k+ records, 8+ relations), enable **query splitting** and **timeout protection**:

```env
# .env — recommended for large sites
OPTIMIZED_QUERIES_MAX_RELATIONS=5
OPTIMIZED_QUERIES_TIMEOUT=10
OPTIMIZED_QUERIES_SAFE_MODE=true
```

**How query splitting works:**

```php
// You request 10 relations
Product::optimized()
    ->with('category')
    ->with('brand')
    ->with('images')
    ->with('variants')
    ->with('reviews')
    ->with('tags')
    ->with('attributes')
    ->with('seller')
    ->withCount('orders')
    ->withAvg('reviews', 'rating')
    ->get();

// With max_relations_per_query=5, it automatically splits into:
// Query 1: base data + category + brand + images + variants + reviews
// Query 2: tags + attributes + seller (by IDs from query 1)
// Then merges the results — you don't notice any difference!
```

**Why this matters:**
- ❌ Without splitting: 1 massive SQL with 10 subqueries → timeout / memory crash
- ✅ With splitting: 2-3 smaller SQL queries → fast and stable

---

## 🔍 When to Use

### ✅ Perfect For

- **API Endpoints** — Reduce response time
- **Admin Dashboards** — Complex data with multiple relations
- **Mobile Backends** — Low latency matters
- **Listings / DataTables** — 3-10 relations per record
- **Read-Heavy Services** — 90%+ reads
- **High-Traffic Pages** — Every millisecond counts

### ⚠️ Consider Standard Eloquent For

- **Write Operations** — Use standard Eloquent for creates/updates
- **Model Events** — Default format is arrays (no model events)
- **Deep Nesting** — More than 3 levels of nested relations

---

## 🤝 Real-World Example: Homepage

```php
class HomepageController extends Controller
{
    public function index()
    {
        // Latest articles — 1 query instead of 4
        $articles = Article::optimized()
            ->with(['author' => ['id', 'name', 'avatar'], 'category' => ['id', 'name', 'slug']])
            ->withCount('comments')
            ->where('published', true)
            ->latest()
            ->limit(10)
            ->cache(3600)
            ->get();

        // Featured products — 1 query instead of 5
        $products = Product::optimized()
            ->with(['category', 'images' => ['id', 'url']])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withMin('variants', 'price')
            ->where('featured', true)
            ->limit(8)
            ->cache(1800)
            ->get();

        // Categories with counts — 1 query
        $categories = Category::optimized()
            ->withCount('products')
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->cache(7200)
            ->get();

        return view('homepage', compact('articles', 'products', 'categories'));
    }
}
```

**Result:** 3 queries total instead of 15-20+. With caching: **0 queries** after first visit.

---

## 🐛 Troubleshooting

### JSON Functions Not Supported

Your database must support JSON functions:
- MySQL 5.7+ / MariaDB 10.5+
- PostgreSQL 9.4+
- SQLite 3.38+

### Query Returns Empty Relations

Make sure your model has the relation method defined:

```php
class Article extends Model
{
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

### Slow Queries

1. Add database indexes on foreign keys
2. Use `->select()` to limit columns
3. Use `->paginate()` or `->limit()`
4. Enable caching with `->cache(3600)`

### Cache Not Clearing

Cache auto-clears on model `saved` and `deleted` events. For manual clearing:

```php
$article = Article::find(1);
$article->clearOptimizedCache();
```

### Laravel Octane / Long-Running Processes

The in-memory request cache clears automatically when models are saved/deleted. To clear manually:

```php
OptimizedQueryBuilder::clearRequestCache();
```

---

## 📝 Requirements

- PHP 8.1+
- Laravel 9.x, 10.x, 11.x, or 12.x
- MySQL 5.7+ / MariaDB 10.5+ / PostgreSQL 9.4+ / SQLite 3.38+

---

## 📄 License

MIT License. See [LICENSE](LICENSE) file.

---

## 👤 Author

**Shadi Shammaa** — [shadi.shammaa@gmail.com](mailto:shadi.shammaa@gmail.com)

---

## ⭐ Support

If this package saved you time, please give it a star on GitHub! Every star helps the package reach more developers.
