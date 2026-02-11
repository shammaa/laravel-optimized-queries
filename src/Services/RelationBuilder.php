<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shammaa\LaravelOptimizedQueries\Support\TranslationResolver;

class RelationBuilder
{
    protected Model $model;
    protected Builder $baseQuery;
    protected string $driver;
    protected ?string $locale = null;
    protected array $bindings = [];

    public function __construct(Model $model, Builder $baseQuery)
    {
        $this->model = $model;
        $this->baseQuery = $baseQuery;
        $this->driver = $this->detectDriver();
    }

    /**
     * Set the locale for translation queries.
     */
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Get collected bindings.
     * These should be added to the main query's 'select' binding type.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Build JSON aggregation for a relation.
     */
    public function buildRelationJson(array $relationConfig): ?string
    {
        $name = $relationConfig['name'];
        $type = $relationConfig['type'];
        $columns = $relationConfig['columns'] ?? ['*'];
        $callback = $relationConfig['callback'] ?? null;

        if (!method_exists($this->model, explode('.', $name)[0])) {
            return null;
        }

        try {
            return match ($type) {
                'single' => $this->buildSingleRelationJson($this->model->{$name}(), $name, $columns, $callback),
                'collection' => $this->buildCollectionRelationJson($this->model->{$name}(), $name, $columns, $callback),
                'many_to_many' => $this->buildManyToManyJson($this->model->{$name}(), $name, $columns, $callback),
                'nested' => $this->buildNestedRelationJson($name, $columns, $callback),
                'polymorphic' => $this->buildPolymorphicJson($this->model->{$name}(), $name, $columns, $callback),
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build count aggregation.
     */
    public function buildCount(array $countConfig): ?string
    {
        $name = $countConfig['name'];
        $callback = $countConfig['callback'] ?? null;

        if (!method_exists($this->model, $name)) {
            return null;
        }

        try {
            $relation = $this->model->{$name}();
            if (!$relation instanceof Relation) return null;

            $relatedTable = $relation->getRelated()->getTable();
            $foreignKey = $this->getForeignKey($relation);
            $baseTable = $this->model->getTable();
            $baseKey = $this->getLocalKey($relation);

            $whereClause = '';
            if ($callback) {
                $subQuery = $relation->getRelated()->newQuery();
                $callback($subQuery);
                $wheres = $subQuery->getQuery()->wheres;
                $subBindings = $subQuery->getQuery()->getBindings();
                $clause = $this->buildWhereClause($wheres, $relatedTable, $subBindings);
                if ($clause) $whereClause = " AND {$clause}";
            }

            // Handle BelongsToMany
            if ($relation instanceof BelongsToMany || $relation instanceof MorphToMany) {
                $pivotTable = $relation->getTable();
                $foreignPivotKey = $relation->getForeignPivotKeyName();
                $relatedPivotKey = $relation->getRelatedPivotKeyName();
                $relatedKey = $relation->getRelatedKeyName();

                return "(SELECT COUNT(*) FROM {$pivotTable} " .
                    "INNER JOIN {$relatedTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey} " .
                    "WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}{$whereClause}) AS {$name}_count";
            }

            return "(SELECT COUNT(*) FROM {$relatedTable} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$baseKey}{$whereClause}) AS {$name}_count";
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build JSON for single relation (belongsTo, hasOne).
     * Supports translations.
     */
    protected function buildSingleRelationJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): ?string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $baseTable = $this->model->getTable();

        [$dbColumns, $transColumns] = $this->resolveRequestedColumns($relatedModel, $columns);
        $jsonPairs = $this->buildJsonPairs($dbColumns, $relatedTable);

        // Translation JOIN support
        $transJoin = '';
        if (!empty($transColumns) && $this->locale) {
            $meta = TranslationResolver::getMetadata($relatedModel);
            if ($meta['table']) {
                $transAlias = "trans_{$relationName}";
                $transJoin = " LEFT JOIN {$meta['table']} AS {$transAlias} ON {$relatedTable}.{$relatedModel->getKeyName()} = {$transAlias}.{$meta['foreign_key']} AND {$transAlias}.locale = ?";
                $this->bindings[] = $this->locale;

                foreach ($transColumns as $tc) {
                    $jsonPairs .= ", '{$tc}', {$transAlias}.{$tc}";
                }
            }
        }

        $jsonFunc = $this->getJsonFunction();

        // Build WHERE JOIN condition
        if ($relation instanceof BelongsTo) {
            $foreignKey = $relation->getForeignKeyName();
            $ownerKey = $relation->getOwnerKeyName();
            $joinCondition = "{$relatedTable}.{$ownerKey} = {$baseTable}.{$foreignKey}";
        } else {
            $foreignKey = $this->getForeignKey($relation);
            $localKey = $this->getLocalKey($relation);
            $joinCondition = "{$relatedTable}.{$foreignKey} = {$baseTable}.{$localKey}";
        }

        // Extra WHERE from callback
        $extraWhere = '';
        if ($callback) {
            $subQuery = $relatedModel->newQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres;
            $subBindings = $subQuery->getQuery()->getBindings();
            $clause = $this->buildWhereClause($wheres, $relatedTable, $subBindings);
            if ($clause) $extraWhere = " AND {$clause}";
        }

        $alias = str_replace('.', '_', $relationName);

        return "(SELECT {$jsonFunc}({$jsonPairs}) FROM {$relatedTable}{$transJoin} WHERE {$joinCondition}{$extraWhere} LIMIT 1) AS `{$alias}`";
    }

    /**
     * Build JSON array for collection relation (hasMany, hasManyThrough).
     * Supports translations.
     */
    protected function buildCollectionRelationJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): ?string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $baseTable = $this->model->getTable();
        $foreignKey = $this->getForeignKey($relation);
        $localKey = $this->getLocalKey($relation);

        [$dbColumns, $transColumns] = $this->resolveRequestedColumns($relatedModel, $columns);
        $jsonPairs = $this->buildJsonPairs($dbColumns, $relatedTable);

        // Translation JOIN support
        $transJoin = '';
        if (!empty($transColumns) && $this->locale) {
            $meta = TranslationResolver::getMetadata($relatedModel);
            if ($meta['table']) {
                $transAlias = "trans_{$relationName}";
                $transJoin = " LEFT JOIN {$meta['table']} AS {$transAlias} ON {$relatedTable}.{$relatedModel->getKeyName()} = {$transAlias}.{$meta['foreign_key']} AND {$transAlias}.locale = ?";
                $this->bindings[] = $this->locale;

                foreach ($transColumns as $tc) {
                    $jsonPairs .= ", '{$tc}', {$transAlias}.{$tc}";
                }
            }
        }

        $jsonFunc = $this->getJsonFunction();
        $jsonAgg = $this->getJsonArrayAgg();

        // Extra WHERE from callback
        $extraWhere = '';
        $orderClause = '';
        if ($callback) {
            $subQuery = $relatedModel->newQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres;
            $subBindings = $subQuery->getQuery()->getBindings();
            $clause = $this->buildWhereClause($wheres, $relatedTable, $subBindings);
            if ($clause) $extraWhere = " AND {$clause}";

            $orders = $subQuery->getQuery()->orders ?? [];
            if (!empty($orders)) {
                $orderParts = array_map(fn($o) => "{$relatedTable}.{$o['column']} {$o['direction']}", $orders);
                $orderClause = ' ORDER BY ' . implode(', ', $orderParts);
            }
        }

        $alias = str_replace('.', '_', $relationName);

        if ($this->driver === 'pgsql') {
            return "(SELECT COALESCE(json_agg({$jsonFunc}({$jsonPairs}){$orderClause}), '[]'::json) FROM {$relatedTable}{$transJoin} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$localKey}{$extraWhere}) AS \"{$alias}\"";
        }

        return "(SELECT COALESCE(CONCAT('[', GROUP_CONCAT({$jsonFunc}({$jsonPairs}){$orderClause}), ']'), '[]') FROM {$relatedTable}{$transJoin} WHERE {$relatedTable}.{$foreignKey} = {$baseTable}.{$localKey}{$extraWhere}) AS `{$alias}`";
    }

    /**
     * Build JSON for nested relations (e.g., 'profile.company.country').
     */
    protected function buildNestedRelationJson(string $relationPath, array $columns, ?\Closure $callback): ?string
    {
        $parts = explode('.', $relationPath);
        $alias = str_replace('.', '_', $relationPath);

        if (count($parts) < 2) return null;

        $currentModel = $this->model;
        $selectParts = [];
        $fromTable = '';
        $joins = [];

        foreach ($parts as $i => $part) {
            if (!method_exists($currentModel, $part)) return null;
            $relation = $currentModel->{$part}();
            if (!$relation instanceof Relation) return null;

            $relatedModel = $relation->getRelated();
            $relatedTable = $relatedModel->getTable();

            if ($i === 0) {
                $fromTable = $relatedTable;
                if ($relation instanceof BelongsTo) {
                    $foreignKey = $relation->getForeignKeyName();
                    $ownerKey = $relation->getOwnerKeyName();
                    $joins[] = ['condition' => "{$relatedTable}.{$ownerKey} = {$this->model->getTable()}.{$foreignKey}", 'table' => $relatedTable];
                } else {
                    $fk = $this->getForeignKey($relation);
                    $lk = $this->getLocalKey($relation);
                    $joins[] = ['condition' => "{$relatedTable}.{$fk} = {$this->model->getTable()}.{$lk}", 'table' => $relatedTable];
                }
            } else {
                $prevTable = $parts[$i - 1] . '_table';
                $prevModel = $currentModel;
                if ($relation instanceof BelongsTo) {
                    $joins[] = ['type' => 'INNER JOIN', 'table' => $relatedTable, 'condition' => "{$relatedTable}.{$relation->getOwnerKeyName()} = {$fromTable}.{$relation->getForeignKeyName()}"];
                }
            }

            if ($i === count($parts) - 1) {
                $resolvedCols = $this->resolveColumns($relatedModel, $columns);
                $selectParts = $this->buildJsonPairs($resolvedCols, $relatedTable);
            }

            $currentModel = $relatedModel;
            $fromTable = $relatedTable;
        }

        $jsonFunc = $this->getJsonFunction();
        $mainJoin = $joins[0]['condition'] ?? '';

        $joinSql = '';
        for ($i = 1; $i < count($joins); $i++) {
            $j = $joins[$i];
            $type = $j['type'] ?? 'INNER JOIN';
            $joinSql .= " {$type} {$j['table']} ON {$j['condition']}";
        }

        $firstTable = $joins[0]['table'] ?? $fromTable;

        return "(SELECT {$jsonFunc}({$selectParts}) FROM {$firstTable}{$joinSql} WHERE {$mainJoin} LIMIT 1) AS `{$alias}`";
    }

    /**
     * Build JSON for belongsToMany or morphToMany relation.
     * Supports translations.
     */
    protected function buildManyToManyJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): ?string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $relatedKey = $relation->getRelatedKeyName();

