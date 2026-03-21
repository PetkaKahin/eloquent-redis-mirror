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

        $pivotScoreColumn = $ctx['pivotScoreColumn'];

        if ($pivotScoreColumn !== null) {
            $scores = $this->scoresFromPivotAttributes($event->ids, $pivotScoreColumn, $event->pivotAttributes);
        } else {
            $scores = $this->getScoresForIds($ctx['relatedModel'], $event->ids);
        }

        $parentScore = $this->scoreFromModel($event->parent);

        [$indexEntries, $pivotItems] = $this->buildAttachEntries(
            $event->ids, $ctx['indexKey'], $scores, $parentScore,
            $ctx['parentId'], $ctx['reverseInfos'], $ctx['pivotTable'],
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
            $event->ids, $ctx['indexKey'], $ctx['parentId'], $ctx['reverseInfos'], $ctx['pivotTable'],
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
         'parentId' => $parentId, 'indexKey' => $indexKey, 'reverseInfos' => $reverseInfos] = $ctx;

        /** @var list<int|string> $attachedIds */
        $attachedIds = array_keys($event->pivotAttributes);
        /** @var list<int|string> $detachedIds */
        $detachedIds = array_values(array_diff($event->ids, $attachedIds));

        $indexEntries      = [];
        $pivotItems        = [];
        $removeEntries     = [];
        $pivotKeysToDelete = [];

        $pivotScoreColumn = $ctx['pivotScoreColumn'];

        if (!empty($attachedIds)) {
            if ($pivotScoreColumn !== null) {
                $scores = $this->scoresFromPivotAttributes($attachedIds, $pivotScoreColumn, $event->pivotAttributes);
            } else {
                $scores = $this->getScoresForIds($relatedModel, $attachedIds);
            }

            $parentScore = $this->scoreFromModel($event->parent);

            // For IDs that already exist in Redis (updated pivot, not newly attached),
            // merge existing pivot data so that columns like created_at are not lost.
            $existingPivots = $this->fetchExistingPivotData(
                $attachedIds, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentId,
            );

            $mergedPivotAttributes = $event->pivotAttributes;
            foreach ($attachedIds as $aid) {
                /** @var int|string $aid */
                $pKey = $pivotTable . ':' . $parentId . ':' . $aid;
                $existingData = $existingPivots[$pKey] ?? null;
                if ($existingData !== null) {
                    /** @var array<string, mixed> $merged */
                    $merged = array_merge($existingData, $mergedPivotAttributes[$aid] ?? []);
                    $mergedPivotAttributes[$aid] = $merged;
                }
            }

            [$indexEntries, $pivotItems] = $this->buildAttachEntries(
                $attachedIds, $indexKey, $scores, $parentScore,
                $parentId, $reverseInfos, $pivotTable,
                $foreignPivotKey, $relatedPivotKey, $mergedPivotAttributes,
            );
        }

        if (!empty($detachedIds)) {
            [$removeEntries, $pivotKeysToDelete] = $this->buildDetachEntries(
                $detachedIds, $indexKey, $parentId, $reverseInfos, $pivotTable,
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

        $existingData = $this->fetchExistingPivotData(
            $event->ids, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentId,
        );

        /** @var array<string, array<string, mixed>> $toWrite */
        $toWrite = [];
        foreach ($event->ids as $id) {
            $pivotKey = $pivotTable . ':' . $parentId . ':' . $id;
            $existing = $existingData[$pivotKey] ?? [
                $foreignPivotKey => $parentId,
                $relatedPivotKey => $id,
            ];
            /** @var array<string, mixed> $mergedPivot */
            $mergedPivot = array_merge($existing, $event->pivotAttributes[$id] ?? []);
            $toWrite[$pivotKey] = $mergedPivot;
        }

        // When pivot score column is configured, update sorted set scores too
        $pivotScoreColumn = $this->getPivotScoreColumn($parent, $relationName);

        if ($pivotScoreColumn !== null) {
            $indexKey = $parent->getRedisIndexKey($relationName);

            /** @var array<string, array<int|string, float>> $indexEntries */
            $indexEntries = [];
            foreach ($event->ids as $id) {
                $newVal = $event->pivotAttributes[$id][$pivotScoreColumn] ?? null;
                if ($newVal !== null) {
                    $indexEntries[$indexKey][$id] = $this->scoreFromPivotValue($newVal);
                }
            }

            // Also update reverse indices if they use pivot scoring
            if (!empty($indexEntries)) {
                $relatedModel = $relation->getRelated();
                $reverseInfos = $this->resolveReverseInfos($relatedModel, $parent);

                foreach ($event->ids as $id) {
                    $newVal = $event->pivotAttributes[$id][$pivotScoreColumn] ?? null;
                    if ($newVal === null) {
                        continue;
                    }
                    foreach ($reverseInfos as $ri) {
                        if ($ri['pivotScoreColumn'] !== null) {
                            $reverseIndexKey = $ri['prefix'] . ':' . $id . ':' . $ri['relation'];
                            $indexEntries[$reverseIndexKey][(string) $parentId] = $this->scoreFromPivotValue($newVal);
                        }
                    }
                }
            }

            $this->repository->executeBatch(
                setItems: $toWrite,
                addToIndices: $indexEntries,
            );

            return;
        }

        $this->repository->setMany($toWrite);
    }

    /**
     * Fetch existing pivot data from Redis, falling back to DB for misses.
     * This prevents silently dropping columns (created_at, custom attributes)
     * when updating or syncing pivots.
     *
     * @param list<int|string> $ids         Related model IDs
     * @param string           $pivotTable
     * @param string           $foreignPivotKey
     * @param string           $relatedPivotKey
     * @param int|string       $parentId
     * @return array<string, array<string, mixed>|null> pivotRedisKey => data
     */
    private function fetchExistingPivotData(
        array $ids,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        int|string $parentId,
    ): array {
        /** @var list<string> $pivotKeys */
        $pivotKeys = [];
        /** @var array<string, int|string> $pivotKeyToId */
        $pivotKeyToId = [];

        foreach ($ids as $id) {
            $pKey = $pivotTable . ':' . $parentId . ':' . $id;
            $pivotKeys[] = $pKey;
            $pivotKeyToId[$pKey] = $id;
        }

        $existingData = $this->repository->getMany($pivotKeys);

        /** @var list<int|string> $missedIds */
        $missedIds = [];
        foreach ($pivotKeys as $pKey) {
            if ($existingData[$pKey] === null) {
                $missedIds[] = $pivotKeyToId[$pKey];
            }
        }

        if (!empty($missedIds)) {
            $dbRows = DB::table($pivotTable)
                ->where($foreignPivotKey, $parentId)
                ->whereIn($relatedPivotKey, $missedIds)
                ->get();

            foreach ($dbRows as $row) {
                /** @var int|string $relId */
                $relId = $row->{$relatedPivotKey};
                /** @var array<string, mixed> $rowArray */
                $rowArray = (array) $row;
                $existingData[$pivotTable . ':' . $parentId . ':' . $relId] = $rowArray;
            }
        }

        return $existingData;
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
     * @return array{relatedModel: Model, pivotTable: string, foreignPivotKey: string, relatedPivotKey: string, parentId: int|string, indexKey: string, reverseInfos: list<array{relation: string, prefix: string, pivotScoreColumn: string|null}>, pivotScoreColumn: string|null}|null
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
        $parentId         = $parent->getKey();
        $indexKey          = $parent->getRedisIndexKey($relationName);
        $reverseInfos      = $this->resolveReverseInfos($relatedModel, $parent);
        $pivotScoreColumn = $this->getPivotScoreColumn($parent, $relationName);

        return compact('relatedModel', 'pivotTable', 'foreignPivotKey', 'relatedPivotKey', 'parentId', 'indexKey', 'reverseInfos', 'pivotScoreColumn');
    }

    /**
     * Resolves ALL reverse-relation infos for the related model if it uses HasRedisCache.
     *
     * @return list<array{relation: string, prefix: string, pivotScoreColumn: string|null}>
     */
    private function resolveReverseInfos(Model $relatedModel, Model $parent): array
    {
        if (!$this->usesRedisCache($relatedModel)) {
            return [];
        }

        $reverseRelations = $this->findAllReverseRelationNames($relatedModel, $parent);

        if (empty($reverseRelations)) {
            return [];
        }

        /** @var Model&HasRedisCacheInterface $relatedModel */
        $prefix = $relatedModel::getRedisPrefix();

        /** @var list<array{relation: string, prefix: string, pivotScoreColumn: string|null}> $result */
        $result = [];
        foreach ($reverseRelations as $rel) {
            $pivotScoreColumn = $this->getPivotScoreColumn($relatedModel, $rel);
            $result[] = ['relation' => $rel, 'prefix' => $prefix, 'pivotScoreColumn' => $pivotScoreColumn];
        }

        return $result;
    }

    /**
     * Builds index entries and pivot items for a list of IDs to attach.
     *
     * @param  list<int|string>                                                      $ids
     * @param  array<int|string, float>                                              $scores
     * @param  list<array{relation: string, prefix: string, pivotScoreColumn: string|null}>  $reverseInfos
     * @param  array<int|string, array<string, mixed>>                               $pivotAttributes
     * @return array{array<string, array<int|string, float>>, array<string, array<string, mixed>>}
     */
    private function buildAttachEntries(
        array $ids,
        string $indexKey,
        array $scores,
        float $parentScore,
        int|string $parentId,
        array $reverseInfos,
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

            foreach ($reverseInfos as $ri) {
                $reverseIndexKey = $ri['prefix'] . ':' . $id . ':' . $ri['relation'];

                if ($ri['pivotScoreColumn'] !== null) {
                    $pivotVal = $pivotAttributes[$id][$ri['pivotScoreColumn']] ?? null;
                    $indexEntries[$reverseIndexKey][(string) $parentId] = $this->scoreFromPivotValue($pivotVal);
                } else {
                    $indexEntries[$reverseIndexKey][(string) $parentId] = $parentScore;
                }
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
     * @param  list<int|string>                                 $ids
     * @param  list<array{relation: string, prefix: string}>    $reverseInfos
     * @return array{array<string, list<int|string>>, list<string>}
     */
    private function buildDetachEntries(
        array $ids,
        string $indexKey,
        int|string $parentId,
        array $reverseInfos,
        string $pivotTable,
    ): array {
        /** @var array<string, list<int|string>> $removeEntries */
        $removeEntries = [];
        /** @var list<string> $pivotKeysToDelete */
        $pivotKeysToDelete = [];

        foreach ($ids as $id) {
            $removeEntries[$indexKey][] = $id;

            foreach ($reverseInfos as $ri) {
                $reverseIndexKey = $ri['prefix'] . ':' . $id . ':' . $ri['relation'];
                $removeEntries[$reverseIndexKey][] = (string) $parentId;
            }

            $pivotKeysToDelete[] = $pivotTable . ':' . $parentId . ':' . $id;
        }

        return [$removeEntries, $pivotKeysToDelete];
    }
}
