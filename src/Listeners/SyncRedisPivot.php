<?php

namespace PetkaKahin\EloquentRedisMirror\Listeners;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class SyncRedisPivot
{
    use ResolvesRedisRelations;

    public function __construct(
        protected RedisRepository $repository,
    ) {}

    public function handle(RedisPivotChanged $event): void
    {
        match ($event->action) {
            'attached' => $this->handleAttached($event),
            'detached' => $this->handleDetached($event),
            'synced', 'toggled' => $this->handleAttachDetach($event),
            'updated' => $this->handleUpdated($event),
            default => null,
        };
    }

    protected function handleAttached(RedisPivotChanged $event): void
    {
        $parent = $event->parent;

        if (!$this->usesRedisCache($parent)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $event->relationName;

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();
        $relatedModel = $relation->getRelated();

        $indexKey = $parent->getRedisIndexKey($relationName);
        $scores = $this->getScoresForIds($relatedModel, $event->ids);

        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        foreach ($event->ids as $id) {
            $this->repository->addToIndex($indexKey, $id, $scores[$id] ?? (float) time());
            $this->addReverseIndex($relatedModel, $id, $parent);

            $pivotData = array_merge(
                [
                    $foreignPivotKey => $parentId,
                    $relatedPivotKey => $id,
                ],
                $event->pivotAttributes[$id] ?? [],
            );
            $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;
            $this->repository->set($pivotKey, $pivotData);
        }
    }

    protected function handleDetached(RedisPivotChanged $event): void
    {
        $parent = $event->parent;

        if (!$this->usesRedisCache($parent)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $event->relationName;

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();
        $relatedModel = $relation->getRelated();

        $indexKey = $parent->getRedisIndexKey($relationName);
        $pivotTable = $relation->getTable();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        foreach ($event->ids as $id) {
            $this->repository->removeFromIndex($indexKey, $id);
            $this->removeReverseIndex($relatedModel, $id, $parent);

            $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;
            $this->repository->delete($pivotKey);
        }
    }

    protected function handleAttachDetach(RedisPivotChanged $event): void
    {
        $parent = $event->parent;

        if (!$this->usesRedisCache($parent)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $event->relationName;

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();
        $relatedModel = $relation->getRelated();

        $indexKey = $parent->getRedisIndexKey($relationName);
        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        /** @var list<int|string> $attachedIds */
        $attachedIds = array_keys($event->pivotAttributes);

        /** @var list<int|string> $detachedIds */
        $detachedIds = array_values(array_diff($event->ids, $attachedIds));

        if (!empty($attachedIds)) {
            $scores = $this->getScoresForIds($relatedModel, $attachedIds);

            foreach ($attachedIds as $id) {
                $this->repository->addToIndex($indexKey, $id, $scores[$id] ?? (float) time());
                $this->addReverseIndex($relatedModel, $id, $parent);

                $pivotData = array_merge(
                    [
                        $foreignPivotKey => $parentId,
                        $relatedPivotKey => $id,
                    ],
                    $event->pivotAttributes[$id] ?? [],
                );
                $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;
                $this->repository->set($pivotKey, $pivotData);
            }
        }

        foreach ($detachedIds as $id) {
            $this->repository->removeFromIndex($indexKey, $id);
            $this->removeReverseIndex($relatedModel, $id, $parent);

            $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;
            $this->repository->delete($pivotKey);
        }
    }

    protected function handleUpdated(RedisPivotChanged $event): void
    {
        $parent = $event->parent;

        if (!$this->usesRedisCache($parent)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $event->relationName;

        /** @var BelongsToMany<Model, Model> $relation */
        $relation = $parent->$relationName();

        $pivotTable = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        foreach ($event->ids as $id) {
            $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;

            $existing = $this->repository->get($pivotKey) ?? [
                $foreignPivotKey => $parentId,
                $relatedPivotKey => $id,
            ];

            $updatedData = array_merge($existing, $event->pivotAttributes[$id] ?? []);
            $this->repository->set($pivotKey, $updatedData);
        }
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    protected function addReverseIndex(Model $relatedModel, int|string $id, Model $parent): void
    {
        if (!$this->usesRedisCache($relatedModel)) {
            return;
        }

        $reverseRelation = $this->findReverseRelationName($relatedModel, $parent);

        if ($reverseRelation === null) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();
        $reverseIndexKey = $relatedPrefix . ':' . $id . ':' . $reverseRelation;

        /** @var DateTimeInterface|null $createdAt */
        $createdAt = $parent->getAttribute('created_at');
        $score = $createdAt instanceof DateTimeInterface ? (float) $createdAt->getTimestamp() : (float) time();

        $this->repository->addToIndex($reverseIndexKey, (string) $parent->getKey(), $score);
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    protected function removeReverseIndex(Model $relatedModel, int|string $id, Model $parent): void
    {
        if (!$this->usesRedisCache($relatedModel)) {
            return;
        }

        $reverseRelation = $this->findReverseRelationName($relatedModel, $parent);

        if ($reverseRelation === null) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();
        $reverseIndexKey = $relatedPrefix . ':' . $id . ':' . $reverseRelation;

        $this->repository->removeFromIndex($reverseIndexKey, (string) $parent->getKey());
    }

    /**
     * @param list<int|string> $ids
     * @return array<int|string, float>
     */
    protected function getScoresForIds(Model $relatedModel, array $ids): array
    {
        /** @var array<int|string, float> $scores */
        $scores = [];
        $defaultScore = (float) time();

        if (empty($ids)) {
            return $scores;
        }

        // Try Redis cache first to avoid unnecessary DB queries
        if ($this->usesRedisCache($relatedModel)) {
            /** @var Model&HasRedisCacheInterface $relatedModel */
            $prefix = $relatedModel::getRedisPrefix();
            /** @var list<string> $redisKeys */
            $redisKeys = array_map(
                static fn (int|string $id): string => $prefix . ':' . $id,
                $ids,
            );

            /** @var list<int|string> $missedIds */
            $missedIds = [];

            try {
                $cached = $this->repository->getMany($redisKeys);

                foreach ($ids as $id) {
                    $redisKey = $prefix . ':' . $id;
                    $data = $cached[$redisKey] ?? null;

                    if ($data !== null && isset($data['created_at'])) {
                        $ts = strtotime((string) $data['created_at']);
                        $scores[$id] = $ts !== false ? (float) $ts : $defaultScore;
                    } else {
                        $missedIds[] = $id;
                    }
                }
            } catch (\Exception) {
                $missedIds = array_values($ids);
            }

            if (empty($missedIds)) {
                return $scores;
            }

            $ids = $missedIds;
        }

        $models = $relatedModel->newQuery()
            ->whereIn($relatedModel->getKeyName(), $ids)
            ->get();

        $modelsById = $models->keyBy($relatedModel->getKeyName());

        foreach ($ids as $id) {
            /** @var Model|null $found */
            $found = $modelsById[$id] ?? null;

            if ($found !== null) {
                /** @var DateTimeInterface|null $createdAt */
                $createdAt = $found->getAttribute('created_at');
                $scores[$id] = $createdAt instanceof DateTimeInterface
                    ? (float) $createdAt->getTimestamp()
                    : $defaultScore;
            } else {
                $scores[$id] = $defaultScore;
            }
        }

        return $scores;
    }
}
