<?php

namespace PetkaKahin\EloquentRedisMirror\Builder;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\BelongsToLoader;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\BelongsToManyLoader;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\EagerLoadStrategy;
use PetkaKahin\EloquentRedisMirror\Builder\EagerLoad\HasManyLoader;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Contracts\HasRedisCacheInterface;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

/**
 * @extends Builder<Model>
 */
class RedisBuilder extends Builder
{
    use ResolvesRedisRelations;

    protected ?Model $relationParent = null;
    protected ?string $relationName = null;

    protected ?RedisRepository $repositoryInstance = null;

    /**
     * Flag to prevent infinite recursion between find() and first().
     *
     * When find() falls back to parent::find(), which internally calls $this->first(),
     * this flag ensures first() delegates to parent::first() (pure SQL) instead of
     * re-entering the Redis path and calling find() again.
     */
    protected bool $dbFallback = false;

    /** @var list<array<string, mixed>>|null Cached result of resolveWheres() */
    protected ?array $resolvedWheres = null;

    /** @var Builder<Model>|null */
    protected ?Builder $wrappedBuilder = null;

    /**
     * @param Builder<Model> $builder
     */
    public function setWrappedBuilder(Builder $builder): static
    {
        $this->wrappedBuilder = $builder;

        return $this;
    }

    /**
     * Delegate unknown method calls to the wrapped builder (e.g. SortableBuilder::sorted()).
     * Since __call is only invoked for methods not on this class, method_exists on the
     * wrappedBuilder will only match methods unique to the custom builder class.
     *
     * @param string $method
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($this->wrappedBuilder !== null && method_exists($this->wrappedBuilder, $method)) {
            $result = $this->wrappedBuilder->$method(...$parameters);

            return $result === $this->wrappedBuilder ? $this : $result;
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param Model&HasRedisCacheInterface $parent
     */
    public function setRelationContext(Model $parent, string $relationName): static
    {
        $this->relationParent = $parent;
        $this->relationName = $relationName;

        return $this;
    }

    protected function hasRelationContext(): bool
    {
        return $this->relationParent !== null
            && $this->usesRedisCache($this->relationParent)
            && $this->relationName !== null;
    }

    protected function getRelationIndexKey(): ?string
    {
        if (!$this->hasRelationContext()) {
            return null;
        }

        /** @var Model&HasRedisCacheInterface $parent */
        $parent = $this->relationParent;

        if ($parent->getKey() === null) {
            return null;
        }

        /** @var string $relationName */
        $relationName = $this->relationName;

        return $parent->getRedisIndexKey($relationName);
    }

    protected function repository(): RedisRepository
    {
        if ($this->repositoryInstance === null) {
            $this->repositoryInstance = app(RedisRepository::class);
        }

        return $this->repositoryInstance;
    }

    /**
     * @param mixed $id
     * @param list<string> $columns
     */
    public function find($id, $columns = ['*']): Model|Collection|null
    {
        if (is_array($id)) {
            return $this->findMany(array_values($id), $columns);
        }

        if (!$this->usesRedisCache($this->getModel())) {
            return parent::find($id, $columns);
        }

        $model = $this->getModel();
        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();
        $key = $prefix . ':' . $id;

        $result = null;

        try {
            $cached = $this->repository()->get($key);

            if ($cached !== null) {
                $hydrated = $this->hydrateModel($cached);

                if ($this->modelSatisfiesWheres($hydrated)) {
                    $result = $hydrated;
                }
            }
        } catch (Exception) {
            // Redis unavailable — fallback to DB
        }

        if ($result === null) {
            // Set flag to prevent find→parent::find→first→find infinite loop.
            // parent::find() calls $this->first() which is overridden; the flag
            // ensures it falls through to parent::first() (pure SQL).
            $this->dbFallback = true;
            try {
                $result = parent::find($id, $columns);
            } finally {
                $this->dbFallback = false;
            }

            if ($result instanceof Model) {
                try {
                    $this->repository()->set($key, $result->getAttributes());
                } catch (Exception) {
                    // Redis unavailable — skip caching
                }
            }
        }

        if ($result instanceof Model && !empty($this->eagerLoad)) {
            $models = $this->eagerLoadRelations([$result]);
            $result = $models[0];
        }

        return $result instanceof Model ? $result : null;
    }

