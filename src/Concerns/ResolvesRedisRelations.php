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
     * @param Model|class-string<Model> $parent
     * @param Model|class-string<Model> $child
     */
    protected function findReverseRelationName(Model|string $parent, Model|string $child): ?string
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
            return RedisRelationCache::$reverseRelation[$cacheKey] = null;
        }

        /** @var Model&HasRedisCacheInterface $parentInstance */
        $redisRelations = $parentInstance->getRedisRelations();

        foreach ($redisRelations as $relationName) {
            if (!method_exists($parentInstance, $relationName)) {
                continue;
            }

            try {
                /** @var Relation<Model, Model, mixed> $relation */
                $relation     = $parentInstance->$relationName();
                $relatedClass = $relation->getRelated()::class;

                if ($relatedClass === $childClass || is_a($childClass, $relatedClass, true)) {
                    return RedisRelationCache::$reverseRelation[$cacheKey] = $relationName;
                }
            } catch (Exception) {
                continue;
            }
        }

        return RedisRelationCache::$reverseRelation[$cacheKey] = null;
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
     * Get the sort score from a model instance (uses created_at timestamp).
     */
    protected function scoreFromModel(Model $model): float
    {
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
    protected function scoreFromAttributes(array $attributes): float
    {
        if (!isset($attributes['created_at'])) {
            return (float) time();
        }

        $ts = strtotime((string) $attributes['created_at']);

        return $ts !== false ? (float) $ts : (float) time();
    }
}
