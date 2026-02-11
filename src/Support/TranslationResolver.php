<?php

declare(strict_types=1);

namespace Shammaa\LaravelOptimizedQueries\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Shared translation metadata resolver.
 * Eliminates code duplication between OptimizedQueryBuilder and RelationBuilder.
 * 
 * Cached per model class to avoid repeated reflection/relation calls.
 */
class TranslationResolver
{
    /**
     * Internal cache to avoid repeated lookups per request.
     * @var array<string, array>
     */
    protected static array $metadataCache = [];

    /**
     * Check if a model uses a translation trait.
     */
    public static function modelHasTranslations(Model $model): bool
    {
        $class = get_class($model);

        if (isset(self::$metadataCache[$class])) {
            return !empty(self::$metadataCache[$class]['fields']);
        }

        $traits = class_uses_recursive($model);

        foreach ($traits as $trait) {
            if (str_contains($trait, 'HasTranslations') || str_contains($trait, 'Translatable')) {
                return true;
            }
        }

        return method_exists($model, 'translations');
    }

    /**
     * Get translatable fields from the model.
     */
    public static function getTranslatableFields(Model $model): array
    {
        $meta = self::getMetadata($model);
        return $meta['fields'] ?? [];
    }

    /**
     * Get full translation metadata for a model.
     * Results are cached per model class.
     *
     * @return array{fields: array, table: string|null, foreign_key: string|null}
     */
    public static function getMetadata(Model $model): array
    {
        $class = get_class($model);

        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $result = self::resolveMetadata($model);
        self::$metadataCache[$class] = $result;

        return $result;
    }

    /**
     * Clear the metadata cache.
     * Useful for testing or long-running processes.
     */
    public static function clearCache(): void
    {
        self::$metadataCache = [];
    }

    /**
     * Resolve translation metadata from a model.
     *
     * @return array{fields: array, table: string|null, foreign_key: string|null}
     */
    protected static function resolveMetadata(Model $model): array
    {
        $traits = class_uses_recursive($model);
        $isTranslatable = false;

        foreach ($traits as $trait) {
            if (str_contains($trait, 'HasTranslations') || str_contains($trait, 'Translatable')) {
                $isTranslatable = true;
                break;
            }
        }

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

        // Try to get metadata from the 'translations' relation
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
            // Fallback to guessing
        }

        // Final fallback: guessing
        if (!$table) {
            $baseTable = $model->getTable();
            $singular = Str::singular($baseTable);
            $table = $singular . '_translations';
            $foreignKey = $singular . '_id';
        }

        return [
            'fields' => $fields,
            'table' => $table,
            'foreign_key' => $foreignKey,
        ];
    }
}
