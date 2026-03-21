<?php

namespace PetkaKahin\EloquentRedisMirror\Listeners;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use JsonException;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class SyncRedisHash
{
    use ResolvesRedisRelations;

    /** @var array<class-string, array<string, array{foreignKey: string, parentClass: class-string<Model>}>> */
    protected static array $belongsToCache = [];

    /**
     * Reset static caches. Call in test teardown to prevent cross-test pollution.
     */
    public static function resetCache(): void
    {
        static::$belongsToCache = [];
    }

    public function __construct(
        protected RedisRepository $repository,
    ) {}

    public function handle(RedisModelChanged $event): void
    {
        try {
            match ($event->action) {
                'created', 'restored' => $this->handleCreated($event->model),
                'updated'             => $this->handleUpdated($event->model, $event->dirty),
                'deleted'             => $this->handleDeleted($event->model),
                default               => null,
            };
        } catch (Exception $e) {
            logger()->warning('SyncRedisHash: Redis sync failed', [
                'action' => $event->action,
                'model'  => $event->model::class,
                'id'     => $event->model->getKey(),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws JsonException
     */
    protected function handleCreated(Model $model): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $this->repository->executeBatch(
            setItems: [$model->getRedisKey() => $model->getAttributes()],
            addToIndices: $this->buildParentIndexEntries($model),
        );
    }

    /**
     * @param list<string> $dirty
     * @throws JsonException
     */
    protected function handleUpdated(Model $model, array $dirty): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $setItems = [$model->getRedisKey() => $model->getAttributes()];

        $infos = $this->resolveParentIndexInfos($model);

        if (empty($infos)) {
            $this->repository->executeBatch(setItems: $setItems);

            return;
        }

        $score    = $this->scoreFromModel($model);
        $modelKey = (string) $model->getKey();

        /** @var array<string, array<int|string, float>> $addEntries */
        $addEntries = [];
        /** @var array<string, list<int|string>> $removeEntries */
        $removeEntries = [];

        $scoreDirty = method_exists($model, 'getRedisSortScore')
            || in_array($this->getSortField($model), $dirty, true);

        foreach ($infos as $info) {
            $fk = $info['fk'];

            if (in_array($fk, $dirty, true)) {
                $oldFk = $model->getOriginal($fk);
                $newFk = $model->getAttribute($fk);

                if ($oldFk !== null) {
                    $oldIndexKey                   = $info['parentPrefix'] . ':' . $oldFk . ':' . $info['reverseRelation'];
                    $removeEntries[$oldIndexKey][] = $modelKey;
                }

                if ($newFk !== null) {
                    $newIndexKey                        = $info['parentPrefix'] . ':' . $newFk . ':' . $info['reverseRelation'];
                    $addEntries[$newIndexKey][$modelKey] = $score;
                }
            } elseif ($scoreDirty) {
                $parentId = $model->getAttribute($fk);

                if ($parentId !== null) {
                    $indexKey                        = $info['parentPrefix'] . ':' . $parentId . ':' . $info['reverseRelation'];
                    $addEntries[$indexKey][$modelKey] = $score;
                }
            }
        }

        $this->repository->executeBatch(
            setItems: $setItems,
            addToIndices: $addEntries,
            removeFromIndices: $removeEntries,
        );
    }

    protected function handleDeleted(Model $model): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $deleteKeys = [$model->getRedisKey()];

        /** @var array<string, list<int|string>> $removeEntries */
        $removeEntries = [];

        $infos = $this->resolveParentIndexInfos($model);

        if (!empty($infos)) {
            $modelKey = (string) $model->getKey();

            foreach ($infos as $info) {
                $parentId = $model->getAttribute($info['fk']);

                if ($parentId !== null) {
                    $indexKey                   = $info['parentPrefix'] . ':' . $parentId . ':' . $info['reverseRelation'];
                    $removeEntries[$indexKey][] = $modelKey;
                }
            }
        }

        // Collect child index keys and identify BelongsToMany relations for batch cleanup
        /** @var array<string, array{pivotTable: string, relInstance: BelongsToMany<Model, Model>}> $btmRelations */
        $btmRelations = [];
        /** @var list<string> $btmIndexKeys */
        $btmIndexKeys = [];

        foreach ($model->getRedisRelations() as $relation) {
            $indexKey     = $model->getRedisIndexKey($relation);
            $deleteKeys[] = $indexKey;
            $deleteKeys[] = $indexKey . ':warmed';

            if (!method_exists($model, $relation)) {
                continue;
            }

            try {
                $relInstance = $model->$relation();
            } catch (Exception) {
                continue;
            }

            if ($relInstance instanceof BelongsToMany) {
                $btmRelations[$indexKey] = [
                    'pivotTable'  => $relInstance->getTable(),
                    'relInstance' => $relInstance,
                ];
                $btmIndexKeys[] = $indexKey;
            }
        }

        // Batch-fetch all BTM member IDs in a single pipeline call
        if (!empty($btmIndexKeys)) {
            $batchMemberIds = $this->repository->getManyRelationIds($btmIndexKeys);

            foreach ($btmRelations as $indexKey => $btmInfo) {
                $memberIds = $batchMemberIds[$indexKey] ?? [];

                // If Redis index was never warmed, fall back to DB pivot table
                if (empty($memberIds) && !isset($batchMemberIds[$indexKey])) {
                    $relInstance = $btmInfo['relInstance'];
                    $foreignPivotKey = $relInstance->getForeignPivotKeyName();
                    $relatedPivotKey = $relInstance->getRelatedPivotKeyName();
                    try {
                        $memberIds = DB::table($btmInfo['pivotTable'])
                            ->where($foreignPivotKey, $model->getKey())
                            ->pluck($relatedPivotKey)
                            ->map(static fn ($id): string => (string) $id)
                            ->all();
                    } catch (Exception) {
                        // DB unavailable — skip cleanup for this relation
                    }
                }

                if (empty($memberIds)) {
                    continue;
                }

                $pivotTable   = $btmInfo['pivotTable'];
                $relInstance   = $btmInfo['relInstance'];
                $relatedModel = $relInstance->getRelated();

                // Pre-resolve reverse relations once per BTM relation (not per member)
                /** @var list<array{prefix: string, relation: string}> $reverseInfos */
                $reverseInfos = [];
                if ($this->usesRedisCache($relatedModel)) {
                    /** @var Model&HasRedisCacheInterface $relatedModel */
                    $reverseRelations = $this->findAllReverseRelationNames($relatedModel, $model);
                    $relatedPrefix = $relatedModel::getRedisPrefix();
                    foreach ($reverseRelations as $reverseRelation) {
                        $reverseInfos[] = ['prefix' => $relatedPrefix, 'relation' => $reverseRelation];
                    }
                }

                foreach ($memberIds as $memberId) {
                    $deleteKeys[] = $pivotTable . ':' . $model->getKey() . ':' . $memberId;

                    foreach ($reverseInfos as $ri) {
                        $reverseIndexKey = $ri['prefix'] . ':' . $memberId . ':' . $ri['relation'];
                        $removeEntries[$reverseIndexKey][] = (string) $model->getKey();
                    }
                }
            }
        }

        $this->repository->executeBatch(
            deleteKeys: $deleteKeys,
            removeFromIndices: $removeEntries,
        );
    }

    /**
     * Build index entries for adding a model to its parent indices.
     *
     * @param Model&HasRedisCacheInterface $model
     * @return array<string, array<int|string, float>>
     */
    protected function buildParentIndexEntries(Model $model): array
    {
        $infos = $this->resolveParentIndexInfos($model);

        if (empty($infos)) {
            return [];
        }

        $score    = $this->scoreFromModel($model);
        $modelKey = (string) $model->getKey();

        /** @var array<string, array<int|string, float>> $indexEntries */
        $indexEntries = [];

        foreach ($infos as $info) {
            $parentId = $model->getAttribute($info['fk']);

            if ($parentId !== null) {
                $indexKey                           = $info['parentPrefix'] . ':' . $parentId . ':' . $info['reverseRelation'];
                $indexEntries[$indexKey][$modelKey] = $score;
            }
        }

        return $indexEntries;
    }

    /**
     * Resolves all valid parent index metadata for a given model.
     * Shared by handleCreated, handleUpdated, and handleDeleted to eliminate
     * the repeated "iterate BelongsTo → check trait → find reverse relation" pattern.
     *
     * @param Model&HasRedisCacheInterface $model
     * @return list<array{fk: string, parentPrefix: string, reverseRelation: string}>
     */
    protected function resolveParentIndexInfos(Model $model): array
    {
        $parentRelations = $this->getBelongsToRelations($model);
        $infos           = [];

        foreach ($parentRelations as $info) {
            $parentClass = $info['parentClass'];

            if (!$this->usesRedisCache($parentClass)) {
                continue;
            }

            $reverseRelations = $this->findAllReverseRelationNames($parentClass, $model);

            if (empty($reverseRelations)) {
                continue;
            }

            /** @var class-string<Model&HasRedisCacheInterface> $parentClass */
            $parentPrefix = $parentClass::getRedisPrefix();
            foreach ($reverseRelations as $reverseRelation) {
                $infos[] = [
                    'fk'              => $info['foreignKey'],
                    'parentPrefix'    => $parentPrefix,
                    'reverseRelation' => $reverseRelation,
                ];
            }
        }

        return $infos;
    }

    /**
     * @return array<string, array{foreignKey: string, parentClass: class-string<Model>}>
     */
    protected function getBelongsToRelations(Model $model): array
    {
        $class = $model::class;

        // Normalise anonymous-class names to their parent for stable caching,
        // mirroring the behaviour of HasRedisCache::getRedisPrefix().
        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($model) ?: $class; // @phpstan-ignore ternary.alwaysFalse
        }

        if (isset(static::$belongsToCache[$class])) {
            return static::$belongsToCache[$class];
        }

        /** @var array<string, array{foreignKey: string, parentClass: class-string<Model>}> $relations */
        $relations  = [];
        $reflection = new ReflectionClass($model);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === Model::class || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();
            if (!$returnType instanceof ReflectionNamedType) {
                continue;
            }

            if ($returnType->getName() !== BelongsTo::class) {
                continue;
            }

            try {
                $relation = $model->{$method->getName()}();
                if ($relation instanceof BelongsTo) {
                    /** @var class-string<Model> $parentClass */
                    $parentClass                   = $relation->getRelated()::class;
                    $relations[$method->getName()] = [
                        'foreignKey'  => $relation->getForeignKeyName(),
                        'parentClass' => $parentClass,
                    ];
                }
            } catch (Exception) {
                continue;
            }
        }

        static::$belongsToCache[$class] = $relations;

        return $relations;
    }
}
