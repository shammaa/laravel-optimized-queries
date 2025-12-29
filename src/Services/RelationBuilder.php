<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class RelationBuilder
{
    protected Model $model;
    protected Builder $baseQuery;
    protected string $driver;
    protected string $jsonFunction;

    public function __construct(Model $model, Builder $baseQuery)
    {
        $this->model = $model;
        $this->baseQuery = $baseQuery;
        $this->driver = $this->detectDriver();
        $this->jsonFunction = $this->getJsonFunction();
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
        $relation = $this->model->{$relationName}();

        if (!$relation instanceof Relation) {
            return null;
        }

        $type = $relationConfig['type'] ?? 'relation';
        $columns = $relationConfig['columns'] ?? ['*'];
        $callback = $relationConfig['callback'] ?? null;

        return match ($type) {
            'relation' => $this->buildSingleRelationJson($relation, $relationName, $columns, $callback),
            'collection' => $this->buildCollectionRelationJson($relation, $relationName, $columns, $callback),
            'nested' => $this->buildNestedRelationJson($relationName, $columns, $callback),
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
        $foreignKey = $this->getForeignKey($relation);
        $localKey = $this->getLocalKey($relation);

        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        // Resolve columns
        $resolvedColumns = $this->resolveColumns($relatedModel, $columns);

        // Build JSON object
        $jsonPairs = $this->buildJsonPairs($resolvedColumns, $relatedTable);

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

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        return "(SELECT {$jsonSql} FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}{$whereClause} LIMIT 1) AS {$relationName}";
    }

    /**
     * Build JSON array for collection relation (hasMany, hasManyThrough).
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

        // Resolve columns
        $resolvedColumns = $this->resolveColumns($relatedModel, $columns);

        // Build JSON object
        $jsonPairs = $this->buildJsonPairs($resolvedColumns, $relatedTable);

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

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        return "(SELECT JSON_ARRAYAGG({$jsonSql}) FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}{$whereClause}) AS {$relationName}";
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
        
        // For now, we only support the first level of nesting
        // Full nested support would require recursive SQL building which is complex
        // Users should use Laravel's with() for deep nesting
        return $this->buildSingleRelationJson(
            $relation,
            $relationPath,  // Use full path as alias
            $columns,
            $callback
        );
    }

    /**
     * Build JSON for belongsToMany relation.
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
        $parentKey = $relation->getParentKeyName();
        $relatedKey = $relation->getRelatedKeyName();

        // Resolve columns
        $resolvedColumns = $this->resolveColumns($relatedModel, $columns);

        // Build JSON object
        $jsonPairs = $this->buildJsonPairs($resolvedColumns, $relatedTable);

        $jsonSql = $this->jsonFunction . '(' . implode(', ', $jsonPairs) . ')';

        return "(SELECT JSON_ARRAYAGG({$jsonSql}) FROM {$relatedTable} INNER JOIN {$pivotTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey} WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}) AS {$relationName}";
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
        }

        return array_unique($columns);
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
}

