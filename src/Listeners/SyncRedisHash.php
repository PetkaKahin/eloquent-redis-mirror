<?php

namespace PetkaKahin\EloquentRedisMirror\Listeners;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

        foreach ($infos as $info) {
            $fk = $info['fk'];

            if (!in_array($fk, $dirty, true)) {
                continue;
            }

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

        // Delete child indices along with their warmed flags
        foreach ($model->getRedisRelations() as $relation) {
            $indexKey     = $model->getRedisIndexKey($relation);
            $deleteKeys[] = $indexKey;
            $deleteKeys[] = $indexKey . ':warmed';
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

            $reverseRelation = $this->findReverseRelationName($parentClass, $model);

            if ($reverseRelation === null) {
                continue;
            }

            /** @var class-string<Model&HasRedisCacheInterface> $parentClass */
            $infos[] = [
                'fk'              => $info['foreignKey'],
                'parentPrefix'    => $parentClass::getRedisPrefix(),
                'reverseRelation' => $reverseRelation,
            ];
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
