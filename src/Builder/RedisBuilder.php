<?php

namespace PetkaKahin\EloquentRedisMirror\Builder;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

/**
 * @extends Builder<Model>
 */
class RedisBuilder extends Builder
{
    use ResolvesRedisRelations;

    protected ?Model $relationParent = null;
    protected ?string $relationName = null;

    protected ?RedisRepository $repositoryInstance = null;

    /** @var Builder<Model>|null */
    protected ?Builder $wrappedBuilder = null;

    /**
     * @param Builder<Model> $builder
     */
    public function setWrappedBuilder(Builder $builder): static
    {
        $this->wrappedBuilder = $builder;

        return $this;
    }

    /**
     * Delegate unknown method calls to the wrapped builder (e.g. SortableBuilder::sorted()).
     * Since __call is only invoked for methods not on this class, method_exists on the
     * wrappedBuilder will only match methods unique to the custom builder class.
     *
     * @param string $method
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($this->wrappedBuilder !== null && method_exists($this->wrappedBuilder, $method)) {
            $result = $this->wrappedBuilder->$method(...$parameters);

            return $result === $this->wrappedBuilder ? $this : $result;
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    public function setRelationContext(Model $parent, string $relationName): static
    {
        $this->relationParent = $parent;
        $this->relationName = $relationName;

        return $this;
    }

    protected function hasRelationContext(): bool
    {
        return $this->relationParent !== null
            && $this->usesRedisCache($this->relationParent)
            && $this->relationName !== null;
    }

    protected function getRelationIndexKey(): ?string
    {
        if (!$this->hasRelationContext()) {
            return null;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $parent = $this->relationParent;

        /** @var string $relationName */
        $relationName = $this->relationName;

