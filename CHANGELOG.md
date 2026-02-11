# Changelog

All notable changes to this project will be documented in this file.

## [1.5.0] - 2026-02-11

### ⚡ Architecture
- **Core engine now uses Laravel Query Builder natively** instead of manual SQL string building
- Uses `clone $this->baseQuery`, `addSelect(DB::raw(...))`, and `addBinding(..., 'select')` for correct binding management
- All complex queries (whereHas, closures, scopes, joins) now work correctly
- Created `TranslationResolver` shared service to eliminate code duplication

### 🆕 New Features
- **Aggregate Subqueries**: `withSum()`, `withAvg()`, `withMin()`, `withMax()` for relation aggregates
- **Conditional Chaining**: `when()`, `unless()`, `tap()`, `tapQuery()` for dynamic query building
- **Scoped Queries**: `scopedOptimized()` trait method for cleaner queries with initial conditions
- **Simple Pagination**: `simplePaginate()` — faster pagination without count query
- **Chunking & Lazy**: `chunk()` and `lazy()` for memory-efficient large dataset processing
- **Safe Mode**: Automatic fallback to standard Eloquent if optimized query fails (configurable)
- **Extended WHERE methods**: `whereDate`, `whereYear`, `whereMonth`, `whereDay`, `whereTime`, `whereColumn`, `whereNotBetween`, `orWhereHas`, `doesntExist`, `whereNotNull`, `whereBetween`, `whereDoesntHave`
- **SQL helpers**: `inRandomOrder()`, `distinct()`, `havingRaw()`, `selectRaw()`, `groupBy()`
- **Debugging**: `dump()`, `debug()`, `showPerformance()`, `toSql()`, `getBindings()`
- **Translation methods**: `withTranslation()`, `locale()`, `whereTranslation()`, `orderByTranslation()`, `emptyTranslation()`, `searchTranslation()`, `whereTranslatedSlug()`
- **Format helpers**: `asObject()`, `asEloquent()`, `asArray()`
- **Method validation**: `__call` now validates method existence before forwarding, providing clear error messages for typos
- **Query Splitting**: Automatically splits complex queries when relations exceed `max_relations_per_query` threshold
- **Query Timeout**: Database-level timeout protection via `query_timeout` config (MySQL/PostgreSQL)
- **Complexity Warning**: Logs warning in debug mode when query has 8+ subqueries

### 🔒 Security
- **SQL Injection Prevention**: All dynamic values in RelationBuilder now use parameterized bindings
- **Safe Mode default**: `safe_mode` config defaults to `true` for production stability

### 🔧 Configuration
- `enable_performance_monitoring` now defaults to `false` (was `true`)
- Added `safe_mode` option (defaults to `true`)
- Added `mariadb` to `supported_drivers`

### 🐛 Fixed
- Complex query failures with nested relations, joins, and scopes
- Binding order issues when using closures in WHERE conditions
- Performance monitoring now only activates when `app.debug` is also `true`

### ♻️ Improved
- `clearOptimizedCache()` now also clears in-memory request cache (Octane compatible)
- Better `__call` validation prevents confusing errors from typos
- Auto-detection of all relation types in `with()` method

### ⚠️ Backward Compatibility
- **All existing public API methods are preserved** — no breaking changes
- New features are 100% opt-in additions
- `optimized()`, `opt()`, `with()`, `get()`, `first()`, `find()` all work exactly as before

## [1.0.2] - 2025-12-29

### Added
- `select()` method to specify which columns to retrieve from the base model
- `orderByDesc()` helper method as a shortcut for `orderBy($column, 'desc')`
- `whereHas()` method for filtering by relation existence
- Support for `Closure` in `where()` method for complex nested conditions

### Fixed
- Removed hardcoded version from `composer.json` to rely on git tags
- Fixed duplicate where conditions when using Closure in where()
- Fixed bindings issue when using Closure by properly cloning baseQuery
- Fixed nested relation handling to avoid calling full path as method name
- Fixed SQL syntax error in nested relation aliases (replaced dots with underscores)

### Improved
- Better compatibility with standard Laravel Query Builder syntax
- Enhanced flexibility for complex queries with nested where conditions
- Improved nested relation detection and handling
- Query builder now properly clones baseQuery to prevent side effects

## [1.0.0] - 2025-12-16

### Added
- Initial release
- Core OptimizedQueryBuilder with JSON aggregation support
- Support for single relations (belongsTo, hasOne)
- Support for collection relations (hasMany, hasManyThrough)
- Support for nested relations (e.g., profile.company.country)
- Support for belongsToMany relations
- Support for polymorphic relations
- Built-in query caching with TTL control
- Query logging for debugging
- Pagination support
- Relation callbacks for filtering
- Short syntax method `opt()`
- Comprehensive README with examples
- Configuration file with sensible defaults

### Features
- Transform 5-15 queries into a single optimized SQL statement
- Automatic column detection from model's $fillable
- Support for MySQL, PostgreSQL, and SQLite
- Flexible output format (arrays or Eloquent models)
- Query result caching
- Debug mode with SQL logging