        [$dbColumns, $transColumns] = $this->resolveRequestedColumns($relatedModel, $columns);
        $jsonPairs = $this->buildJsonPairs($dbColumns, $relatedTable);

        // Translation JOIN support
        $transJoin = '';
        if (!empty($transColumns) && $this->locale) {
            $meta = TranslationResolver::getMetadata($relatedModel);
            if ($meta['table']) {
                $transAlias = "trans_{$relationName}";
                $transJoin = " LEFT JOIN {$meta['table']} AS {$transAlias} ON {$relatedTable}.{$relatedModel->getKeyName()} = {$transAlias}.{$meta['foreign_key']} AND {$transAlias}.locale = ?";
                $this->bindings[] = $this->locale;

                foreach ($transColumns as $tc) {
                    $jsonPairs .= ", '{$tc}', {$transAlias}.{$tc}";
                }
            }
        }

        $jsonFunc = $this->getJsonFunction();

        // Morph type condition
        $morphCondition = '';
        if ($relation instanceof MorphToMany) {
            $morphType = $relation->getMorphType();
            $morphClass = $relation->getMorphClass();
            $morphCondition = " AND {$pivotTable}.{$morphType} = ?";
            $this->bindings[] = $morphClass;
        }

        // Extra WHERE from callback
        $extraWhere = '';
        if ($callback) {
            $subQuery = $relatedModel->newQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres;
            $subBindings = $subQuery->getQuery()->getBindings();
            $clause = $this->buildWhereClause($wheres, $relatedTable, $subBindings);
            if ($clause) $extraWhere = " AND {$clause}";
        }