        return $parent->getRedisIndexKey($relationName);
    }

    protected function repository(): RedisRepository
    {
        if ($this->repositoryInstance === null) {
            $this->repositoryInstance = app(RedisRepository::class);
        }

        return $this->repositoryInstance;
    }

    /**
     * @param mixed $id
     * @param list<string> $columns
     */
    public function find($id, $columns = ['*']): Model|Collection|null
    {
        if (is_array($id)) {
            return $this->findMany(array_values($id), $columns);
        }

        if (!$this->usesRedisCache($this->getModel())) {
            return parent::find($id, $columns);
        }

        $model = $this->getModel();
        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();
        $key = $prefix . ':' . $id;

        $result = null;

        try {
            $cached = $this->repository()->get($key);

            if ($cached !== null) {
                $result = $this->hydrateModel($cached);
            }
        } catch (Exception) {
            // Redis unavailable — fallback to DB
        }

        if ($result === null) {
            $result = parent::find($id, $columns);

            if ($result instanceof Model) {
                try {
                    $this->repository()->set($key, $result->getAttributes());
                } catch (Exception) {
                    // Redis unavailable — skip caching
                }
            }
        }

        if ($result instanceof Model && !empty($this->eagerLoad)) {
            $models = $this->eagerLoadRelations([$result]);
            $result = $models[0];
        }

        return $result instanceof Model ? $result : null;
    }

    /**
     * @param array<int, mixed> $ids
     * @param list<string> $columns
     * @return Collection<int, Model>
     */
    public function findMany($ids, $columns = ['*']): Collection
    {
        if (empty($ids)) {
            return $this->getModel()->newCollection();
        }

        if (!$this->usesRedisCache($this->getModel())) {
            return parent::findMany($ids, $columns);
        }

        $model = $this->getModel();
        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();

        /** @var list<string> $keys */
        $keys = [];
        /** @var array<string, int|string> $keyToId */
        $keyToId = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;
            $redisKey = $prefix . ':' . $idStr;
            $keys[] = $redisKey;
            $keyToId[$redisKey] = $id;
        }

        /** @var array<int|string, Model> $found */
        $found = [];
        /** @var list<int|string> $missedIds */
        $missedIds = [];

        try {
            $cached = $this->repository()->getMany($keys);

            foreach ($cached as $redisKey => $attrs) {
                $originalId = $keyToId[$redisKey];
                if ($attrs !== null) {
                    $found[$originalId] = $this->hydrateModel($attrs);
                } else {
                    $missedIds[] = $originalId;
                }
            }
        } catch (Exception) {
            /** @var list<int|string> $missedIds */
            $missedIds = array_values($ids);
        }

        if (!empty($missedIds)) {
            $keyName = $model->getKeyName();
            $dbResults = parent::whereIn($keyName, $missedIds)->get($columns);

            /** @var array<string, array<string, mixed>> $toCache */
            $toCache = [];
            foreach ($dbResults as $dbModel) {
                $dbId = $dbModel->getKey();
                $found[$dbId] = $dbModel;
                $toCache[$prefix . ':' . $dbId] = $dbModel->getAttributes();
            }

            if (!empty($toCache)) {
                try {
                    $this->repository()->setMany($toCache);
                } catch (Exception) {
                    // Redis unavailable
                }
            }
        }

        /** @var list<Model> $ordered */
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $ordered[] = $found[$id];
            }
        }

        if (!empty($this->eagerLoad) && !empty($ordered)) {
            $ordered = $this->eagerLoadRelations($ordered);
        }

        return $this->getModel()->newCollection($ordered);
    }

    /**
     * @param list<string> $columns
     */
    public function first($columns = ['*']): ?Model
    {
        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            return parent::first($columns);
        }

        try {
            $ids = $this->repository()->getRelationIdsChecked($indexKey, 0, 0);

            if ($ids === null) {
                return parent::first($columns);
            }

            if (empty($ids)) {
                return null;
            }

            return $this->find($ids[0], $columns);
        } catch (Exception) {
            return parent::first($columns);
        }
    }

    /**
     * @param int|null $perPage
     * @param list<string> $columns
     * @param string $pageName
     * @param int|null $page
     * @param int|\Closure|null $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Model>
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            return parent::paginate($perPage, $columns, $pageName, $page);
        }

        $perPage = $perPage ?: $this->getModel()->getPerPage();
        $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

        try {
            $total = $this->repository()->getRelationCountChecked($indexKey);

            if ($total === null) {
                return parent::paginate($perPage, $columns, $pageName, $page);
            }

            if ($total === 0) {
                return new LengthAwarePaginator([], 0, $perPage, $page, [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]);
            }

            $offset = ($page - 1) * $perPage;
            $ids = $this->repository()->getRelationIds($indexKey, $offset, $offset + $perPage - 1);
            $items = $this->findMany($ids, $columns);

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        } catch (Exception) {
            return parent::paginate($perPage, $columns, $pageName, $page);
        }
    }

    /**
     * @param array<int, Model> $models
     * @return array<int, Model>
     */
    public function eagerLoadRelations(array $models): array
    {
        $nonRedisEagerLoad = $this->eagerLoad;

        foreach ($this->eagerLoad as $name => $constraints) {
            /** @var callable $constraints */
            if ($this->loadRedisRelation($models, $name, $constraints)) {
                unset($nonRedisEagerLoad[$name]);
            }
        }

        if (!empty($nonRedisEagerLoad)) {
            $original = $this->eagerLoad;
            $this->eagerLoad = $nonRedisEagerLoad;
            $models = parent::eagerLoadRelations($models);
            $this->eagerLoad = $original;
        }

        return $models;
    }

    /**
     * @param array<int, Model> $models
     */
    protected function loadRedisRelation(array &$models, string $name, callable $constraints): bool
    {
        $parts = explode('.', $name);
        $directRelation = $parts[0];
        $nested = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : null;

        if (empty($models)) {
            return true;
        }

        $firstModel = $models[0];

        if (!method_exists($firstModel, $directRelation)) {
            return false;
        }

        /** @var Relation<Model, Model, mixed> $relation */
        $relation = $firstModel->$directRelation();
        $relatedModel = $relation->getRelated();

        if (!$this->usesRedisCache($relatedModel)) {
            return false;
        }

        if ($constraints instanceof \Closure) {
            $testQuery = $relatedModel->newQuery();
            $beforeWheres = $testQuery->getQuery()->wheres;
            $beforeOrders = $testQuery->getQuery()->orders;
            $beforeLimit = $testQuery->getQuery()->limit;
            $constraints($testQuery);
            $hasRealConstraints = $testQuery->getQuery()->wheres !== $beforeWheres
                || $testQuery->getQuery()->orders !== $beforeOrders
                || $testQuery->getQuery()->limit !== $beforeLimit;
            if ($hasRealConstraints) {
                return false;
            }
        }

        $repository = $this->repository();
        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();

        $isBelongsToMany = $relation instanceof BelongsToMany;

        /** @var array<int, string> $modelIndexKeys */
        $modelIndexKeys = [];
        /** @var array<int, Model&HasRedisCacheInterface> $redisModels */
        $redisModels = [];
        foreach ($models as $i => $model) {
            if (!$this->usesRedisCache($model)) {
                continue;
            }
            /** @var Model&HasRedisCacheInterface $model */
            $indexKey = $model->getRedisIndexKey($directRelation);
            $modelIndexKeys[$i] = $indexKey;
            $redisModels[$i] = $model;
        }

        if (empty($redisModels)) {
            return true;
        }

        try {
            /** @var array<string, true> $allRelatedIds */
            $allRelatedIds = [];
            /** @var array<int, list<string>|null> $modelRelatedIds */
            $modelRelatedIds = [];

            // Fetch all relation-index IDs in a single pipeline instead of N roundtrips
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
                    // null → index was never warmed, needs cold-start
                    $coldStartModels[$i] = $redisModels[$i];
                } else {
                    // [] or [id,...] → index is warmed (possibly empty)
                    $warmModels[$i] = $relatedIds;
                }
            }

            $uniqueIds = array_keys($allRelatedIds);
            /** @var array<string, Model> $allRelatedModels */
            $allRelatedModels = [];

            if (!empty($uniqueIds)) {
                /** @var list<string> $relatedKeys */
                $relatedKeys = array_values(array_map(
                    static fn (string $id): string => $relatedPrefix . ':' . $id,
                    $uniqueIds,
                ));
                $cachedRelated = $repository->getMany($relatedKeys);

                /** @var list<string> $missedRelatedIds */
                $missedRelatedIds = [];
                foreach ($uniqueIds as $relId) {
                    $relKey = $relatedPrefix . ':' . $relId;
                    $cachedData = $cachedRelated[$relKey] ?? null;
                    if ($cachedData !== null) {
                        $allRelatedModels[$relId] = $relatedModel->newFromBuilder($cachedData);
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
                        $allRelatedModels[$dbKey] = $dbModel;
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
            }

            // Load pivot data for BelongsToMany relations
            /** @var array<string, array<string, mixed>> $allPivotData */
            $allPivotData = [];
            if ($isBelongsToMany) {
                /** @var BelongsToMany<Model, Model> $btmRelation */
                $btmRelation = $relation;
                $pivotTable = $btmRelation->getTable();

                /** @var list<string> $pivotKeys */
                $pivotKeys = [];
                /** @var array<string, string> $pivotKeyMap */
                $pivotKeyMap = [];

                foreach ($warmModels as $i => $relatedIds) {
                    $parentId = $redisModels[$i]->getKey();
                    foreach ($relatedIds as $relId) {
                        $pKey = $pivotTable . ':' . $parentId . ':' . $relId;
                        $pivotKeys[] = $pKey;
                        $pivotKeyMap[$pKey] = $parentId . ':' . $relId;
                    }
                }

                if (!empty($pivotKeys)) {
                    try {
                        $cachedPivots = $repository->getMany($pivotKeys);
                        foreach ($cachedPivots as $pKey => $pData) {
                            if ($pData !== null) {
                                $allPivotData[$pivotKeyMap[$pKey]] = $pData;
                            }
                        }
                    } catch (Exception) {
                        // Redis unavailable — pivot data will be empty
                    }
                }
            }

            foreach ($warmModels as $i => $relatedIds) {
                /** @var list<Model> $orderedRelated */
                $orderedRelated = [];
                $parentId = $redisModels[$i]->getKey();

                foreach ($relatedIds as $relId) {
                    if (isset($allRelatedModels[$relId])) {
                        $relModel = $allRelatedModels[$relId];

                        // Attach pivot data for BelongsToMany
                        if ($isBelongsToMany) {
                            /** @var BelongsToMany<Model, Model> $btmRelation */
                            $btmRelation = $relation;
                            $compositeKey = $parentId . ':' . $relId;
                            $pivotData = $allPivotData[$compositeKey] ?? [
                                $btmRelation->getForeignPivotKeyName() => $parentId,
                                $btmRelation->getRelatedPivotKeyName() => $relId,
                            ];
                            $pivot = $btmRelation->newExistingPivot($pivotData);
                            $relModel = clone $relModel;
                            $relModel->setRelation('pivot', $pivot);
                        }

                        $orderedRelated[] = $relModel;
                    }
                }

                $collection = $relatedModel->newCollection($orderedRelated);

                if ($nested !== null) {
                    $relatedArray = $collection->all();
                    $this->loadRedisRelation($relatedArray, $nested, $constraints);
                    $collection = $relatedModel->newCollection($relatedArray);
                }

                $models[$i]->setRelation($directRelation, $collection);
            }

            // ── Cold-start: single batch query instead of N individual queries ──
            if (!empty($coldStartModels)) {
                // Track all cold-start index keys so we can mark them as warmed after DB load
                /** @var list<string> $coldModelIndexKeys */
                $coldModelIndexKeys = array_values(array_map(
                    static fn (int $i): string => $modelIndexKeys[$i],
                    array_keys($coldStartModels),
                ));

                /** @var array<string, array<string, mixed>> $coldToCache */
                $coldToCache = [];
                /** @var array<string, array<int|string, float>> $coldIndexEntries */
                $coldIndexEntries = [];
                /** @var array<string, array<string, mixed>> $coldPivotToCache */
                $coldPivotToCache = [];

                if ($isBelongsToMany) {
                    /** @var BelongsToMany<Model, Model> $btmRelation */
                    $btmRelation     = $relation;
                    $foreignPivotKey = $btmRelation->getForeignPivotKeyName();
                    $relatedPivotKey = $btmRelation->getRelatedPivotKeyName();

                    $coldParentIds = array_values(array_map(
                        static fn (Model $m): mixed => $m->getKey(),
                        $coldStartModels,
                    ));

                    // One pivot-table query for all cold parents
                    $allPivotRows     = DB::table($pivotTable)->whereIn($foreignPivotKey, $coldParentIds)->get();
                    $uniqueRelatedIds = $allPivotRows->pluck($relatedPivotKey)->unique()->values()->all();

                    $coldRelatedByPk = empty($uniqueRelatedIds)
                        ? $relatedModel->newCollection()
                        : $relatedModel->newQuery()->whereIn($relatedModel->getKeyName(), $uniqueRelatedIds)->get();

                    /** @var \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, mixed>> $pivotByParent */
                    $pivotByParent = $allPivotRows->groupBy($foreignPivotKey);
                    $coldRelatedByPk = $coldRelatedByPk->keyBy($relatedModel->getKeyName());

                    foreach ($coldStartModels as $i => $coldModel) {
                        $parentId  = $coldModel->getKey();
                        $pivotRows = $pivotByParent->get((string) $parentId) ?? collect();

                        /** @var list<Model> $orderedRelated */
                        $orderedRelated = [];

                        foreach ($pivotRows as $pivotRow) {
                            $relId    = $pivotRow->{$relatedPivotKey};
                            $relModel = $coldRelatedByPk[$relId] ?? null;

                            if ($relModel === null) {
                                continue;
                            }

                            /** @var array<string, mixed> $pivotData */
                            $pivotData = (array) $pivotRow;
                            $relModel  = clone $relModel;
                            $relModel->setRelation('pivot', $btmRelation->newExistingPivot($pivotData));
                            $orderedRelated[] = $relModel;

                            $coldToCache[$relatedPrefix . ':' . $relId]                      = $relModel->getAttributes();
                            $coldIndexEntries[$modelIndexKeys[$i]][(string) $relId]          = $this->scoreFromModel($relModel);
                            $coldPivotToCache[$pivotTable . ':' . $parentId . ':' . $relId] = $pivotData;
                        }

                        $dbRelated = $relatedModel->newCollection($orderedRelated);

                        if ($nested !== null) {
                            $relatedArray = $dbRelated->all();
                            $this->loadRedisRelation($relatedArray, $nested, $constraints);
                            $dbRelated = $relatedModel->newCollection($relatedArray);
                        }

                        $models[$i]->setRelation($directRelation, $dbRelated);
                    }
                } else {
                    // HasMany / HasOne: batch via single whereIn on the foreign key
                    /** @var HasOneOrMany<Model, Model, mixed> $hasManyRelation */
                    $hasManyRelation = $relation;
                    $fkName          = $hasManyRelation->getForeignKeyName();
                    $localKeyName    = $hasManyRelation->getLocalKeyName();

                    /** @var array<int, mixed> $coldLocalKeys */
                    $coldLocalKeys = [];
                    foreach ($coldStartModels as $i => $coldModel) {
                        $coldLocalKeys[$i] = $coldModel->getAttribute($localKeyName);
                    }

                    $allColdRelated = $relatedModel->newQuery()
                        ->whereIn($fkName, array_values($coldLocalKeys))
                        ->get();

                    $grouped = $allColdRelated->groupBy($fkName);

                    foreach ($coldStartModels as $i => $coldModel) {
                        /** @var int|string $localKeyValue */
                        $localKeyValue = $coldLocalKeys[$i];
                        /** @var array<int, Model> $groupedModels */
                        $groupedModels = ($grouped->get($localKeyValue) ?? collect())->all();
                        $dbRelated     = $relatedModel->newCollection($groupedModels);

                        foreach ($dbRelated as $rel) {
                            $coldToCache[$relatedPrefix . ':' . $rel->getKey()]             = $rel->getAttributes();
                            $coldIndexEntries[$modelIndexKeys[$i]][(string) $rel->getKey()] = $this->scoreFromModel($rel);
                        }

                        if ($nested !== null) {
                            $relatedArray = $dbRelated->all();
                            $this->loadRedisRelation($relatedArray, $nested, $constraints);
                            $dbRelated = $relatedModel->newCollection($relatedArray);
                        }

                        $models[$i]->setRelation($directRelation, $dbRelated);
                    }
                }

                // Batch-write all cold-start data to Redis in a single pipeline (best-effort)
                try {
                    $repository->executeBatch(
                        setItems: array_replace($coldToCache, $coldPivotToCache),
                        addToIndices: $coldIndexEntries,
                        markWarmed: $coldModelIndexKeys,
                    );
                } catch (Exception) {
                    // Redis unavailable — relations already set from DB, caching is best-effort
                }
            }
        } catch (Exception) {
            // Redis unavailable — fall back to a single batch DB query per relation type
            if ($isBelongsToMany) {
                /** @var BelongsToMany<Model, Model> $btmRelation */
                $btmRelation     = $relation;
                $pivotTable      = $btmRelation->getTable();
                $foreignPivotKey = $btmRelation->getForeignPivotKeyName();
                $relatedPivotKey = $btmRelation->getRelatedPivotKeyName();

                $parentIds = array_values(array_map(static fn (Model $m): mixed => $m->getKey(), $models));

                $allPivotRows     = DB::table($pivotTable)->whereIn($foreignPivotKey, $parentIds)->get();
                $uniqueRelatedIds = $allPivotRows->pluck($relatedPivotKey)->unique()->values()->all();

                $fallbackRelated = empty($uniqueRelatedIds)
                    ? $relatedModel->newCollection()
                    : $relatedModel->newQuery()->whereIn($relatedModel->getKeyName(), $uniqueRelatedIds)->get();
                $fallbackRelated = $fallbackRelated->keyBy($relatedModel->getKeyName());

                $pivotByParent = $allPivotRows->groupBy($foreignPivotKey);

                foreach ($models as $model) {
                    $parentId  = $model->getKey();
                    $pivotRows = $pivotByParent->get((string) $parentId) ?? collect();

                    /** @var list<Model> $orderedRelated */
                    $orderedRelated = [];
                    foreach ($pivotRows as $pivotRow) {
                        $relId    = $pivotRow->{$relatedPivotKey};
                        $relModel = $fallbackRelated[$relId] ?? null;
                        if ($relModel !== null) {
                            $relModel = clone $relModel;
                            $relModel->setRelation('pivot', $btmRelation->newExistingPivot((array) $pivotRow));
                            $orderedRelated[] = $relModel;
                        }
                    }

                    $dbRelated = $relatedModel->newCollection($orderedRelated);
                    if ($nested !== null) {
                        $dbRelated->load($nested);
                    }
                    $model->setRelation($directRelation, $dbRelated);
                }
            } elseif ($relation instanceof HasOneOrMany) {
                /** @var HasOneOrMany<Model, Model, mixed> $hasManyRelation */
                $hasManyRelation = $relation;
                $fkName          = $hasManyRelation->getForeignKeyName();
                $localKeyName    = $hasManyRelation->getLocalKeyName();

                $localKeys = array_values(array_map(
                    static fn (Model $m): mixed => $m->getAttribute($localKeyName),
                    $models,
                ));

                $allFallbackRelated = $relatedModel->newQuery()->whereIn($fkName, $localKeys)->get();
                $grouped            = $allFallbackRelated->groupBy($fkName);

                foreach ($models as $model) {
                    /** @var int|string $localKey */
                    $localKey  = $model->getAttribute($localKeyName);
                    /** @var array<int, Model> $groupedModels */
                    $groupedModels = ($grouped->get($localKey) ?? collect())->all();
                    $dbRelated = $relatedModel->newCollection($groupedModels);
                    if ($nested !== null) {
                        $dbRelated->load($nested);
                    }
                    $model->setRelation($directRelation, $dbRelated);
                }
            } else {
                foreach ($models as $model) {
                    /** @var Collection<int, Model> $dbRelated */
                    $dbRelated = $model->$directRelation()->get();
                    if ($nested !== null) {
                        $dbRelated->load($nested);
                    }
                    $model->setRelation($directRelation, $dbRelated);
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function hydrateModel(array $attributes): Model
    {
        return $this->getModel()->newFromBuilder($attributes);
    }
}
