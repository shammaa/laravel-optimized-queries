# Changelog

All notable changes to this project will be documented in this file.

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

