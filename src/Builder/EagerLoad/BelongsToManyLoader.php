<?php

namespace PetkaKahin\EloquentRedisMirror\Builder\EagerLoad;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class BelongsToManyLoader implements EagerLoadStrategy
{
    use ResolvesRedisRelations;
    use FetchesRelatedModels;

    /**
     * @param Relation<Model, Model, mixed> $relation
     */
    public function supports(Relation $relation): bool
    {
        return $relation instanceof BelongsToMany;
    }

    /**
     * @param array<int, Model> $models
     * @param Relation<Model, Model, mixed> $relation
     */
    public function load(
        array &$models,
        string $relationName,
        Relation $relation,
        ?string $nested,
        callable $constraints,
        RedisRepository $repository,
    ): void {
        /** @var BelongsToMany<Model, Model> $relation */
        $relatedModel = $relation->getRelated();
        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();
        $pivotTable = $relation->getTable();

        /** @var array<int, string> $modelIndexKeys */
        $modelIndexKeys = [];
        /** @var array<int, Model&HasRedisCacheInterface> $redisModels */
        $redisModels = [];
        foreach ($models as $i => $model) {
            if (!$this->usesRedisCache($model)) {
                continue;
            }
            /** @var Model&HasRedisCacheInterface $model */
            $modelIndexKeys[$i] = $model->getRedisIndexKey($relationName);
            $redisModels[$i] = $model;
        }

        if (empty($redisModels)) {
            return;
        }

        try {
            $this->loadFromRedis(
                $models, $redisModels, $modelIndexKeys, $relationName,
                $relation, $relatedModel, $relatedPrefix, $pivotTable, $nested, $constraints, $repository,
            );
        } catch (Exception) {
            $this->loadFallback($models, $relation, $relationName, $relatedModel, $pivotTable, $nested);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, Model&HasRedisCacheInterface> $redisModels
     * @param array<int, string> $modelIndexKeys
     * @param BelongsToMany<Model, Model> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadFromRedis(
        array &$models,
        array $redisModels,
        array $modelIndexKeys,
        string $relationName,
        BelongsToMany $relation,
        Model $relatedModel,
        string $relatedPrefix,
        string $pivotTable,
        ?string $nested,
        callable $constraints,
        RedisRepository $repository,
    ): void {
        $split = $this->resolveWarmColdSplit($modelIndexKeys, $redisModels, $relatedPrefix, $relatedModel, $repository);
        $warmModels = $split['warmModels'];
        $coldStartModels = $split['coldStartModels'];

        // Set relations for warm models using shared pivot-fetching logic.
        // Models are already cached in Redis by resolveWarmColdSplit, so
        // fetchRelatedWithPivots will get cache hits — minimal overhead.
        foreach ($warmModels as $i => $relatedIds) {
            $parentId = $redisModels[$i]->getKey();
            $ordered = $this->fetchRelatedWithPivots(
                $relatedIds, $relatedPrefix, $relatedModel,
                $parentId, $pivotTable, $relation, $repository,
            );

            $collection = $relatedModel->newCollection($ordered);

            if ($nested !== null) {
                $relatedArray = $collection->all();
                self::loadNested($relatedArray, $nested, $constraints);
                $collection = $relatedModel->newCollection($relatedArray);
            }

            $models[$i]->setRelation($relationName, $collection);
        }

        // Cold-start
        if (!empty($coldStartModels)) {
            $this->loadCold(
                $models, $coldStartModels, $modelIndexKeys, $relationName,
                $relation, $relatedModel, $relatedPrefix, $pivotTable, $nested, $constraints, $repository,
            );
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, Model&HasRedisCacheInterface> $coldStartModels
     * @param array<int, string> $modelIndexKeys
     * @param BelongsToMany<Model, Model> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadCold(
        array &$models,
        array $coldStartModels,
        array $modelIndexKeys,
        string $relationName,
        BelongsToMany $relation,
        Model $relatedModel,
        string $relatedPrefix,
        string $pivotTable,
        ?string $nested,
        callable $constraints,
        RedisRepository $repository,
    ): void {
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        /** @var list<string> $coldModelIndexKeys */
        $coldModelIndexKeys = array_values(array_map(
            static fn (int $i): string => $modelIndexKeys[$i],
            array_keys($coldStartModels),
        ));

        $coldParentIds = array_values(array_map(
            static fn (Model $m): mixed => $m->getKey(),
            $coldStartModels,
        ));

        $allPivotRows     = DB::table($pivotTable)->whereIn($foreignPivotKey, $coldParentIds)->get();
        $uniqueRelatedIds = $allPivotRows->pluck($relatedPivotKey)->unique()->values()->all();

        $coldRelatedByPk = empty($uniqueRelatedIds)
            ? $relatedModel->newCollection()
            : $relatedModel->newQuery()->whereIn($relatedModel->getKeyName(), $uniqueRelatedIds)->get();

        // Group by string-cast keys to avoid int/string type mismatch on lookup
        $pivotByParent = $allPivotRows->groupBy(static fn ($row) => (string) $row->{$foreignPivotKey});
        $coldRelatedByPk = $coldRelatedByPk->keyBy(static fn (Model $m) => (string) $m->getKey());

        /** @var array<string, array<string, mixed>> $coldToCache */
        $coldToCache = [];
        /** @var array<string, array<int|string, float>> $coldIndexEntries */
        $coldIndexEntries = [];
        /** @var array<string, array<string, mixed>> $coldPivotToCache */
        $coldPivotToCache = [];

        foreach ($coldStartModels as $i => $coldModel) {
            $parentId  = $coldModel->getKey();
            $pivotRows = $pivotByParent->get((string) $parentId) ?? collect();
            $pivotScoreColumn = $this->getPivotScoreColumn($coldModel, $relationName);

            /** @var list<Model> $orderedRelated */
            $orderedRelated = [];

            foreach ($pivotRows as $pivotRow) {
                $relId    = $pivotRow->{$relatedPivotKey};
                $relModel = $coldRelatedByPk[(string) $relId] ?? null;

                if ($relModel === null) {
                    continue;
                }

                /** @var array<string, mixed> $pivotData */
                $pivotData = (array) $pivotRow;
                $relModel  = clone $relModel;
                $relModel->setRelation('pivot', $relation->newExistingPivot($pivotData));
                $orderedRelated[] = $relModel;

                $coldToCache[$relatedPrefix . ':' . $relId] = $relModel->getAttributes();
                if ($pivotScoreColumn !== null) {
                    $coldIndexEntries[$modelIndexKeys[$i]][(string) $relId] = $this->scoreFromPivotValue($pivotData[$pivotScoreColumn] ?? null);
                } else {
                    $coldIndexEntries[$modelIndexKeys[$i]][(string) $relId] = $this->scoreFromModel($relModel);
                }
                $coldPivotToCache[$pivotTable . ':' . $parentId . ':' . $relId] = $pivotData;
            }

            $dbRelated = $relatedModel->newCollection($orderedRelated);

            if ($nested !== null) {
                $relatedArray = $dbRelated->all();
                self::loadNested($relatedArray, $nested, $constraints);
                $dbRelated = $relatedModel->newCollection($relatedArray);
            }

            $models[$i]->setRelation($relationName, $dbRelated);
        }

        try {
            $repository->executeBatch(
                setItems: array_replace($coldToCache, $coldPivotToCache),
                addToIndices: $coldIndexEntries,
                markWarmed: $coldModelIndexKeys,
            );
        } catch (Exception) {
            // Redis unavailable
        }
    }

    /**
     * Pure DB fallback when Redis is completely unavailable.
     *
     * @param array<int, Model> $models
     * @param BelongsToMany<Model, Model> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadFallback(
        array &$models,
        BelongsToMany $relation,
        string $relationName,
        Model $relatedModel,
        string $pivotTable,
        ?string $nested,
    ): void {
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        $parentIds = array_values(array_map(static fn (Model $m): mixed => $m->getKey(), $models));

        $allPivotRows     = DB::table($pivotTable)->whereIn($foreignPivotKey, $parentIds)->get();
        $uniqueRelatedIds = $allPivotRows->pluck($relatedPivotKey)->unique()->values()->all();

        $fallbackRelated = empty($uniqueRelatedIds)
            ? $relatedModel->newCollection()
            : $relatedModel->newQuery()->whereIn($relatedModel->getKeyName(), $uniqueRelatedIds)->get();
        $fallbackRelated = $fallbackRelated->keyBy(static fn (Model $m) => (string) $m->getKey());

        $pivotByParent = $allPivotRows->groupBy(static fn ($row) => (string) $row->{$foreignPivotKey});

        foreach ($models as $model) {
            $parentId  = $model->getKey();
            $pivotRows = $pivotByParent->get((string) $parentId) ?? collect();

            /** @var list<Model> $orderedRelated */
            $orderedRelated = [];
            foreach ($pivotRows as $pivotRow) {
                $relId    = $pivotRow->{$relatedPivotKey};
                $relModel = $fallbackRelated[(string) $relId] ?? null;
                if ($relModel !== null) {
                    $relModel = clone $relModel;
                    $relModel->setRelation('pivot', $relation->newExistingPivot((array) $pivotRow));
                    $orderedRelated[] = $relModel;
                }
            }

            $dbRelated = $relatedModel->newCollection($orderedRelated);
            if ($nested !== null) {
                $dbRelated->load($nested);
            }
            $model->setRelation($relationName, $dbRelated);
        }
    }
}