    /**
     * @param array<int, mixed> $ids
     * @param list<string> $columns
     * @return Collection<int, Model>
     */
    public function findMany($ids, $columns = ['*']): Collection
    {
        if (empty($ids)) {
            return $this->getModel()->newCollection();
        }

        if (!$this->usesRedisCache($this->getModel())) {
            return parent::findMany($ids, $columns);
        }

        // Reset cached wheres so that any builder mutations within this call
        // (e.g. adding whereIn for DB fallback) get fresh scope resolution.
        $this->resolvedWheres = null;

        $model = $this->getModel();
        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();

        /** @var list<string> $keys */
        $keys = [];
        /** @var array<string, int|string> $keyToId */
        $keyToId = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;
            $redisKey = $prefix . ':' . $idStr;
            $keys[] = $redisKey;
            $keyToId[$redisKey] = $id;
        }

        /** @var array<int|string, Model> $found */
        $found = [];
        /** @var list<int|string> $missedIds */
        $missedIds = [];

        try {
            $cached = $this->repository()->getMany($keys);

            foreach ($cached as $redisKey => $attrs) {
                $originalId = $keyToId[$redisKey];
                if ($attrs !== null) {
                    $hydrated = $this->hydrateModel($attrs);
                    if ($this->modelSatisfiesWheres($hydrated)) {
                        $found[$originalId] = $hydrated;
                    } else {
                        // Cached data doesn't satisfy wheres — may be stale.
                        // Fall back to DB which will apply correct constraints.
                        $missedIds[] = $originalId;
                    }
                } else {
                    $missedIds[] = $originalId;
                }
            }
        } catch (Exception) {
            /** @var list<int|string> $missedIds */
            $missedIds = array_values($ids);
        }

        if (!empty($missedIds)) {
            $keyName = $model->getKeyName();

            $this->dbFallback = true;
            try {
                $dbResults = parent::whereIn($keyName, $missedIds)->get($columns);
            } finally {
                $this->dbFallback = false;
            }

            /** @var array<string, array<string, mixed>> $toCache */
            $toCache = [];
            foreach ($dbResults as $dbModel) {
                $dbId = $dbModel->getKey();
                $found[$dbId] = $dbModel;
                $toCache[$prefix . ':' . $dbId] = $dbModel->getAttributes();
            }

            if (!empty($toCache)) {
                try {
                    $this->repository()->setMany($toCache);
                } catch (Exception) {
                    // Redis unavailable
                }
            }
        }

