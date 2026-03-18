<?php

namespace PetkaKahin\EloquentRedisMirror\Listeners;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use ReflectionMethod;

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
        $model = $event->model;

        match ($event->action) {
            'created' => $this->handleCreated($model),
            'updated' => $this->handleUpdated($model, $event->dirty),
            'deleted' => $this->handleDeleted($model),
            default => null,
        };
    }

    protected function handleCreated(Model $model): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $this->repository->set($model->getRedisKey(), $model->getAttributes());
        $this->addToParentIndices($model);
    }

    /**
     * @param list<string> $dirty
     */
    protected function handleUpdated(Model $model, array $dirty): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $this->repository->set($model->getRedisKey(), $model->getAttributes());

        $parentRelations = $this->getBelongsToRelations($model);

        foreach ($parentRelations as $info) {
            $fk = $info['foreignKey'];

            if (!in_array($fk, $dirty)) {
                continue;
            }

            $parentClass = $info['parentClass'];

            if (!$this->usesRedisCache($parentClass)) {
                continue;
            }

            $reverseRelation = $this->findReverseRelationName($parentClass, $model);

            if ($reverseRelation === null) {
                continue;
            }

            $oldFk = $model->getOriginal($fk);
            $newFk = $model->getAttribute($fk);

            /** @var class-string<Model&HasRedisCacheInterface> $parentClass */
            $parentPrefix = $parentClass::getRedisPrefix();
            /** @var DateTimeInterface|null $createdAt */
            $createdAt = $model->getAttribute('created_at');
            $score = $createdAt instanceof DateTimeInterface ? (float) $createdAt->getTimestamp() : (float) time();
            $modelKey = (string) $model->getKey();

            if ($oldFk !== null) {
                $oldIndexKey = $parentPrefix . ':' . $oldFk . ':' . $reverseRelation;
                $this->repository->removeFromIndex($oldIndexKey, $modelKey);
            }

            if ($newFk !== null) {
                $newIndexKey = $parentPrefix . ':' . $newFk . ':' . $reverseRelation;
                $this->repository->addToIndex($newIndexKey, $modelKey, $score);
            }
        }
    }

    protected function handleDeleted(Model $model): void
    {
        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $this->repository->delete($model->getRedisKey());

        $parentRelations = $this->getBelongsToRelations($model);

        foreach ($parentRelations as $info) {
            $fk = $info['foreignKey'];
            $parentClass = $info['parentClass'];

            if (!$this->usesRedisCache($parentClass)) {
                continue;
            }

            $reverseRelation = $this->findReverseRelationName($parentClass, $model);

            if ($reverseRelation === null) {
                continue;
            }

            $parentId = $model->getAttribute($fk);
            if ($parentId !== null) {
                /** @var class-string<Model&HasRedisCacheInterface> $parentClass */
                $parentPrefix = $parentClass::getRedisPrefix();
                $indexKey = $parentPrefix . ':' . $parentId . ':' . $reverseRelation;
                $this->repository->removeFromIndex($indexKey, (string) $model->getKey());
            }
        }

        foreach ($model->getRedisRelations() as $relation) {
            $this->repository->deleteIndex($model->getRedisIndexKey($relation));
        }
    }

    /**
     * @param Model&HasRedisCacheInterface $model
     */
    protected function addToParentIndices(Model $model): void
    {
        $parentRelations = $this->getBelongsToRelations($model);

        foreach ($parentRelations as $info) {
            $fk = $info['foreignKey'];
            $parentClass = $info['parentClass'];

            if (!$this->usesRedisCache($parentClass)) {
                continue;
            }

            $reverseRelation = $this->findReverseRelationName($parentClass, $model);

            if ($reverseRelation === null) {
                continue;
            }

            $parentId = $model->getAttribute($fk);
            if ($parentId !== null) {
                /** @var class-string<Model&HasRedisCacheInterface> $parentClass */
                $parentPrefix = $parentClass::getRedisPrefix();
                $indexKey = $parentPrefix . ':' . $parentId . ':' . $reverseRelation;
                /** @var DateTimeInterface|null $createdAt */
                $createdAt = $model->getAttribute('created_at');
                $score = $createdAt instanceof DateTimeInterface ? (float) $createdAt->getTimestamp() : (float) time();
                $this->repository->addToIndex($indexKey, (string) $model->getKey(), $score);
            }
        }
    }

    /**
     * @return array<string, array{foreignKey: string, parentClass: class-string<Model>}>
     */
    protected function getBelongsToRelations(Model $model): array
    {
        $class = $model::class;

        if (isset(static::$belongsToCache[$class])) {
            return static::$belongsToCache[$class];
        }

        /** @var array<string, array{foreignKey: string, parentClass: class-string<Model>}> $relations */
        $relations = [];
        $reflection = new \ReflectionClass($model);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === Model::class || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();
            if (!$returnType instanceof \ReflectionNamedType) {
                continue;
            }

            if ($returnType->getName() !== BelongsTo::class) {
                continue;
            }

            try {
                $relation = $model->{$method->getName()}();
                if ($relation instanceof BelongsTo) {
                    /** @var class-string<Model> $parentClass */
                    $parentClass = $relation->getRelated()::class;
                    $relations[$method->getName()] = [
                        'foreignKey' => $relation->getForeignKeyName(),
                        'parentClass' => $parentClass,
                    ];
                }
            } catch (\Exception) {
                continue;
            }
        }

        static::$belongsToCache[$class] = $relations;

        return $relations;
    }
}
