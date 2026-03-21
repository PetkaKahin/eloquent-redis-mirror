<?php

namespace PetkaKahin\EloquentRedisMirror\Builder\EagerLoad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class BelongsToLoader implements EagerLoadStrategy
{
    /**
     * @param Relation<Model, Model, mixed> $relation
     */
    public function supports(Relation $relation): bool
    {
        return $relation instanceof BelongsTo;
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
        /** @var BelongsTo<Model, Model> $relation */
        $fkName   = $relation->getForeignKeyName();
        $ownerKey = $relation->getOwnerKeyName();
        $relatedModel = $relation->getRelated();

        /** @var array<int, mixed> $fkValues */
        $fkValues = [];
        foreach ($models as $i => $model) {
            $fkValues[$i] = $model->getAttribute($fkName);
        }

        $uniqueIds = array_values(array_unique(array_filter(
            $fkValues,
            static fn (mixed $v): bool => $v !== null,
        )));

        $query = $relatedModel->newQuery();
        if ($nested !== null) {
            $query->with($nested);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $found */
        $found      = empty($uniqueIds) ? $relatedModel->newCollection() : $query->findMany($uniqueIds);
        $foundByKey = $found->keyBy($ownerKey);

        foreach ($models as $i => $model) {
            $fkValue = $fkValues[$i];
            $models[$i]->setRelation(
                $relationName,
                $fkValue !== null ? ($foundByKey[$fkValue] ?? null) : null,
            );
        }
    }
}
