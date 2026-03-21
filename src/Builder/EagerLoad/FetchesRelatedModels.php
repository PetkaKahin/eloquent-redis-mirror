<?php

namespace PetkaKahin\EloquentRedisMirror\Builder\EagerLoad;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PetkaKahin\EloquentRedisMirror\Concerns\RedisRelationCache;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

trait FetchesRelatedModels
{
    /**
     * Fetch related models by IDs: try Redis first, fallback to DB for misses, cache results.
     *
     * @param list<string> $uniqueIds
     * @param Model&HasRedisCacheInterface $relatedModel
     * @return array<string, Model>
     */
    protected function fetchRelatedModels(
        array $uniqueIds,
        string $relatedPrefix,
        Model $relatedModel,
        RedisRepository $repository,
    ): array {
        /** @var list<string> $relatedKeys */
        $relatedKeys = array_values(array_map(
            static fn (string $id): string => $relatedPrefix . ':' . $id,
            $uniqueIds,
        ));
        $cachedRelated = $repository->getMany($relatedKeys);

        /** @var array<string, Model> $result */
        $result = [];
        /** @var list<string> $missedRelatedIds */
        $missedRelatedIds = [];

        foreach ($uniqueIds as $relId) {
            $relKey = $relatedPrefix . ':' . $relId;
            $cachedData = $cachedRelated[$relKey] ?? null;
            if ($cachedData !== null) {
                $result[$relId] = $relatedModel->newFromBuilder($cachedData);
            } else {
                $missedRelatedIds[] = $relId;
            }
        }

        if (!empty($missedRelatedIds)) {
            $dbModels = $relatedModel->newQuery()
                ->whereIn($relatedModel->getKeyName(), $missedRelatedIds)
                ->get();

            /** @var array<string, array<string, mixed>> $toCache */
            $toCache = [];
            foreach ($dbModels as $dbModel) {
                $dbKey = (string) $dbModel->getKey();
                $result[$dbKey] = $dbModel;
                $toCache[$relatedPrefix . ':' . $dbKey] = $dbModel->getAttributes();
            }

            if (!empty($toCache)) {
                try {
                    $repository->setMany($toCache);
                } catch (Exception) {
                    // Redis unavailable
                }
            }
        }

        return $result;
    }

    /**
     * Fetch related models with pivot data and build an ordered list.
     *
     * Shared between BelongsToManyLoader (eager load) and RedisBelongsToMany::get() (lazy load).
     *
     * @param list<string> $ids
     * @param Model&HasRedisCacheInterface $relatedModel
     * @param BelongsToMany<Model, Model> $relation
     * @return list<Model>
     */
    protected function fetchRelatedWithPivots(
        array $ids,
        string $relatedPrefix,
        Model $relatedModel,
        int|string $parentId,
        string $pivotTable,
        BelongsToMany $relation,
        RedisRepository $repository,
    ): array {
        $allRelatedModels = $this->fetchRelatedModels($ids, $relatedPrefix, $relatedModel, $repository);

        // Fetch pivot data in batch
        /** @var list<string> $pivotKeys */
        $pivotKeys = [];
        /** @var array<string, string> $pivotKeyMap */
        $pivotKeyMap = [];

        foreach ($ids as $relId) {
            $pKey = $pivotTable . ':' . $parentId . ':' . $relId;
            $pivotKeys[] = $pKey;
            $pivotKeyMap[$pKey] = $relId;
        }

        /** @var array<string, array<string, mixed>> $pivotData */
        $pivotData = [];
        if (!empty($pivotKeys)) {
            try {
                $cachedPivots = $repository->getMany($pivotKeys);
                foreach ($cachedPivots as $pKey => $pData) {
                    if ($pData !== null) {
                        $pivotData[$pivotKeyMap[$pKey]] = $pData;
                    }
                }
            } catch (Exception) {
                // Redis unavailable — pivot data will be empty
            }
        }

        // Build ordered list with pivots
        /** @var list<Model> $ordered */
        $ordered = [];
        foreach ($ids as $relId) {
            if (isset($allRelatedModels[$relId])) {
                $model = clone $allRelatedModels[$relId];
                $pData = $pivotData[$relId] ?? [
                    $relation->getForeignPivotKeyName() => $parentId,
                    $relation->getRelatedPivotKeyName() => $relId,
                ];
                $model->setRelation('pivot', $relation->newExistingPivot($pData));
                $ordered[] = $model;
            }
        }

        return $ordered;
    }

    /**
     * Batch-fetch relation IDs from Redis and split models into warm/cold groups.
     *
     * Shared between HasManyLoader and BelongsToManyLoader.
     *
     * @param array<int, string> $modelIndexKeys
     * @param array<int, Model&HasRedisCacheInterface> $redisModels
     * @param Model&HasRedisCacheInterface $relatedModel
     * @return array{
     *     allRelatedModels: array<string, Model>,
     *     warmModels: array<int, list<string>>,
     *     coldStartModels: array<int, Model&HasRedisCacheInterface>,
     * }
     */
    protected function resolveWarmColdSplit(
        array $modelIndexKeys,
        array $redisModels,
        string $relatedPrefix,
        Model $relatedModel,
        RedisRepository $repository,
    ): array {
        /** @var array<string, true> $allRelatedIds */
        $allRelatedIds = [];
        /** @var array<int, list<string>|null> $modelRelatedIds */
        $modelRelatedIds = [];

        $batchIds = $repository->getManyRelationIds(array_values($modelIndexKeys));

        foreach ($modelIndexKeys as $i => $indexKey) {
            $relatedIds = $batchIds[$indexKey] ?? null;
            $modelRelatedIds[$i] = $relatedIds;

            if ($relatedIds !== null) {
                foreach ($relatedIds as $relId) {
                    $allRelatedIds[$relId] = true;
                }
            }
        }

        /** @var array<int, Model&HasRedisCacheInterface> $coldStartModels */
        $coldStartModels = [];
        /** @var array<int, list<string>> $warmModels */
        $warmModels = [];

        foreach ($modelRelatedIds as $i => $relatedIds) {
            if ($relatedIds === null) {
                $coldStartModels[$i] = $redisModels[$i];
            } else {
                $warmModels[$i] = $relatedIds;
            }
        }

        // Fetch all related models from Redis
        $uniqueIds = array_keys($allRelatedIds);
        /** @var array<string, Model> $allRelatedModels */
        $allRelatedModels = [];

        if (!empty($uniqueIds)) {
            $allRelatedModels = $this->fetchRelatedModels($uniqueIds, $relatedPrefix, $relatedModel, $repository);
        }

        return [
            'allRelatedModels' => $allRelatedModels,
            'warmModels' => $warmModels,
            'coldStartModels' => $coldStartModels,
        ];
    }

    /**
     * Load nested relations through RedisBuilder (static — no instance state needed).
     *
     * @param array<int, Model> $relatedArray
     */
    public static function loadNested(array &$relatedArray, string $nested, callable $constraints): void
    {
        if (empty($relatedArray)) {
            return;
        }

        $firstModel = $relatedArray[0];
        if (!in_array(HasRedisCache::class, class_uses_recursive($firstModel::class), true)) {
            return;
        }

        $builder = $firstModel->newQuery();
        if (method_exists($builder, 'eagerLoadRelations')) {
            $builder->with([$nested => $constraints]);
            $relatedArray = $builder->eagerLoadRelations($relatedArray);
        }
    }
}
