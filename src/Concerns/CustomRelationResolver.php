<?php

namespace PetkaKahin\EloquentRedisMirror\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\FetchesRelatedModels;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class CustomRelationResolver
{
    use FetchesRelatedModels;
    use ResolvesRedisRelations;

    protected RedisRepository $repository;

    public function __construct(RedisRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Try to resolve a custom relation from Redis.
     *
     * @param Model&HasRedisCacheInterface $parent
     * @return Collection<int, Model>|Model|null|false  false = Redis miss (cold start), caller should fall through to SQL
     */
    public function resolveFromRedis(Model $parent, string $relationName, string $type): Collection|Model|null|false
    {
        try {
            return match ($type) {
                'belongsToMany' => $this->resolveBelongsToMany($parent, $relationName),
                'hasMany' => $this->resolveHasMany($parent, $relationName),
                'hasOne' => $this->resolveHasOne($parent, $relationName),
                default => false,
            };
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     * @return Collection<int, Model>|false
     */
    private function resolveBelongsToMany(Model $parent, string $relationName): Collection|false
    {
        $indexKey = $parent->getRedisIndexKey($relationName);
        $ids = $this->repository->getRelationIdsChecked($indexKey);

        if ($ids === null) {
            return false;
        }

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();
        /** @var Model $relatedModel */
        $relatedModel = $relation->getRelated();

        if (!$this->usesRedisCache($relatedModel)) {
            return false;
        }

        if (empty($ids)) {
            /** @var Collection<int, Model> */
            return $relatedModel->newCollection();
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();
        $pivotTable = $relation->getTable();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        $ordered = $this->fetchRelatedWithPivots(
            $ids, $relatedPrefix, $relatedModel,
            $parentId, $pivotTable, $relation, $this->repository,
        );

        /** @var Collection<int, Model> */
        return $relatedModel->newCollection($ordered);
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     * @return Collection<int, Model>|false
     */
    private function resolveHasMany(Model $parent, string $relationName): Collection|false
    {
        $indexKey = $parent->getRedisIndexKey($relationName);
        $ids = $this->repository->getRelationIdsChecked($indexKey);

        if ($ids === null) {
            return false;
        }

        /** @var Relation<Model, Model, mixed> $relation */
        $relation = $parent->$relationName();
        /** @var Model $relatedModel */
        $relatedModel = $relation->getRelated();

        if (!$this->usesRedisCache($relatedModel)) {
            return false;
        }

        if (empty($ids)) {
            /** @var Collection<int, Model> */
            return $relatedModel->newCollection();
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();

        $allRelated = $this->fetchRelatedModels($ids, $relatedPrefix, $relatedModel, $this->repository);

        /** @var list<Model> $ordered */
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($allRelated[$id])) {
                $ordered[] = $allRelated[$id];
            }
        }

        /** @var Collection<int, Model> */
        return $relatedModel->newCollection($ordered);
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    private function resolveHasOne(Model $parent, string $relationName): Model|null|false
    {
        $result = $this->resolveHasMany($parent, $relationName);

        if ($result === false) {
            return false;
        }

        return $result->first();
    }

    /**
     * Warm Redis after SQL fallback for a custom relation.
     * Caches models + builds the sorted set index + marks as warmed.
     *
     * @param Model&HasRedisCacheInterface $parent
     */
    public function warmFromResult(Model $parent, string $relationName, string $type, mixed $result): void
    {
        try {
            match ($type) {
                'belongsToMany' => $this->warmBelongsToMany($parent, $relationName, $result),
                'hasMany' => $this->warmHasMany($parent, $relationName, $result),
                'hasOne' => $this->warmHasOne($parent, $relationName, $result),
                default => null,
            };
        } catch (Exception) {
            // Redis unavailable
        }
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     * @param Collection<int, Model> $result
     */
    private function warmBelongsToMany(Model $parent, string $relationName, Collection $result): void
    {
        $indexKey = $parent->getRedisIndexKey($relationName);

        if ($result->isEmpty()) {
            $this->repository->executeBatch(markWarmed: [$indexKey]);

            return;
        }

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();
        $pivotTable = $relation->getTable();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();
        /** @var Model $firstRelated */
        $firstRelated = $result->first();

        if (!$this->usesRedisCache($firstRelated)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $firstRelated */
        $prefix = $firstRelated::getRedisPrefix();
        $pivotScoreColumn = $this->getPivotScoreColumn($parent, $relationName);

        /** @var array<string, array<string, mixed>> $toCache */
        $toCache = [];
        /** @var array<int|string, float> $indexEntries */
        $indexEntries = [];

        foreach ($result as $item) {
            $toCache[$prefix . ':' . $item->getKey()] = $item->getAttributes();

            if ($item->relationLoaded('pivot')) {
                /** @var array<string, mixed> $pivotData */
                $pivotData = $item->pivot->getAttributes();
                $toCache[$pivotTable . ':' . $parentId . ':' . $item->getKey()] = $pivotData;

                if ($pivotScoreColumn !== null) {
                    $indexEntries[(string) $item->getKey()] = $this->scoreFromPivotValue($pivotData[$pivotScoreColumn] ?? null);
                } else {
                    $indexEntries[(string) $item->getKey()] = $this->scoreFromModel($item);
                }
            } else {
                $indexEntries[(string) $item->getKey()] = $this->scoreFromModel($item);
            }
        }

        $this->repository->executeBatch(
            setItems: $toCache,
            addToIndices: [$indexKey => $indexEntries],
            markWarmed: [$indexKey],
        );
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     * @param Collection<int, Model> $result
     */
    private function warmHasMany(Model $parent, string $relationName, Collection $result): void
    {
        $indexKey = $parent->getRedisIndexKey($relationName);

        if ($result->isEmpty()) {
            $this->repository->executeBatch(markWarmed: [$indexKey]);

            return;
        }

        /** @var Model $firstRelated */
        $firstRelated = $result->first();

        if (!$this->usesRedisCache($firstRelated)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $firstRelated */
        $prefix = $firstRelated::getRedisPrefix();

        /** @var array<string, array<string, mixed>> $toCache */
        $toCache = [];
        /** @var array<int|string, float> $indexEntries */
        $indexEntries = [];

        foreach ($result as $item) {
            $toCache[$prefix . ':' . $item->getKey()] = $item->getAttributes();
            $indexEntries[(string) $item->getKey()] = $this->scoreFromModel($item);
        }

        $this->repository->executeBatch(
            setItems: $toCache,
            addToIndices: [$indexKey => $indexEntries],
            markWarmed: [$indexKey],
        );
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    private function warmHasOne(Model $parent, string $relationName, mixed $result): void
    {
        $indexKey = $parent->getRedisIndexKey($relationName);

        if ($result === null) {
            $this->repository->executeBatch(markWarmed: [$indexKey]);

            return;
        }

        if (!$result instanceof Model || !$this->usesRedisCache($result)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $result */
        $prefix = $result::getRedisPrefix();

        $this->repository->executeBatch(
            setItems: [$prefix . ':' . $result->getKey() => $result->getAttributes()],
            addToIndices: [$indexKey => [(string) $result->getKey() => $this->scoreFromModel($result)]],
            markWarmed: [$indexKey],
        );
    }
}