        /** @var list<Model> $ordered */
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $ordered[] = $found[$id];
            }
        }

        if (!empty($this->eagerLoad) && !empty($ordered)) {
            $ordered = $this->eagerLoadRelations($ordered);
        }

        return $this->getModel()->newCollection($ordered);
    }

    /**
     * @param list<string> $columns
     * @return Collection<int, Model>
     */
    public function get($columns = ['*']): Collection
    {
        if ($this->dbFallback) {
            return parent::get($columns);
        }

        $this->resolvedWheres = null;

        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            $result = parent::get($columns);
            $this->cacheLoadedModels($result);

            return $result;
        }

        if ($this->getQuery()->limit !== null) {
            return parent::get($columns);
        }

        // Bail if any where type is unsupported by modelSatisfiesWheres().
        // Must use fresh wheres (not resolveWheres() cache) because the builder
        // may have been mutated since the cache was populated (e.g. findMany
        // adds a whereIn for DB fallback before calling get()).
        /** @var list<array<string, mixed>> $wheres */
        $wheres = $this->applyScopes()->getQuery()->wheres;
        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            if (!in_array($type, ['Basic', 'Null', 'NotNull'], true)) {
                return parent::get($columns);
            }
        }

        try {
            $ids = $this->repository()->getRelationIdsChecked($indexKey);

            if ($ids === null) {
                $result = parent::get($columns);
                $this->warmRelationFromResult($indexKey, $result);

                return $result;
            }

            if (empty($ids)) {
                return $this->getModel()->newCollection();
            }

            return $this->findMany($ids, $columns);
        } catch (Exception) {
            return parent::get($columns);
        }
    }

    /**
     * @param list<string> $columns
     */
    public function first($columns = ['*']): ?Model
    {
        if ($this->dbFallback) {
            return parent::first($columns);
        }

        $this->resolvedWheres = null;

        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            $result = parent::first($columns);
            $this->cacheSingleModel($result);

            return $result;
        }

        try {
            $ids = $this->repository()->getRelationIdsChecked($indexKey, 0, 0);

            if ($ids === null) {
                $result = parent::first($columns);
                $this->cacheSingleModel($result);

                return $result;
            }

            if (empty($ids)) {
                return null;
            }

            return $this->find($ids[0], $columns);
        } catch (Exception) {
            return parent::first($columns);
        }
    }

    /**
     * @param int|null $perPage
     * @param list<string> $columns
     * @param string $pageName
     * @param int|null $page
     * @param int|\Closure|null $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Model>
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            return parent::paginate($perPage, $columns, $pageName, $page);
        }

        $perPage = $perPage ?: $this->getModel()->getPerPage();
        $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

        try {
            $total = $this->repository()->getRelationCountChecked($indexKey);

            if ($total === null) {
                return parent::paginate($perPage, $columns, $pageName, $page);
            }

            if ($total === 0) {
                return new LengthAwarePaginator([], 0, $perPage, $page, [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]);
            }

            $offset = ($page - 1) * $perPage;
            $ids = $this->repository()->getRelationIds($indexKey, $offset, $offset + $perPage - 1);
            $items = $this->findMany($ids, $columns);

            return new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        } catch (Exception) {
            return parent::paginate($perPage, $columns, $pageName, $page);
        }
    }

    /**
     * Check existence via Redis sorted set when in relation context (HasMany/HasOne).
     * Falls back to SQL when no relation context, extra constraints exist, or cold start.
     */
    public function exists(): bool
    {
        if ($this->dbFallback) {
            return parent::exists();
        }

        $indexKey = $this->getRelationIndexKey();

        if ($indexKey === null) {
            return parent::exists();
        }

        try {
            $count = $this->repository()->getRelationCountChecked($indexKey);

            if ($count === null) {
                return parent::exists();
            }

            return $count > 0;
        } catch (Exception) {
            return parent::exists();
        }
    }

    /**
     * @param array<int, Model> $models
     * @return array<int, Model>
     */
    public function eagerLoadRelations(array $models): array
    {
        $nonRedisEagerLoad = $this->eagerLoad;

        foreach ($this->eagerLoad as $name => $constraints) {
            /** @var callable $constraints */
            if ($this->loadRedisRelation($models, $name, $constraints)) {
                unset($nonRedisEagerLoad[$name]);
            }
        }

        if (!empty($nonRedisEagerLoad)) {
            $original = $this->eagerLoad;
            $this->eagerLoad = $nonRedisEagerLoad;
            $models = parent::eagerLoadRelations($models);
            $this->eagerLoad = $original;
        }

        return $models;
    }

    /** @var list<EagerLoadStrategy>|null */
    protected static ?array $strategies = null;

    /**
     * @return list<EagerLoadStrategy>
     */
    protected function getEagerLoadStrategies(): array
    {
        return static::$strategies ??= [
            new BelongsToLoader(),
            new HasManyLoader(),
            new BelongsToManyLoader(),
        ];
    }

    public static function resetStrategies(): void
    {
        static::$strategies = null;
    }

    protected function getStrategyForType(string $type): ?EagerLoadStrategy
    {
        return match ($type) {
            'belongsToMany' => new BelongsToManyLoader(),
            'hasMany', 'hasOne' => new HasManyLoader(),
            'belongsTo' => new BelongsToLoader(),
            default => null,
        };
    }

    /**
     * @param array<int, Model> $models
     */
    protected function loadRedisRelation(array &$models, string $name, callable $constraints): bool
    {
        $parts = explode('.', $name);
        $directRelation = $parts[0];
        $nested = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : null;

        if (empty($models)) {
            return true;
        }

        $firstModel = $models[0];

        if (!method_exists($firstModel, $directRelation)) {
            return false;
        }

        /** @var Relation<Model, Model, mixed> $relation */
        $relation = $firstModel->$directRelation();
        $relatedModel = $relation->getRelated();

        if (!$this->usesRedisCache($relatedModel)) {
            return false;
        }

        if ($constraints instanceof \Closure) {
            $testQuery = $relatedModel->newQuery();
            $beforeWheres = $testQuery->getQuery()->wheres;
            $beforeOrders = $testQuery->getQuery()->orders;
            $beforeLimit = $testQuery->getQuery()->limit;
            $constraints($testQuery);
            $hasRealConstraints = $testQuery->getQuery()->wheres !== $beforeWheres
                || $testQuery->getQuery()->orders !== $beforeOrders
                || $testQuery->getQuery()->limit !== $beforeLimit;
            if ($hasRealConstraints) {
                return false;
            }
        }

        foreach ($this->getEagerLoadStrategies() as $strategy) {
            if ($strategy->supports($relation)) {
                $strategy->load($models, $directRelation, $relation, $nested, $constraints, $this->repository());
                return true;
            }
        }

        // Fallback: check if this is a custom relation with a mapped type
        if ($firstModel instanceof HasRedisCacheInterface) {
            $customTypes = $firstModel->getRedisCustomRelations();

            if (isset($customTypes[$directRelation])) {
                $strategy = $this->getStrategyForType($customTypes[$directRelation]);

                if ($strategy !== null) {
                    $strategy->load($models, $directRelation, $relation, $nested, $constraints, $this->repository());

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the where clauses once (applying global scopes) and cache the result
     * so that repeated calls within the same query (e.g. findMany loop) don't
     * clone the builder every time.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveWheres(): array
    {
        if ($this->resolvedWheres === null) {
            /** @var list<array<string, mixed>> $wheres */
            $wheres = $this->applyScopes()->getQuery()->wheres;
            $this->resolvedWheres = $wheres;
        }

        return $this->resolvedWheres;
    }

    /**
     * Check if a hydrated model satisfies all where clauses on the current query.
     * Handles relation FK constraints (Basic =) and SoftDeletes (Null on deleted_at).
     * Returns false for unsupported where types to trigger safe DB fallback.
     */
    protected function modelSatisfiesWheres(Model $model): bool
    {
        $wheres = $this->resolveWheres();

        if (empty($wheres)) {
            return true;
        }

        foreach ($wheres as $where) {
            /** @var string|null $column */
            $column = $where['column'] ?? null;

            if ($column !== null && str_contains($column, '.')) {
                $column = substr($column, strrpos($column, '.') + 1);
            }

            $type = $where['type'] ?? null;

            if ($column === null) {
                // No column — unsupported where, fall back to DB
                return false;
            }

            if ($type === 'Basic') {
                $modelValue = $model->getAttribute($column);
                $operator = $where['operator'] ?? '=';
                $expected = $where['value'];

                $passes = match ($operator) {
                    '=', '==' => $modelValue == $expected,
                    '!=', '<>' => $modelValue != $expected,
                    '>' => $modelValue > $expected,
                    '<' => $modelValue < $expected,
                    '>=' => $modelValue >= $expected,
                    '<=' => $modelValue <= $expected,
                    default => false,
                };

                if (!$passes) {
                    return false;
                }
            } elseif ($type === 'Null') {
                if ($model->getAttribute($column) !== null) {
                    return false;
                }
            } elseif ($type === 'NotNull') {
                if ($model->getAttribute($column) === null) {
                    return false;
                }
            } else {
                // Unsupported where type — fall back to DB for safety
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function hydrateModel(array $attributes): Model
    {
        return $this->getModel()->newFromBuilder($attributes);
    }

    /**
     * Cache models loaded from DB (no index, just model hashes).
     * Used when get() has no relation context — warms Redis for future find() calls.
     *
     * @param Collection<int, Model> $result
     */
    protected function cacheLoadedModels(Collection $result): void
    {
        if ($result->isEmpty()) {
            return;
        }

        $model = $this->getModel();

        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();

        /** @var array<string, array<string, mixed>> $toCache */
        $toCache = [];

        foreach ($result as $item) {
            $toCache[$prefix . ':' . $item->getKey()] = $item->getAttributes();
        }

        try {
            $this->repository()->setMany($toCache);
        } catch (Exception) {
            // Redis unavailable
        }
    }

    /**
     * Cache a single model loaded from DB.
     * Used when first() falls back to SQL.
     */
    protected function cacheSingleModel(?Model $model): void
    {
        if ($model === null || !$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $key = $model::getRedisPrefix() . ':' . $model->getKey();

        try {
            $this->repository()->set($key, $model->getAttributes());
        } catch (Exception) {
            // Redis unavailable
        }
    }

    /**
     * Warm a relation index from DB results on cold start.
     * Caches each model + populates the sorted set index + marks as warmed.
     *
     * @param Collection<int, Model> $result
     */
    protected function warmRelationFromResult(string $indexKey, Collection $result): void
    {
        $model = $this->getModel();

        if (!$this->usesRedisCache($model)) {
            return;
        }

        /** @var Model&HasRedisCacheInterface $model */
        $prefix = $model::getRedisPrefix();

        /** @var array<string, array<string, mixed>> $toCache */
        $toCache = [];
        /** @var array<int|string, float> $indexEntries */
        $indexEntries = [];

        foreach ($result as $item) {
            $toCache[$prefix . ':' . $item->getKey()] = $item->getAttributes();
            $indexEntries[(string) $item->getKey()] = $this->scoreFromModel($item);
        }

        try {
            $this->repository()->executeBatch(
                setItems: $toCache,
                addToIndices: !empty($indexEntries) ? [$indexKey => $indexEntries] : [],
                markWarmed: [$indexKey],
            );
        } catch (Exception) {
            // Redis unavailable
        }
    }
}
