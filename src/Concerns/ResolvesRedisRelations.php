<?php

namespace PetkaKahin\EloquentRedisMirror\Concerns;

use Illuminate\Database\Eloquent\Model;
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
        /** @var Model $parentInstance */
        $parentInstance = $parent instanceof Model ? $parent : new $parent;
        $childClass = $child instanceof Model ? $child::class : $child;

        if (!$this->usesRedisCache($parentInstance)) {
            return null;
        }

        /** @var Model&HasRedisCacheInterface $parentInstance */
        $redisRelations = $parentInstance->getRedisRelations();

        foreach ($redisRelations as $relationName) {
            if (!method_exists($parentInstance, $relationName)) {
                continue;
            }

            try {
                /** @var \Illuminate\Database\Eloquent\Relations\Relation<Model, Model, mixed> $relation */
                $relation = $parentInstance->$relationName();
                $relatedClass = $relation->getRelated()::class;

                if ($relatedClass === $childClass || is_a($childClass, $relatedClass, true)) {
                    return $relationName;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param Model|class-string $modelOrClass
     */
    protected function usesRedisCache(Model|string $modelOrClass): bool
    {
        $class = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

        return in_array(HasRedisCache::class, class_uses_recursive($class));
    }
}
