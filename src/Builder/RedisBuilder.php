<?php

namespace PetkaKahin\EloquentRedisMirror\Builder;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

/**
 * @extends Builder<Model>
 */
class RedisBuilder extends Builder
{
    protected ?Model $relationParent = null;
    protected ?string $relationName = null;

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
            && $this->modelUsesRedis($this->relationParent)
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
        return app(RedisRepository::class);
    }

    protected function modelUsesRedis(?Model $model = null): bool
    {
        $model = $model ?? $this->getModel();
        return in_array(HasRedisCache::class, class_uses_recursive($model));
    }

    /**
     * @param mixed $id
     * @param list<string> $columns
     */
    public function find($id, $columns = ['*']): Model|\Illuminate\Database\Eloquent\Collection|null
    {
        if (is_array($id)) {
            return $this->findMany(array_values($id), $columns);
        }

        if (!$this->modelUsesRedis()) {
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
        } catch (\Exception) {
            // Redis unavailable — fallback to DB
        }

        if ($result === null) {
            $result = parent::find($id, $columns);

            if ($result instanceof Model) {
                try {
                    $this->repository()->set($key, $result->getAttributes());
                } catch (\Exception) {
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    public function findMany($ids, $columns = ['*'])
    {
        if (empty($ids)) {
            return $this->getModel()->newCollection();
        }

        if (!$this->modelUsesRedis()) {
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
        } catch (\Exception) {
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
                } catch (\Exception) {
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
            $ids = $this->repository()->getRelationIds($indexKey, 0, 0);

            if (empty($ids)) {
                return null;
            }

            return $this->find((int) $ids[0], $columns);
        } catch (\Exception) {
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
            $total = $this->repository()->getRelationCount($indexKey);

            if ($total === 0) {
                return new LengthAwarePaginator([], 0, $perPage, $page, [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]);
            }

            $offset = ($page - 1) * $perPage;
            $ids = $this->repository()->getRelationIds($indexKey, $offset, $offset + $perPage - 1);
            $intIds = array_map('intval', $ids);
            $items = $this->findMany($intIds, $columns);

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        } catch (\Exception) {
            return parent::paginate($perPage, $columns, $pageName, $page);
        }
    }

    /**
     * @param array<int, Model> $models
     * @return array<int, Model>
     */
    public function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (!is_callable($constraints)) {
                continue;
            }
            if ($this->loadRedisRelation($models, $name, $constraints)) {
                unset($this->eagerLoad[$name]);
            }
        }

        if (!empty($this->eagerLoad)) {
            $models = parent::eagerLoadRelations($models);
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

        /** @var \Illuminate\Database\Eloquent\Relations\Relation<Model, Model, mixed> $relation */
        $relation = $firstModel->$directRelation();
        $relatedModel = $relation->getRelated();

        if (!$this->modelUsesRedis($relatedModel)) {
            return false;
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
            if (!$this->modelUsesRedis($model)) {
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
            /** @var array<int, list<string>> $modelRelatedIds */
            $modelRelatedIds = [];

            foreach ($modelIndexKeys as $i => $indexKey) {
                $relatedIds = $repository->getRelationIds($indexKey);
                $modelRelatedIds[$i] = $relatedIds;

                foreach ($relatedIds as $relId) {
                    $allRelatedIds[$relId] = true;
                }
            }

            /** @var array<int, Model&HasRedisCacheInterface> $coldStartModels */
            $coldStartModels = [];
            /** @var array<int, list<string>> $warmModels */
            $warmModels = [];

            foreach ($modelRelatedIds as $i => $relatedIds) {
                if (empty($relatedIds)) {
                    $coldStartModels[$i] = $redisModels[$i];
                } else {
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
                        } catch (\Exception) {
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
                    } catch (\Exception) {
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

            foreach ($coldStartModels as $i => $coldModel) {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $dbRelated */
                $dbRelated = $coldModel->$directRelation()->get();

                if ($dbRelated->isNotEmpty()) {
                    foreach ($dbRelated as $rel) {
                        $relKey = $relatedPrefix . ':' . $rel->getKey();
                        try {
                            $repository->set($relKey, $rel->getAttributes());
                            /** @var DateTimeInterface|null $createdAt */
                            $createdAt = $rel->getAttribute('created_at');
                            $score = $createdAt instanceof DateTimeInterface ? (float) $createdAt->getTimestamp() : (float) time();
                            $repository->addToIndex($modelIndexKeys[$i], (string) $rel->getKey(), $score);

                            // Cache pivot data during cold start for BelongsToMany
                            if ($isBelongsToMany) {
                                /** @var BelongsToMany<Model, Model> $btmRelation */
                                $btmRelation = $relation;
                                $pivotTable = $btmRelation->getTable();
                                $parentId = $coldModel->getKey();

                                if ($rel->relationLoaded('pivot')) {
                                    /** @var Model $pivotModel */
                                    $pivotModel = $rel->getRelation('pivot');
                                    /** @var array<string, mixed> $pivotData */
                                    $pivotData = $pivotModel->getAttributes();
                                    $pivotKey = $pivotTable . ':' . $parentId . ':' . $rel->getKey();
                                    $repository->set($pivotKey, $pivotData);
                                }
                            }
                        } catch (\Exception) {
                            // Redis unavailable
                        }
                    }

                    if ($nested !== null) {
                        $relatedArray = $dbRelated->all();
                        $this->loadRedisRelation($relatedArray, $nested, $constraints);
                        $dbRelated = $relatedModel->newCollection($relatedArray);
                    }

                    $models[$i]->setRelation($directRelation, $dbRelated);
                } else {
                    $models[$i]->setRelation($directRelation, $relatedModel->newCollection());
                }
            }
        } catch (\Exception) {
            foreach ($models as $model) {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $dbRelated */
                $dbRelated = $model->$directRelation()->get();

                if ($nested !== null) {
                    $dbRelated->load($nested);
                }

                $model->setRelation($directRelation, $dbRelated);
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
