<?php

namespace PetkaKahin\EloquentRedisMirror\Listeners;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
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
        try {
            match ($event->action) {
                'attached'         => $this->handleAttached($event),
                'detached'         => $this->handleDetached($event),
                'synced', 'toggled' => $this->handleSyncedOrToggled($event),
                'updated'          => $this->handleUpdated($event),
                default            => null,
            };
        } catch (Exception $e) {
            logger()->warning('SyncRedisPivot: Redis sync failed', [
                'action'       => $event->action,
                'parent'       => $event->parent::class,
                'parent_id'    => $event->parent->getKey(),
                'relation'     => $event->relationName,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    protected function handleAttached(RedisPivotChanged $event): void
    {
        $ctx = $this->resolveFullContext($event);
        if ($ctx === null) {
            return;
        }

        $scores      = $this->getScoresForIds($ctx['relatedModel'], $event->ids);
        $parentScore = $this->scoreFromModel($event->parent);

        [$indexEntries, $pivotItems] = $this->buildAttachEntries(
            $event->ids, $ctx['indexKey'], $scores, $parentScore,
            $ctx['parentId'], $ctx['reverseInfo'], $ctx['pivotTable'],
            $ctx['foreignPivotKey'], $ctx['relatedPivotKey'], $event->pivotAttributes,
        );

        $this->repository->executeBatch(
            setItems: $pivotItems,
            addToIndices: $indexEntries,
        );
    }

    protected function handleDetached(RedisPivotChanged $event): void
    {
        $ctx = $this->resolveFullContext($event);
        if ($ctx === null) {
            return;
        }

        [$removeEntries, $pivotKeysToDelete] = $this->buildDetachEntries(
            $event->ids, $ctx['indexKey'], $ctx['parentId'], $ctx['reverseInfo'], $ctx['pivotTable'],
        );

        $this->repository->executeBatch(
            deleteKeys: $pivotKeysToDelete,
            removeFromIndices: $removeEntries,
        );
    }

    /**
     * Handles 'synced' and 'toggled' actions.
     * Convention: pivotAttributes keys = IDs to attach/update, (ids - pivotAttributes keys) = IDs to detach.
     */
    protected function handleSyncedOrToggled(RedisPivotChanged $event): void
    {
        $ctx = $this->resolveFullContext($event);
        if ($ctx === null) {
            return;
        }

        ['relatedModel' => $relatedModel, 'pivotTable' => $pivotTable,
         'foreignPivotKey' => $foreignPivotKey, 'relatedPivotKey' => $relatedPivotKey,
         'parentId' => $parentId, 'indexKey' => $indexKey, 'reverseInfo' => $reverseInfo] = $ctx;

        /** @var list<int|string> $attachedIds */
        $attachedIds = array_keys($event->pivotAttributes);
        /** @var list<int|string> $detachedIds */
        $detachedIds = array_values(array_diff($event->ids, $attachedIds));

        $indexEntries      = [];
        $pivotItems        = [];
        $removeEntries     = [];
        $pivotKeysToDelete = [];

        if (!empty($attachedIds)) {
            $scores      = $this->getScoresForIds($relatedModel, $attachedIds);
            $parentScore = $this->scoreFromModel($event->parent);

            // For IDs that already exist in Redis (updated pivot, not newly attached),
            // merge existing pivot data so that columns like created_at are not lost.
            $mergedPivotAttributes = $event->pivotAttributes;
            /** @var list<string> $existingPivotKeys */
            $existingPivotKeys = [];
            /** @var array<string, int|string> $pivotKeyToAttachedId */
            $pivotKeyToAttachedId = [];
            foreach ($attachedIds as $aid) {
                /** @var int|string $aid */
                $pKey = $pivotTable . ':' . $parentId . ':' . $aid;
                $existingPivotKeys[] = $pKey;
                $pivotKeyToAttachedId[$pKey] = $aid;
            }

            $existingPivots = $this->repository->getMany($existingPivotKeys);
            foreach ($existingPivots as $pKey => $existingData) {
                if ($existingData !== null) {
                    $aid = $pivotKeyToAttachedId[$pKey];
                    /** @var array<string, mixed> $merged */
                    $merged = array_merge($existingData, $mergedPivotAttributes[$aid] ?? []);
                    $mergedPivotAttributes[$aid] = $merged;
                }
            }

            [$indexEntries, $pivotItems] = $this->buildAttachEntries(
                $attachedIds, $indexKey, $scores, $parentScore,
                $parentId, $reverseInfo, $pivotTable,
                $foreignPivotKey, $relatedPivotKey, $mergedPivotAttributes,
            );
        }

        if (!empty($detachedIds)) {
            [$removeEntries, $pivotKeysToDelete] = $this->buildDetachEntries(
                $detachedIds, $indexKey, $parentId, $reverseInfo, $pivotTable,
            );
        }

        $this->repository->executeBatch(
            setItems: $pivotItems,
            deleteKeys: $pivotKeysToDelete,
            addToIndices: $indexEntries,
            removeFromIndices: $removeEntries,
        );
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

        $pivotTable      = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        /** @var int|string $parentId */
        $parentId = $parent->getKey();

        /** @var list<string> $pivotKeys */
        $pivotKeys = [];
        /** @var array<string, int|string> $pivotKeyToId */
        $pivotKeyToId = [];

        foreach ($event->ids as $id) {
            $pivotKey                = $pivotTable . ':' . $parentId . ':' . $id;
            $pivotKeys[]             = $pivotKey;
            $pivotKeyToId[$pivotKey] = $id;
        }

        $existingData = $this->repository->getMany($pivotKeys);

        // For pivot keys absent from Redis fetch their current state from the DB so
        // that existing columns (created_at, custom attributes) are not silently dropped.
        /** @var list<int|string> $missedIds */
        $missedIds = [];
        foreach ($pivotKeys as $pivotKey) {
            if ($existingData[$pivotKey] === null) {
                $missedIds[] = $pivotKeyToId[$pivotKey];
            }
        }

        if (!empty($missedIds)) {
            $dbRows = DB::table($pivotTable)
                ->where($foreignPivotKey, $parentId)
                ->whereIn($relatedPivotKey, $missedIds)
                ->get();

            foreach ($dbRows as $row) {
                /** @var int|string $relId */
                $relId                                                  = $row->{$relatedPivotKey};
                $existingData[$pivotTable . ':' . $parentId . ':' . $relId] = (array) $row;
            }
        }

        /** @var array<string, array<string, mixed>> $toWrite */
        $toWrite = [];
        foreach ($pivotKeys as $pivotKey) {
            $id       = $pivotKeyToId[$pivotKey];
            $existing = $existingData[$pivotKey] ?? [
                $foreignPivotKey => $parentId,
                $relatedPivotKey => $id,
            ];
            /** @var array<string, mixed> $mergedPivot */
            $mergedPivot = array_merge($existing, $event->pivotAttributes[$id] ?? []);
            $toWrite[$pivotKey] = $mergedPivot;
        }

        $this->repository->setMany($toWrite);
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
                    $data = $cached[$prefix . ':' . $id] ?? null;

                    if ($data !== null) {
                        $scores[$id] = $this->scoreFromAttributes($data, $relatedModel);
                    } else {
                        $missedIds[] = $id;
                    }
                }
            } catch (Exception) {
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

            $scores[$id] = $found !== null
                ? $this->scoreFromModel($found)
                : $defaultScore;
        }

        return $scores;
    }

    /**
     * Resolves the common context for pivot handlers (attached/detached/synced/toggled).
     * Returns null if the parent model does not use HasRedisCache.
     *
     * @return array{relatedModel: Model, pivotTable: string, foreignPivotKey: string, relatedPivotKey: string, parentId: int|string, indexKey: string, reverseInfo: array{relation: string, prefix: string}|null}|null
     */
    private function resolveFullContext(RedisPivotChanged $event): ?array
    {
        $parent = $event->parent;

        if (!$this->usesRedisCache($parent)) {
            return null;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $relationName = $event->relationName;

        /** @var BelongsToMany<Model, Model> $relation */
        $relation        = $parent->$relationName();
        $relatedModel    = $relation->getRelated();
        $pivotTable      = $relation->getTable();
        $foreignPivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        /** @var int|string $parentId */
        $parentId    = $parent->getKey();
        $indexKey    = $parent->getRedisIndexKey($relationName);
        $reverseInfo = $this->resolveReverseInfo($relatedModel, $parent);

        return compact('relatedModel', 'pivotTable', 'foreignPivotKey', 'relatedPivotKey', 'parentId', 'indexKey', 'reverseInfo');
    }

    /**
     * Resolves reverse-relation info for the related model if it uses HasRedisCache.
     *
     * @return array{relation: string, prefix: string}|null
     */
    private function resolveReverseInfo(Model $relatedModel, Model $parent): ?array
    {
        if (!$this->usesRedisCache($relatedModel)) {
            return null;
        }

        $reverseRelation = $this->findReverseRelationName($relatedModel, $parent);

        if ($reverseRelation === null) {
            return null;
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        return ['relation' => $reverseRelation, 'prefix' => $relatedModel::getRedisPrefix()];
    }

    /**
     * Builds index entries and pivot items for a list of IDs to attach.
     *
     * @param  list<int|string>                            $ids
     * @param  array<int|string, float>                    $scores
     * @param  array{relation: string, prefix: string}|null $reverseInfo
     * @param  array<int|string, array<string, mixed>>     $pivotAttributes
     * @return array{array<string, array<int|string, float>>, array<string, array<string, mixed>>}
     */
    private function buildAttachEntries(
        array $ids,
        string $indexKey,
        array $scores,
        float $parentScore,
        int|string $parentId,
        ?array $reverseInfo,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        array $pivotAttributes,
    ): array {
        /** @var array<string, array<int|string, float>> $indexEntries */
        $indexEntries = [];
        /** @var array<string, array<string, mixed>> $pivotItems */
        $pivotItems = [];

        foreach ($ids as $id) {
            $indexEntries[$indexKey][$id] = $scores[$id] ?? (float) time();

            if ($reverseInfo !== null) {
                $reverseIndexKey = $reverseInfo['prefix'] . ':' . $id . ':' . $reverseInfo['relation'];
                $indexEntries[$reverseIndexKey][(string) $parentId] = $parentScore;
            }

            $pivotData = array_merge(
                [$foreignPivotKey => $parentId, $relatedPivotKey => $id],
                $pivotAttributes[$id] ?? [],
            );
            $pivotItems[$pivotTable . ':' . $parentId . ':' . $id] = $pivotData;
        }

        return [$indexEntries, $pivotItems];
    }

    /**
     * Builds remove-index entries and pivot keys to delete for a list of IDs to detach.
     *
     * @param  list<int|string>                            $ids
     * @param  array{relation: string, prefix: string}|null $reverseInfo
     * @return array{array<string, list<int|string>>, list<string>}
     */
    private function buildDetachEntries(
        array $ids,
        string $indexKey,
        int|string $parentId,
        ?array $reverseInfo,
        string $pivotTable,
    ): array {
        /** @var array<string, list<int|string>> $removeEntries */
        $removeEntries = [];
        /** @var list<string> $pivotKeysToDelete */
        $pivotKeysToDelete = [];

        foreach ($ids as $id) {
            $removeEntries[$indexKey][] = $id;

            if ($reverseInfo !== null) {
                $reverseIndexKey = $reverseInfo['prefix'] . ':' . $id . ':' . $reverseInfo['relation'];
                $removeEntries[$reverseIndexKey][] = (string) $parentId;
            }

            $pivotKeysToDelete[] = $pivotTable . ':' . $parentId . ':' . $id;
        }

        return [$removeEntries, $pivotKeysToDelete];
    }
}
