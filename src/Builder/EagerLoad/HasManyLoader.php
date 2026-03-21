<?php

namespace PetkaKahin\EloquentRedisMirror\Builder\EagerLoad;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class HasManyLoader implements EagerLoadStrategy
{
    use ResolvesRedisRelations;
    use FetchesRelatedModels;

    /**
     * @param Relation<Model, Model, mixed> $relation
     */
    public function supports(Relation $relation): bool
    {
        return $relation instanceof HasOneOrMany;
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
        /** @var HasOneOrMany<Model, Model, mixed> $relation */
        $relatedModel = $relation->getRelated();
        /** @var Model&HasRedisCacheInterface $relatedModel */
        $relatedPrefix = $relatedModel::getRedisPrefix();
        $isHasOne = $relation instanceof HasOne;

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
                $relation, $relatedModel, $relatedPrefix, $nested, $constraints, $repository, $isHasOne,
            );
        } catch (Exception) {
            $this->loadFallback($models, $relation, $relationName, $relatedModel, $nested, $isHasOne);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, Model&HasRedisCacheInterface> $redisModels
     * @param array<int, string> $modelIndexKeys
     * @param HasOneOrMany<Model, Model, mixed> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadFromRedis(
        array &$models,
        array $redisModels,
        array $modelIndexKeys,
        string $relationName,
        HasOneOrMany $relation,
        Model $relatedModel,
        string $relatedPrefix,
        ?string $nested,
        callable $constraints,
        RedisRepository $repository,
        bool $isHasOne,
    ): void {
        $split = $this->resolveWarmColdSplit($modelIndexKeys, $redisModels, $relatedPrefix, $relatedModel, $repository);
        $allRelatedModels = $split['allRelatedModels'];
        $warmModels = $split['warmModels'];
        $coldStartModels = $split['coldStartModels'];

        // Set relations for warm models
        foreach ($warmModels as $i => $relatedIds) {
            /** @var list<Model> $orderedRelated */
            $orderedRelated = [];
            foreach ($relatedIds as $relId) {
                if (isset($allRelatedModels[$relId])) {
                    $orderedRelated[] = $allRelatedModels[$relId];
                }
            }

            $collection = $relatedModel->newCollection($orderedRelated);

            if ($nested !== null) {
                $relatedArray = $collection->all();
                self::loadNested($relatedArray, $nested, $constraints);
                $collection = $relatedModel->newCollection($relatedArray);
            }

            $models[$i]->setRelation(
                $relationName,
                $isHasOne ? $collection->first() : $collection,
            );
        }

        // Cold-start
        if (!empty($coldStartModels)) {
            $this->loadCold(
                $models, $coldStartModels, $modelIndexKeys, $relationName,
                $relation, $relatedModel, $relatedPrefix, $nested, $constraints, $repository, $isHasOne,
            );
        }
    }

    /**
     * Cold-start: batch query from DB for models with unwarmed indices.
     *
     * @param array<int, Model> $models
     * @param array<int, Model&HasRedisCacheInterface> $coldStartModels
     * @param array<int, string> $modelIndexKeys
     * @param HasOneOrMany<Model, Model, mixed> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadCold(
        array &$models,
        array $coldStartModels,
        array $modelIndexKeys,
        string $relationName,
        HasOneOrMany $relation,
        Model $relatedModel,
        string $relatedPrefix,
        ?string $nested,
        callable $constraints,
        RedisRepository $repository,
        bool $isHasOne,
    ): void {
        $fkName       = $relation->getForeignKeyName();
        $localKeyName = $relation->getLocalKeyName();

        /** @var list<string> $coldModelIndexKeys */
        $coldModelIndexKeys = array_values(array_map(
            static fn (int $i): string => $modelIndexKeys[$i],
            array_keys($coldStartModels),
        ));

        /** @var array<int, mixed> $coldLocalKeys */
        $coldLocalKeys = [];
        foreach ($coldStartModels as $i => $coldModel) {
            $coldLocalKeys[$i] = $coldModel->getAttribute($localKeyName);
        }

        $allColdRelated = $relatedModel->newQuery()
            ->whereIn($fkName, array_values($coldLocalKeys))
            ->get();

        // Group by string-cast FK to avoid int/string type mismatch on lookup
        $grouped = $allColdRelated->groupBy(static fn (Model $m) => (string) $m->getAttribute($fkName));

        /** @var array<string, array<string, mixed>> $coldToCache */
        $coldToCache = [];
        /** @var array<string, array<int|string, float>> $coldIndexEntries */
        $coldIndexEntries = [];

        foreach ($coldStartModels as $i => $coldModel) {
            /** @var int|string $localKeyValue */
            $localKeyValue = $coldLocalKeys[$i];
            /** @var array<int, Model> $groupedModels */
            $groupedModels = ($grouped->get((string) $localKeyValue) ?? collect())->all();
            $dbRelated     = $relatedModel->newCollection($groupedModels);

            foreach ($dbRelated as $rel) {
                $coldToCache[$relatedPrefix . ':' . $rel->getKey()]             = $rel->getAttributes();
                $coldIndexEntries[$modelIndexKeys[$i]][(string) $rel->getKey()] = $this->scoreFromModel($rel);
            }

            if ($nested !== null) {
                $relatedArray = $dbRelated->all();
                self::loadNested($relatedArray, $nested, $constraints);
                $dbRelated = $relatedModel->newCollection($relatedArray);
            }

            $models[$i]->setRelation(
                $relationName,
                $isHasOne ? $dbRelated->first() : $dbRelated,
            );
        }

        try {
            $repository->executeBatch(
                setItems: $coldToCache,
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
     * @param HasOneOrMany<Model, Model, mixed> $relation
     * @param Model&HasRedisCacheInterface $relatedModel
     */
    private function loadFallback(
        array &$models,
        HasOneOrMany $relation,
        string $relationName,
        Model $relatedModel,
        ?string $nested,
        bool $isHasOne,
    ): void {
        $fkName       = $relation->getForeignKeyName();
        $localKeyName = $relation->getLocalKeyName();

        $localKeys = array_values(array_map(
            static fn (Model $m): mixed => $m->getAttribute($localKeyName),
            $models,
        ));

        $allFallbackRelated = $relatedModel->newQuery()->whereIn($fkName, $localKeys)->get();
        $grouped            = $allFallbackRelated->groupBy(static fn (Model $m) => (string) $m->getAttribute($fkName));

        foreach ($models as $model) {
            /** @var int|string $localKey */
            $localKey      = $model->getAttribute($localKeyName);
            /** @var array<int, Model> $groupedModels */
            $groupedModels = ($grouped->get((string) $localKey) ?? collect())->all();
            $dbRelated     = $relatedModel->newCollection($groupedModels);
            if ($nested !== null) {
                $dbRelated->load($nested);
            }
            $model->setRelation(
                $relationName,
                $isHasOne ? $dbRelated->first() : $dbRelated,
            );
        }
    }
}
