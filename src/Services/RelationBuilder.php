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

        if ($callback) {
            // Apply callback to build WHERE clause
            $subQuery = $relation->getQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres ?? [];
            if (!empty($wheres)) {
                $whereClause = $this->buildWhereClause($wheres, $relatedTable);
            }
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

        // Check if related model uses translations
        $translatableFields = $this->getTranslatableFieldsFromModel($relatedModel);
        $hasTranslations = !empty($translatableFields);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $this->getSingular($relatedTable) . '_translations';
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
            $foreignKeyInTranslations = $this->getSingular($relatedTable) . '_id';
            $joinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$relatedKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Build WHERE clause from callback
        $whereClause = '';
        if ($callback) {
            $subQuery = $relation->getQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres ?? [];
            if (!empty($wheres)) {
                $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable);
            }
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

        // Check if related model uses translations
        $translatableFields = $this->getTranslatableFieldsFromModel($relatedModel);
        $hasTranslations = !empty($translatableFields);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $this->getSingular($relatedTable) . '_translations';
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
            $foreignKeyInTranslations = $this->getSingular($relatedTable) . '_id';
            $joinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$relatedKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Build WHERE clause from callback
        $whereClause = '';
        if ($callback) {
            $subQuery = $relation->getQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres ?? [];
            if (!empty($wheres)) {
                $whereClause = ' AND ' . $this->buildWhereClause($wheres, $relatedTable);
            }
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
     * Build JSON for belongsToMany relation.
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

        // Check if related model uses translations
        $translatableFields = $this->getTranslatableFieldsFromModel($relatedModel);
        $hasTranslations = !empty($translatableFields);
        
        // Get locale
        $locale = $this->locale ?? app()->getLocale();
        
        // Build translations table name and alias
        $translationsTable = $this->getSingular($relatedTable) . '_translations';
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
            $foreignKeyInTranslations = $this->getSingular($relatedTable) . '_id';
            $translationJoinClause = " LEFT JOIN {$translationsTable} AS {$translationsAlias} ON {$relatedTable}.{$modelKey} = {$translationsAlias}.{$foreignKeyInTranslations} AND {$translationsAlias}.locale = '{$locale}'";
        }

        // Use JSON_ARRAYAGG if supported, otherwise fallback to GROUP_CONCAT
        if ($this->supportsJsonArrayAgg) {
            $arrayAgg = "JSON_ARRAYAGG({$jsonSql})";
        } else {
            $arrayAgg = "CONCAT('[', COALESCE(GROUP_CONCAT({$jsonSql} SEPARATOR ','), ''), ']')";
        }

        return "(SELECT {$arrayAgg} FROM {$relatedTable} INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey}{$translationJoinClause} WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}) AS {$relationName}";
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

        return "(SELECT {$jsonSql} FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey} AND {$relatedTable}.{$morphType} = '{$morphClass}' LIMIT 1) AS {$relationName}";
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
        if (in_array('*', $columns)) {
            $fillable = $model->getFillable();
            $columns = array_merge([$model->getKeyName()], $fillable);
            
            if ($model->usesTimestamps()) {
                $columns[] = $model->getCreatedAtColumn();
                $columns[] = $model->getUpdatedAtColumn();
            }

            // ✅ Check if related model uses translations and exclude translatable columns
            $translatableFields = $this->getTranslatableFieldsFromModel($model);
            if (!empty($translatableFields)) {
                $columns = array_filter($columns, function ($col) use ($translatableFields) {
                    return !in_array($col, $translatableFields);
                });
            }
        } else {
            // ✅ Even for explicit columns, filter out translatable ones
            $translatableFields = $this->getTranslatableFieldsFromModel($model);
            if (!empty($translatableFields)) {
                $columns = array_filter($columns, function ($col) use ($translatableFields) {
                    return !in_array($col, $translatableFields);
                });
            }
        }

        return array_unique(array_values($columns));
    }

    /**
     * Get translatable fields from a model if it uses HasTranslations.
     *
     * @param Model $model
     * @return array
     */
    protected function getTranslatableFieldsFromModel(Model $model): array
    {
        // Check if model uses HasTranslations trait
        $traits = class_uses_recursive($model);
        $hasTranslations = false;
        
        foreach ($traits as $trait) {
            if (str_contains($trait, 'HasTranslations') || str_contains($trait, 'Translatable')) {
                $hasTranslations = true;
                break;
            }
        }

        if (!$hasTranslations && !method_exists($model, 'translations')) {
            return [];
        }

        // Get translatable fields
        if (property_exists($model, 'translatable')) {
            return $model->translatable ?? [];
        }

        if (method_exists($model, 'getTranslatableAttributes')) {
            return $model->getTranslatableAttributes();
        }

        // Common translatable fields as fallback
        return ['title', 'name', 'description', 'content', 'slug', 'short_description', 'meta_title', 'meta_description', 'meta_keywords'];
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
    protected function buildWhereClause(array $wheres, string $table): string
    {
        $conditions = [];

        foreach ($wheres as $where) {
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? '=';
            $value = $where['value'] ?? null;

            if ($column) {
                $fullColumn = strpos($column, '.') !== false ? $column : "{$table}.{$column}";
                
                if ($operator === 'IN' && is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "{$fullColumn} IN ({$placeholders})";
                } else {
                    $conditions[] = "{$fullColumn} {$operator} ?";
                }
            }
        }

        return !empty($conditions) ? ' AND ' . implode(' AND ', $conditions) : '';
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

