# Changelog

All notable changes to this project will be documented in this file.

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