        $alias = str_replace('.', '_', $relationName);

        if ($this->driver === 'pgsql') {
            return "(SELECT COALESCE(json_agg(json_build_object({$jsonPairs})), '[]'::json) " .
                "FROM {$pivotTable} " .
                "INNER JOIN {$relatedTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey}{$transJoin} " .
                "WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}{$morphCondition}{$extraWhere}" .
                ") AS \"{$alias}\"";
        }

        return "(SELECT COALESCE(CONCAT('[', GROUP_CONCAT({$jsonFunc}({$jsonPairs})), ']'), '[]') " .
            "FROM {$pivotTable} " .
            "INNER JOIN {$relatedTable} ON {$relatedTable}.{$relatedKey} = {$pivotTable}.{$relatedPivotKey}{$transJoin} " .
            "WHERE {$pivotTable}.{$foreignPivotKey} = {$baseTable}.{$baseKey}{$morphCondition}{$extraWhere}" .
            ") AS `{$alias}`";
    }

    /**
     * Build JSON for polymorphic relation.
     */
    protected function buildPolymorphicJson(
        Relation $relation,
        string $relationName,
        array $columns,
        ?\Closure $callback
    ): ?string {
        $relatedModel = $relation->getRelated();
        $relatedTable = $relatedModel->getTable();
        $baseTable = $this->model->getTable();
        $baseKey = $this->model->getKeyName();

        $morphType = '';
        $morphId = '';
        $morphClass = get_class($this->model);

        if (method_exists($relation, 'getMorphType')) {
            $morphType = $relation->getMorphType();
            $morphId = $relation->getForeignKeyName();
        }

        $resolvedCols = $this->resolveColumns($relatedModel, $columns);
        $jsonPairs = $this->buildJsonPairs($resolvedCols, $relatedTable);
        $jsonFunc = $this->getJsonFunction();

        $extraWhere = '';
        if ($callback) {
            $subQuery = $relatedModel->newQuery();
            $callback($subQuery);
            $wheres = $subQuery->getQuery()->wheres;
            $subBindings = $subQuery->getQuery()->getBindings();
            $clause = $this->buildWhereClause($wheres, $relatedTable, $subBindings);
            if ($clause) $extraWhere = " AND {$clause}";
        }

        $alias = str_replace('.', '_', $relationName);

        // Use parameterized binding for morph class
        $this->bindings[] = $morphClass;

        if ($this->driver === 'pgsql') {
            return "(SELECT COALESCE(json_agg(json_build_object({$jsonPairs})), '[]'::json) " .
                "FROM {$relatedTable} WHERE {$relatedTable}.{$morphId} = {$baseTable}.{$baseKey} AND {$relatedTable}.{$morphType} = ?{$extraWhere}) AS \"{$alias}\"";
        }

        return "(SELECT COALESCE(CONCAT('[', GROUP_CONCAT({$jsonFunc}({$jsonPairs})), ']'), '[]') " .
            "FROM {$relatedTable} WHERE {$relatedTable}.{$morphId} = {$baseTable}.{$baseKey} AND {$relatedTable}.{$morphType} = ?{$extraWhere}) AS `{$alias}`";
    }

    // =========================================================================
    // COLUMN HELPERS
    // =========================================================================

    /**
     * Resolve requested columns for a model (with translation awareness).
     * Returns [dbColumns, translatableColumns].
     */
    protected function resolveRequestedColumns(Model $model, array $columns): array
    {
        if ($columns === ['*']) {
            $all = $model->getFillable();
            if (empty($all)) {
                $all = $this->getModelColumns($model);
            }
            $all = array_merge([$model->getKeyName()], $all);
            $all = array_unique($all);
        } else {
            $all = $columns;
        }

        if (!TranslationResolver::modelHasTranslations($model) || !$this->locale) {
            return [$all, []];
        }

        $transFields = TranslationResolver::getTranslatableFields($model);
        $dbCols = array_values(array_diff($all, $transFields));
        $transCols = array_values(array_intersect($all, $transFields));

        return [$dbCols, $transCols];
    }

    /**
     * Resolve columns for a model, excluding translatable columns.
     */
    protected function resolveColumns(Model $model, array $columns): array
    {
        if ($columns === ['*']) {
            $all = $model->getFillable();
            if (empty($all)) {
                $all = $this->getModelColumns($model);
            }
            return array_merge([$model->getKeyName()], $all);
        }
        return $columns;
    }

    /**
     * Build JSON pairs for JSON_OBJECT function.
     */
    protected function buildJsonPairs(array $columns, string $table): string
    {
        $pairs = [];
        foreach ($columns as $col) {
            $pairs[] = "'{$col}', {$table}.{$col}";
        }
        return implode(', ', $pairs);
    }

    /**
     * Get foreign key from relation.
     */
    protected function getForeignKey(Relation $relation): string
    {
        if (method_exists($relation, 'getForeignKeyName')) {
            return $relation->getForeignKeyName();
        }
        if (method_exists($relation, 'getQualifiedForeignKeyName')) {
            return last(explode('.', $relation->getQualifiedForeignKeyName()));
        }
        return $this->model->getForeignKey();
    }

    /**
     * Get local key from relation.
     */
    protected function getLocalKey(Relation $relation): string
    {
        if (method_exists($relation, 'getLocalKeyName')) {
            return $relation->getLocalKeyName();
        }
        if (method_exists($relation, 'getQualifiedParentKeyName')) {
            return last(explode('.', $relation->getQualifiedParentKeyName()));
        }
        return $this->model->getKeyName();
    }

    /**
     * Get columns from a model's table.
     */
    protected function getModelColumns(Model $model): array
    {
        try {
            $table = $model->getTable();
            return match ($this->driver) {
                'mysql', 'mariadb' => array_column(DB::select("SHOW COLUMNS FROM {$table}"), 'Field'),
                'pgsql' => array_column(DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ?", [$table]), 'column_name'),
                'sqlite' => array_column(DB::select("PRAGMA table_info({$table})"), 'name'),
                default => array_column(DB::select("SHOW COLUMNS FROM {$table}"), 'Field'),
            };
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // WHERE CLAUSE BUILDER
    // =========================================================================

    /**
     * Build WHERE clause from query wheres.
     * Uses parameterized bindings for safety.
     */
    protected function buildWhereClause(array $wheres, string $table, array $baseBindings = []): string
    {
        $parts = [];
        $bindingIndex = 0;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? 'Basic';
            $boolean = strtoupper($where['boolean'] ?? 'and');
            $prefix = empty($parts) ? '' : " {$boolean} ";

            switch ($type) {
                case 'Basic':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $op = $where['operator'] ?? '=';
                    $parts[] = "{$prefix}{$col} {$op} ?";
                    $this->bindings[] = $baseBindings[$bindingIndex] ?? $where['value'] ?? null;
                    $bindingIndex++;
                    break;

                case 'Null':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $parts[] = "{$prefix}{$col} IS NULL";
                    break;

                case 'NotNull':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $parts[] = "{$prefix}{$col} IS NOT NULL";
                    break;

                case 'In':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $values = $where['values'] ?? [];
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    $parts[] = "{$prefix}{$col} IN ({$placeholders})";
                    foreach ($values as $v) {
                        $this->bindings[] = $v;
                    }
                    break;

                case 'NotIn':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $values = $where['values'] ?? [];
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    $parts[] = "{$prefix}{$col} NOT IN ({$placeholders})";
                    foreach ($values as $v) {
                        $this->bindings[] = $v;
                    }
                    break;

                case 'Between':
                    $col = Str::contains($where['column'] ?? '', '.') ? $where['column'] : "{$table}.{$where['column']}";
                    $vals = $where['values'] ?? [];
                    $not = !empty($where['not']) ? ' NOT' : '';
                    $parts[] = "{$prefix}{$col}{$not} BETWEEN ? AND ?";
                    $this->bindings[] = $vals[0] ?? null;
                    $this->bindings[] = $vals[1] ?? null;
                    break;

                case 'raw':
                    $parts[] = "{$prefix}{$where['sql']}";
                    if (!empty($where['bindings'])) {
                        foreach ($where['bindings'] as $b) {
                            $this->bindings[] = $b;
                        }
                    }
                    break;

                default:
                    // For unsupported types, try basic handling
                    if (isset($where['column'], $where['value'])) {
                        $col = Str::contains($where['column'], '.') ? $where['column'] : "{$table}.{$where['column']}";
                        $op = $where['operator'] ?? '=';
                        $parts[] = "{$prefix}{$col} {$op} ?";
                        $this->bindings[] = $where['value'];
                    }
                    break;
            }
        }

        return implode('', $parts);
    }

    // =========================================================================
    // DATABASE DRIVER HELPERS
    // =========================================================================

    /**
     * Detect database driver.
     */
    protected function detectDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Get JSON function based on driver.
     */
    protected function getJsonFunction(): string
    {
        $configFunc = config('optimized-queries.json_function', 'auto');
        if ($configFunc !== 'auto') return $configFunc;

        return match ($this->driver) {
            'pgsql' => 'json_build_object',
            default => 'JSON_OBJECT',
        };
    }

    /**
     * Get JSON array aggregation function.
     */
    protected function getJsonArrayAgg(): string
    {
        return match ($this->driver) {
            'pgsql' => 'json_agg',
            default => 'JSON_ARRAYAGG',
        };
    }

    /**
     * Check if database supports JSON_ARRAYAGG function.
     */
    protected function checkJsonArrayAggSupport(): bool
    {
        return in_array($this->driver, ['mysql', 'mariadb', 'pgsql']);
    }
}
