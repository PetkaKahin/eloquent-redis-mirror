<?php

namespace PetkaKahin\EloquentRedisMirror\Relations;

use Exception;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\FetchesRelatedModels;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

/**
 * @extends BelongsToMany<Model, Model>
 */
class RedisBelongsToMany extends BelongsToMany
{
    use FetchesRelatedModels;
    use ResolvesRedisRelations;

    protected ?RedisRepository $repositoryInstance = null;

    protected function repository(): RedisRepository
    {
        return $this->repositoryInstance ??= app(RedisRepository::class);
    }

    /**
     * @param list<string> $columns
     * @return Collection<int, Model>
     */
    public function get($columns = ['*']): Collection
    {
        $parent = $this->getParent();

        if (!$this->usesRedisCache($parent)) {
            return parent::get($columns);
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $this->getRelationName();

        if (!in_array($relationName, $parent->getRedisRelations(), true)) {
            return parent::get($columns);
        }

        $relatedModel = $this->getRelated();

        if (!$this->usesRedisCache($relatedModel)) {
            return parent::get($columns);
        }

        $baseQuery = $this->getQuery()->getQuery();

        if ($baseQuery->limit !== null || $baseQuery->offset !== null) {
            return parent::get($columns);
        }

        // BelongsToMany base adds exactly 1 where (FK constraint on pivot).
        // Extra wheres mean user-added constraints that Redis can't evaluate.
        if (count($baseQuery->wheres) > 1 || !empty($baseQuery->groups) || $baseQuery->distinct) {
            return parent::get($columns);
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $indexKey = $parent->getRedisIndexKey($relationName);
        $repository = $this->repository();

        try {
            $ids = $repository->getRelationIdsChecked($indexKey);

            if ($ids === null) {
                return parent::get($columns);
            }

            if (empty($ids)) {
                return $relatedModel->newCollection();
            }

            $relatedPrefix = $relatedModel::getRedisPrefix();
            $pivotTable = $this->getTable();
            /** @var int|string $parentId */
            $parentId = $parent->getKey();

            $ordered = $this->fetchRelatedWithPivots(
                $ids, $relatedPrefix, $relatedModel,
                $parentId, $pivotTable, $this, $repository,
            );

            return $relatedModel->newCollection($ordered);
        } catch (Exception) {
            return parent::get($columns);
        }
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $attributes
     */
    public function attach($id, array $attributes = [], $touch = true): void
    {
        parent::attach($id, $attributes, $touch);

        $ids = $this->extractIds($id);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($ids as $relatedId) {
            // Per-ID attributes from associative array like [$id => ['role' => 'x']]
            if (is_array($id) && isset($id[$relatedId]) && is_array($id[$relatedId])) {
                $pivotAttributes[$relatedId] = array_merge($attributes, $id[$relatedId]);
            } else {
                $pivotAttributes[$relatedId] = $attributes;
            }
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'attached',
            $ids,
            $pivotAttributes,
        ));
    }

    /**
     * @param mixed $ids
     */
    public function detach($ids = null, $touch = true): int
    {
        if ($ids === null) {
            /** @var list<int|string> $detachIds */
            $detachIds = $this->allRelatedIds()->toArray();
        } else {
            /** @var list<int|string> $detachIds */
            $detachIds = $this->extractIds($ids);
        }

        $result = parent::detach($ids, $touch);

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'detached',
            $detachIds,
        ));

        return $result;
    }

    /**
     * @param Arrayable<array-key, mixed>|array<array-key, mixed> $ids
     * @return array{attached: list<mixed>, detached: list<mixed>, updated: list<mixed>}
     */
    public function sync($ids, $detaching = true): array
    {
        $result = parent::sync($ids, $detaching);

        /** @var list<int|string> $attached */
        $attached = array_values($result['attached']);
        /** @var list<int|string> $detached */
        $detached = array_values($result['detached']);
        /** @var list<int|string> $updated */
        $updated = array_values($result['updated']);

        // allIds = attached + detached + updated (updated pivot must also be synced to Redis)
        /** @var list<int|string> $allIds */
        $allIds = array_merge($attached, $detached, $updated);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        // For attached and updated IDs, extract pivot attributes from the input
        foreach (array_merge($attached, $updated) as $id) {
            if (is_array($ids) && isset($ids[$id]) && is_array($ids[$id])) {
                $pivotAttributes[$id] = $ids[$id];
            } else {
                $pivotAttributes[$id] = [];
            }
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'synced',
            $allIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @param Arrayable<array-key, mixed>|array<array-key, mixed> $ids
     * @return array{attached: list<mixed>, detached: list<mixed>}
     */
    public function toggle($ids, $touch = true): array
    {
        $result = parent::toggle($ids, $touch);

        /** @var list<int|string> $attached */
        $attached = array_values($result['attached']);
        /** @var list<int|string> $detached */
        $detached = array_values($result['detached']);

        /** @var list<int|string> $allIds */
        $allIds = array_merge($attached, $detached);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($attached as $attachedId) {
            $pivotAttributes[$attachedId] = [];
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'toggled',
            $allIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        /** @var list<int|string> $eventIds */
        $eventIds = array_values((array) $id);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($eventIds as $eid) {
            $pivotAttributes[$eid] = $attributes;
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'updated',
            $eventIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @return list<int|string>
     */
    protected function extractIds(mixed $id): array
    {
        if ($id instanceof Model) {
            /** @var int|string $key */
            $key = $id->getKey();
            return [$key];
        }

        if (is_array($id)) {
            /** @var list<int|string> $ids */
            $ids = [];
            /**
             * @var int|string $key
             * @var mixed $value
             */
            foreach ($id as $key => $value) {
                if (is_array($value)) {
                    $ids[] = $key;
                } elseif (is_numeric($key)) {
                    /** @var int|string $value */
                    $ids[] = $value;
                } else {
                    $ids[] = $key;
                }
            }
            return $ids;
        }

        /** @var int|string $id */
        return [$id];
    }
}
