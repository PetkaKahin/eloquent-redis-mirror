<?php

namespace PetkaKahin\EloquentRedisMirror\Concerns;

use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

trait ResolvesRedisRelations
{
    /**
     * Find ALL reverse relation names on $parent that point to $child's class.
     * Handles the case where a parent has multiple relations to the same child model
     * (e.g. Project has both categories() HasMany and firstCategory() HasOne to Category).
     *
     * @param Model|class-string<Model> $parent
     * @param Model|class-string<Model> $child
     * @return list<string>
     */
    protected function findAllReverseRelationNames(Model|string $parent, Model|string $child): array
    {
        $parentClass = $parent instanceof Model ? $parent::class : $parent;
        $childClass  = $child instanceof Model ? $child::class : $child;
        $cacheKey    = $parentClass . '|' . $childClass;

        if (array_key_exists($cacheKey, RedisRelationCache::$reverseRelation)) {
            return RedisRelationCache::$reverseRelation[$cacheKey];
        }

        /** @var Model $parentInstance */
        $parentInstance = $parent instanceof Model ? $parent : new $parent;

        if (!$this->usesRedisCache($parentInstance)) {
            return RedisRelationCache::$reverseRelation[$cacheKey] = [];
        }

        /** @var Model&HasRedisCacheInterface $parentInstance */
        $redisRelations = $parentInstance->getRedisRelations();

        /** @var list<string> $found */
        $found = [];

        foreach ($redisRelations as $relationName) {
            if (!method_exists($parentInstance, $relationName)) {
                continue;
            }

            try {
                /** @var Relation<Model, Model, mixed> $relation */
                $relation     = $parentInstance->$relationName();
                $relatedClass = $relation->getRelated()::class;

                if ($relatedClass === $childClass || is_a($childClass, $relatedClass, true)) {
                    $found[] = $relationName;
                }
            } catch (Exception) {
                continue;
            }
        }

        return RedisRelationCache::$reverseRelation[$cacheKey] = $found;
    }

    /**
     * @param Model|class-string $modelOrClass
     */
    protected function usesRedisCache(Model|string $modelOrClass): bool
    {
        $class = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

        if (!isset(RedisRelationCache::$traitCheck[$class])) {
            RedisRelationCache::$traitCheck[$class] = in_array(HasRedisCache::class, class_uses_recursive($class));
        }

        return RedisRelationCache::$traitCheck[$class];
    }

    /**
     * Get the sort score from a model instance.
     * Supports custom score via getRedisSortScore() method or getRedisSortField() method on the model.
     */
    protected function scoreFromModel(Model $model): float
    {
        if (method_exists($model, 'getRedisSortScore')) {
            /** @var float|int $score */
            $score = $model->getRedisSortScore();

            return (float) $score;
        }

        $sortField = $this->getSortField($model);

        if ($sortField !== 'created_at') {
            $value = $model->getAttribute($sortField);

            if ($value === null) {
                return (float) time();
            }

            if (is_numeric($value)) {
                return (float) $value;
            }

            return $this->stringToScore((string) $value);
        }

        $createdAt = $model->getAttribute('created_at');

        return $createdAt instanceof DateTimeInterface
            ? (float) $createdAt->getTimestamp()
            : (float) time();
    }

    /**
     * Get the sort score from raw attributes array (as stored in Redis).
     *
     * @param array<string, mixed> $attributes
     */
    protected function scoreFromAttributes(array $attributes, ?Model $model = null): float
    {
        $sortField = $this->getSortField($model);

        if ($sortField !== 'created_at') {
            if (isset($attributes[$sortField])) {
                /** @var float|int|string $val */
                $val = $attributes[$sortField];

                if (is_numeric($val)) {
                    return (float) $val;
                }

                return $this->stringToScore((string) $val);
            }

            return (float) time();
        }

        if (!isset($attributes['created_at'])) {
            return (float) time();
        }

        $ts = strtotime((string) $attributes['created_at']);

        return $ts !== false ? (float) $ts : (float) time();
    }

    /**
     * Convert a non-numeric string to an order-preserving float score.
     *
     * Uses big-endian packing: score = c0*256^(N-1) + c1*256^(N-2) + ... + c(N-1)
     * This preserves lexicographic ordering: "aaa" < "aab" < "b".
     *
     * Precision limit: float64 mantissa = 52 bits. With base-256 (8 bits/char),
     * we get 6 chars of exact precision (48 bits < 52). The 7th char has partial
     * precision. Strings that share 7+ leading characters may produce equal scores.
     */
    protected function stringToScore(string $value): float
    {
        $maxLen = 7;
        $len = min(strlen($value), $maxLen);
        $score = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $score = $score * 256.0 + ord($value[$i]);
        }

        // Pad remaining positions to ensure consistent scale
        for ($i = $len; $i < $maxLen; $i++) {
            $score *= 256.0;
        }

        return $score;
    }

    /**
     * Determine the sort field for a model.
     */
    protected function getSortField(?Model $model): string
    {
        if ($model !== null && method_exists($model, 'getRedisSortField')) {
            /** @var string $field */
            $field = $model->getRedisSortField();

            return $field;
        }

        return 'created_at';
    }
}
