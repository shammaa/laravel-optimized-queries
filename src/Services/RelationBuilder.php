<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class RelationBuilder
{
    protected Model $model;
    protected Builder $baseQuery;
    protected string $driver;
    protected string $jsonFunction;
    protected bool $supportsJsonArrayAgg;
    protected ?string $locale = null;
    protected array $bindings = [];

    public function __construct(Model $model, Builder $baseQuery)
    {
        $this->model = $model;
        $this->baseQuery = $baseQuery;
        $this->driver = $this->detectDriver();
        $this->jsonFunction = $this->getJsonFunction();
        $this->supportsJsonArrayAgg = $this->checkJsonArrayAggSupport();
    }

    /**
     * Set the locale for translation queries.
     *
     * @param string|null $locale
     * @return $this
     */
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Get collected bindings.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Build JSON aggregation for a relation.
     *
     * @param array $relationConfig
     * @return string|null
     */
    public function buildRelationJson(array $relationConfig): ?string
    {
        $relationName = $relationConfig['name'];
        $type = $relationConfig['type'] ?? 'relation';
        $columns = $relationConfig['columns'] ?? ['*'];
        $callback = $relationConfig['callback'] ?? null;

        // Handle nested relations separately (they contain dots)
        if ($type === 'nested' || str_contains($relationName, '.')) {
            return $this->buildNestedRelationJson($relationName, $columns, $callback);
        }

        // For non-nested relations, get the relation instance
        if (!method_exists($this->model, $relationName)) {
            return null;
        }

        $relation = $this->model->{$relationName}();

        if (!$relation instanceof Relation) {
            return null;
        }

        return match ($type) {
            'relation' => $this->buildSingleRelationJson($relation, $relationName, $columns, $callback),
            'collection' => $this->buildCollectionRelationJson($relation, $relationName, $columns, $callback),
            'many_to_many' => $this->buildManyToManyJson($relation, $relationName, $columns, $callback),
            'polymorphic' => $this->buildPolymorphicJson($relation, $relationName, $columns, $callback),
            default => null,
        };
    }

    /**
     * Build count aggregation.
     *
     * @param array $countConfig
     * @return string|null
     */
    public function buildCount(array $countConfig): ?string
    {
        $relationName = $countConfig['name'];
        $relation = $this->model->{$relationName}();

        if (!$relation instanceof Relation) {
            return null;
        }

        $relatedTable = $relation->getRelated()->getTable();
        $foreignKey = $this->getForeignKey($relation);
        $localKey = $this->getLocalKey($relation);

        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        $callback = $countConfig['callback'] ?? null;
        $whereClause = '';

        $subQuery = $relation->getQuery();
        if ($callback) {
            $callback($subQuery);
        }
        $wheres = $subQuery->getQuery()->wheres ?? [];
        if (!empty($wheres)) {
            $whereClause = $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());
        }

        return "(SELECT COUNT(*) FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}{$whereClause}) AS {$relationName}_count";
    }

    /**
     * Build JSON for single relation (belongsTo, hasOne).
     * Supports translations by joining with the translations table.
     *
     * @param Relation $relation
     * @param string $relationName
     * @param array $columns
     * @param \Closure|null $callback
     * @return string
     */
    protected function buildSingleRelationJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        // Check if related model uses translations and get metadata
        $meta = $this->getTranslationMetadata($relatedModel);
        $translatableFields = $meta['fields'];
        $hasTranslations = !empty($translatableFields) && !empty($meta['table']);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $meta['table'];
        $translationsAlias = $relationName . '_trans';
        
        // Resolve columns (get all requested columns)
        $requestedColumns = in_array('*', $columns) 
            ? array_merge([$relatedModel->getKeyName()], $relatedModel->getFillable())
            : $columns;
        
        // Add timestamps if needed
        if (in_array('*', $columns) && $relatedModel->usesTimestamps()) {
            $requestedColumns[] = $relatedModel->getCreatedAtColumn();
            $requestedColumns[] = $relatedModel->getUpdatedAtColumn();
        }
        $requestedColumns = array_unique($requestedColumns);
        
        // Build JSON pairs - separating main table columns from translated columns
        $jsonPairs = [];
        foreach ($requestedColumns as $col) {
            $jsonPairs[] = "'{$col}'";
            if ($hasTranslations && in_array($col, $translatableFields)) {
                // Get from translations table
                $jsonPairs[] = "{$translationsAlias}.{$col}";
            } else {
                // Get from main table
                $jsonPairs[] = "{$relatedTable}.{$col}";
            }
        }

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        // Build JOIN clause for translations if needed
        $joinClause = '';
        if ($hasTranslations) {
            $relatedKey = $relatedModel->getKeyName();
            $foreignKeyInTranslations = $meta['foreign_key'] ?? ($this->getSingular($relatedTable) . '_id');
            $joinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$relatedKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Build WHERE clause from relation and callback
        $whereClause = '';
        $subQuery = $relation->getQuery();
        if ($callback) {
            $callback($subQuery);
        }
        $wheres = $subQuery->getQuery()->wheres ?? [];
        if (!empty($wheres)) {
            $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());
        }

        // Check if it's a BelongsTo relation (foreign key is in base table)
        // or HasOne (foreign key is in related table)
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            // BelongsTo: foreign key in base table, owner key in related table
            $foreignKeyName = $relation->getForeignKeyName();
            $ownerKeyName = $relation->getOwnerKeyName();
            return "(SELECT {$jsonSql} FROM {$relatedTable}{$joinClause} WHERE {$relatedTable}.{$ownerKeyName} = {$baseTable}.{$foreignKeyName}{$whereClause} LIMIT 1) AS {$relationName}";
        } else {
            // HasOne: foreign key in related table, local key in base table
            $foreignKey = $this->getForeignKey($relation);
            $localKey = $this->getLocalKey($relation);
            return "(SELECT {$jsonSql} FROM {$relatedTable}{$joinClause} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$localKey}{$whereClause} LIMIT 1) AS {$relationName}";
        }
    }

    /**
     * Build JSON array for collection relation (hasMany, hasManyThrough).
     * Supports translations by joining with the translations table.
     *
     * @param Relation $relation
     * @param string $relationName
     * @param array $columns
     * @param \Closure|null $callback
     * @return string
     */
    protected function buildCollectionRelationJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $foreignKey = $this->getForeignKey($relation);
        $localKey = $this->getLocalKey($relation);

        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        // Check if related model uses translations and get metadata
        $meta = $this->getTranslationMetadata($relatedModel);
        $translatableFields = $meta['fields'];
        $hasTranslations = !empty($translatableFields) && !empty($meta['table']);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $meta['table'];
        $translationsAlias = $relationName . '_trans';
        
        // Resolve columns (get all requested columns)
        $requestedColumns = in_array('*', $columns) 
            ? array_merge([$relatedModel->getKeyName()], $relatedModel->getFillable())
            : $columns;
        
        // Add timestamps if needed
        if (in_array('*', $columns) && $relatedModel->usesTimestamps()) {
            $requestedColumns[] = $relatedModel->getCreatedAtColumn();
            $requestedColumns[] = $relatedModel->getUpdatedAtColumn();
        }
        $requestedColumns = array_unique($requestedColumns);
        
        // Build JSON pairs - separating main table columns from translated columns
        $jsonPairs = [];
        foreach ($requestedColumns as $col) {
            $jsonPairs[] = "'{$col}'";
            if ($hasTranslations && in_array($col, $translatableFields)) {
                $jsonPairs[] = "{$translationsAlias}.{$col}";
            } else {
                $jsonPairs[] = "{$relatedTable}.{$col}";
            }
        }

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        // Build JOIN clause for translations if needed
        $joinClause = '';
        if ($hasTranslations) {
            $relatedKey = $relatedModel->getKeyName();
            $foreignKeyInTranslations = $meta['foreign_key'] ?? ($this->getSingular($relatedTable) . '_id');
            $joinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$relatedKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Build WHERE clause from relation and callback
        $whereClause = '';
        $subQuery = $relation->getQuery();
        if ($callback) {
            $callback($subQuery);
        }
        $wheres = $subQuery->getQuery()->wheres ?? [];
        if (!empty($wheres)) {
            $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());
        }

        // Use JSON_ARRAYAGG if supported, otherwise fallback to GROUP_CONCAT for MariaDB 10.4
        if ($this->supportsJsonArrayAgg) {
            $arrayAgg = "JSON_ARRAYAGG({$jsonSql})";
        } else {
            $arrayAgg = "CONCAT('[', COALESCE(GROUP_CONCAT({$jsonSql} SEPARATOR ','), ''), ']')";
        }

        return "(SELECT {$arrayAgg} FROM {$relatedTable}{$joinClause} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}{$whereClause}) AS {$relationName}";
    }

    /**
     * Build JSON for nested relations (e.g., 'profile.company.country').
     *
     * @param string $relationPath
     * @param array $columns
     * @param \Closure|null $callback
     * @return string
     */
    protected function buildNestedRelationJson(string $relationPath, array $columns, ?\Closure $callback): string
    {
        // Split the path into parts (e.g., 'category.parent' -> ['category', 'parent'])
        $parts = explode('.', $relationPath);
        $firstRelation = $parts[0];
        
        // Check if first relation exists
        if (!method_exists($this->model, $firstRelation)) {
            throw new \BadMethodCallException("Relation {$firstRelation} does not exist on model " . get_class($this->model));
        }
        
        $relation = $this->model->{$firstRelation}();
        
        // Replace dots with underscores for valid SQL alias
        $aliasName = str_replace('.', '_', $relationPath);
        
        // For now, we only support the first level of nesting
        // Full nested support would require recursive SQL building which is complex
        // Users should use Laravel's with() for deep nesting
        return $this->buildSingleRelationJson(
            $relation,
            $aliasName,  // Use underscore version as alias
            $columns,
            $callback
        );
    }

    /**
     * Build JSON for belongsToMany or morphToMany relation.
     * Supports translations by joining with the translations table.
     *
     * @param Relation $relation
     * @param string $relationName
     * @param array $columns
     * @param \Closure|null $callback
     * @return string
     */
    protected function buildManyToManyJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $pivotTable = $relation->getTable();
        
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();
        
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $relatedKey = $relation->getRelatedKeyName();

        // Check if related model uses translations and get metadata
        $meta = $this->getTranslationMetadata($relatedModel);
        $translatableFields = $meta['fields'];
        $hasTranslations = !empty($translatableFields) && !empty($meta['table']);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $meta['table'];
        $translationsAlias = $relationName . '_trans';
        
        // Resolve columns (get all requested columns)
        $requestedColumns = in_array('*', $columns) 
            ? array_merge([$relatedModel->getKeyName()], $relatedModel->getFillable())
            : $columns;
        
        // Add timestamps if needed
        if (in_array('*', $columns) && $relatedModel->usesTimestamps()) {
            $requestedColumns[] = $relatedModel->getCreatedAtColumn();
            $requestedColumns[] = $relatedModel->getUpdatedAtColumn();
        }
        $requestedColumns = array_unique($requestedColumns);
        
        // Build JSON pairs - separating main table columns from translated columns
        $jsonPairs = [];
        foreach ($requestedColumns as $col) {
            $jsonPairs[] = "'{$col}'";
            if ($hasTranslations && in_array($col, $translatableFields)) {
                $jsonPairs[] = "{$translationsAlias}.{$col}";
            } else {
                $jsonPairs[] = "{$relatedTable}.{$col}";
            }
        }

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        // Build JOIN clause for translations if needed
        $translationJoinClause = '';
        if ($hasTranslations) {
            $modelKey = $relatedModel->getKeyName();
            $foreignKeyInTranslations = $meta['foreign_key'] ?? ($this->getSingular($relatedTable) . '_id');
            $translationJoinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$modelKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Handle MorphToMany specific conditions (e.g., taggable_type)
        $morphCondition = '';
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany) {
            $morphType = $relation->getMorphType();
            $morphClass = $relation->getMorphClass();
            $morphCondition = " AND {$pivotTable}.{$morphType} = '{$morphClass}'";
        }

        // Use JSON_ARRAYAGG if supported, otherwise fallback to GROUP_CONCAT
        if ($this->supportsJsonArrayAgg) {
            $arrayAgg = "JSON_ARRAYAGG({$jsonSql})";
        } else {
            $arrayAgg = "CONCAT('[', COALESCE(GROUP_CONCAT({$jsonSql} SEPARATOR ','), ''), ']')";
        }

        // Build WHERE clause from relation and callback
        $whereClause = '';
        $subQuery = $relation->getQuery();
        if ($callback) {
            $callback($subQuery);
        }
        $wheres = $subQuery->getQuery()->wheres ?? [];
        if (!empty($wheres)) {
            $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());
        }

        return "(SELECT {$arrayAgg} FROM {$relatedTable} INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey}{$translationJoinClause} WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}{$morphCondition}{$whereClause}) AS {$relationName}";
    }

    /**
     * Build JSON for polymorphic relation.
     *
     * @param Relation $relation
     * @param string $relationName
     * @param array $columns
     * @param \Closure|null $callback
     * @return string
     */
    protected function buildPolymorphicJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();
        
        $morphType = $relation->getMorphType();
        $morphClass = get_class($this->model);
        $foreignKey = $relation->getForeignKeyName();

        // Resolve columns
        $resolvedColumns = $this->resolveColumns($relatedModel, $columns);

        // Build JSON object
        $jsonPairs = $this->buildJsonPairs($resolvedColumns, $relatedTable);

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        // Build WHERE clause from relation and callback
        $whereClause = '';
        $subQuery = $relation->getQuery();
        if ($callback) {
            $callback($subQuery);
        }
        $wheres = $subQuery->getQuery()->wheres ?? [];
        if (!empty($wheres)) {
            $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable, $subQuery->getBindings());
        }

        return "(SELECT {$jsonSql} FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey} AND {$relatedTable}.{$morphType} = '{$morphClass}'{$whereClause} LIMIT 1) AS {$relationName}";
    }

    /**
     * Resolve columns for a model.
     * Excludes translatable columns if the model uses HasTranslations trait.
     *
     * @param Model $model
     * @param array $columns
     * @return array
     */
    protected function resolveColumns(Model $model, array $columns): array
    {
        $meta = $this->getTranslationMetadata($model);
        $translatableFields = $meta['fields'];

        if (in_array('*', $columns)) {
            $fillable = $model->getFillable();
            $columns = array_merge([$model->getKeyName()], $fillable);
            
            if ($model->usesTimestamps()) {
                $columns[] = $model->getCreatedAtColumn();
                $columns[] = $model->getUpdatedAtColumn();
            }

            // ✅ Check if related model uses translations and exclude translatable columns
            if (!empty($translatableFields)) {
                $columns = array_filter($columns, function ($col) use ($translatableFields) {
                    return !in_array($col, $translatableFields);
                });
            }
        } else {
            // ✅ Even for explicit columns, filter out translatable ones
            if (!empty($translatableFields)) {
                $columns = array_filter($columns, function ($col) use ($translatableFields) {
                    return !in_array($col, $translatableFields);
                });
            }
        }

        return array_unique(array_values($columns));
    }

    /**
     * Get translatable fields and metadata from a model.
     *
     * @param Model $model
     * @return array{fields: array, table: string|null, foreign_key: string|null}
     */
    protected function getTranslationMetadata(Model $model): array
    {
        $traits = class_uses_recursive($model);
        $isTranslatable = false;
        
        foreach ($traits as $trait) {
            if (str_contains($trait, 'HasTranslations') || str_contains($trait, 'Translatable')) {
                $isTranslatable = true;
                break;
            }
        }

        // If not translatable by trait/property/method, return empty
        $hasProperty = property_exists($model, 'translatable');
        $hasMethod = method_exists($model, 'getTranslatableAttributes');
        $hasTranslationMethod = method_exists($model, 'translations');

        if (!$isTranslatable && !$hasProperty && !$hasMethod && !$hasTranslationMethod) {
            return ['fields' => [], 'table' => null, 'foreign_key' => null];
        }

        // Get translatable fields
        $fields = [];
        if ($hasProperty) {
            $fields = (array) $model->translatable;
        } elseif ($hasMethod) {
            $fields = (array) $model->getTranslatableAttributes();
        }

        if (empty($fields)) {
            return ['fields' => [], 'table' => null, 'foreign_key' => null];
        }

        // Try to get metadata from the 'translations' relation if it exists
        $table = null;
        $foreignKey = null;

        try {
            if ($hasTranslationMethod || method_exists($model, 'getTranslationModelName')) {
                $relation = $model->translations();
                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
                    $table = $relation->getRelated()->getTable();
                    $foreignKey = $relation->getForeignKeyName();
                }
            }
        } catch (\Throwable $e) {
            // Fallback to guessing if relation call fails
        }

        // Final fallback: Guessing (The smart way)
        if (!$table) {
            $baseTable = $model->getTable();
            $singular = $this->getSingular($baseTable);
            $table = $singular . '_translations';
            $foreignKey = $singular . '_id';
        }

        return [
            'fields' => $fields,
            'table' => $table,
            'foreign_key' => $foreignKey
        ];
    }

    /**
     * Build JSON pairs for JSON_OBJECT function.
     *
     * @param array $columns
     * @param string $table
     * @return array
     */
    protected function buildJsonPairs(array $columns, string $table): array
    {
        $pairs = [];

        foreach ($columns as $column) {
            $pairs[] = "'{$column}'";
            $pairs[] = "{$table}.{$column}";
        }

        return $pairs;
    }

    /**
     * Get foreign key from relation.
     *
     * @param Relation $relation
     * @return string
     */
    protected function getForeignKey(Relation $relation): string
    {
        if (method_exists($relation, 'getForeignKeyName')) {
            return $relation->getForeignKeyName();
        }

        if (method_exists($relation, 'getForeignKey')) {
            return $relation->getForeignKey();
        }

        // Default fallback
        $relatedModel = $relation->getRelated();
        return $this->model->getForeignKey();
    }

    /**
     * Get local key from relation.
     *
     * @param Relation $relation
     * @return string
     */
    protected function getLocalKey(Relation $relation): string
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
     * Get singular form of a table name.
     * Used to build translation table names (e.g., 'categories' -> 'category_translations').
     *
     * @param string $table
     * @return string
     */
    protected function getSingular(string $table): string
    {
        // Use Laravel's Str::singular if available
        if (class_exists(\Illuminate\Support\Str::class)) {
            return \Illuminate\Support\Str::singular($table);
        }

        // Simple fallback for common cases
        if (str_ends_with($table, 'ies')) {
            return substr($table, 0, -3) . 'y';
        }
        if (str_ends_with($table, 'es')) {
            return substr($table, 0, -2);
        }
        if (str_ends_with($table, 's')) {
            return substr($table, 0, -1);
        }

        return $table;
    }

    /**
     * Build WHERE clause from query wheres.
     *
     * @param array $wheres
     * @param string $table
     * @return string
     */
    protected function buildWhereClause(array $wheres, string $table, array $baseBindings = []): string
    {
        $conditions = [];
        $bindingIndex = 0;

        foreach ($wheres as $where) {
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? '=';
            $value = $where['value'] ?? null;

            if ($column) {
                $fullColumn = strpos($column, '.') !== false ? $column : "{$table}.{$column}";
                
                if ($operator === 'IN' && is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "{$fullColumn} IN ({$placeholders})";
                    foreach ($value as $val) {
                        $this->bindings[] = $val;
                    }
                } else {
                    $conditions[] = "{$fullColumn} {$operator} ?";
                    if (isset($baseBindings[$bindingIndex])) {
                        $this->bindings[] = $baseBindings[$bindingIndex];
                        $bindingIndex++;
                    } else {
                        $this->bindings[] = $value;
                    }
                }
            }
        }

        return !empty($conditions) ? ' ' . implode(' AND ', $conditions) : '';
    }

    /**
     * Detect database driver.
     *
     * @return string
     */
    protected function detectDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Get JSON function based on driver.
     *
     * @return string
     */
    protected function getJsonFunction(): string
    {
        $configFunction = config('optimized-queries.json_function', 'auto');
        
        if ($configFunction !== 'auto') {
            return $configFunction;
        }

        return match ($this->driver) {
            'mysql' => 'JSON_OBJECT',
            'pgsql' => 'JSON_BUILD_OBJECT',
            'sqlite' => 'JSON_OBJECT',
            default => 'JSON_OBJECT',
        };
    }

    /**
     * Check if database supports JSON_ARRAYAGG function.
     *
     * @return bool
     */
    protected function checkJsonArrayAggSupport(): bool
    {
        if ($this->driver !== 'mysql') {
            return false;
        }

        try {
            $versionResult = DB::selectOne('SELECT VERSION() as version');
            $version = $versionResult->version ?? '';

            // Check if it's MariaDB
            if (str_contains($version, 'MariaDB')) {
                // Extract version number (e.g., "10.4.32-MariaDB" -> "10.4")
                if (preg_match('/(\d+\.\d+)/', $version, $matches)) {
                    $versionNumber = floatval($matches[1]);
                    // JSON_ARRAYAGG available in MariaDB 10.5+
                    return $versionNumber >= 10.5;
                }
                return false;
            }

            // For MySQL, JSON_ARRAYAGG available in 5.7.22+
            if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
                return version_compare($matches[1], '5.7.22', '>=');
            }

            return true; // Assume support for unknown versions
        } catch (\Exception $e) {
            // If we can't determine version, assume no support
            return false;
        }
    }
}
