<?php

namespace PetkaKahin\EloquentRedisMirror\Builder\EagerLoad;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

/**
 * @phpstan-type LoadParams array{
 *     models: array<int, Model>,
 *     relationName: string,
 *     relation: Relation<Model, Model, mixed>,
 *     nested: string|null,
 *     constraints: callable,
 *     repository: RedisRepository,
 * }
 */
interface EagerLoadStrategy
{
    /**
     * @param Relation<Model, Model, mixed> $relation
     */
    public function supports(Relation $relation): bool;

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
    ): void;
}
