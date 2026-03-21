<?php

namespace PetkaKahin\EloquentRedisMirror\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use PetkaKahin\EloquentRedisMirror\Builder\RedisBuilder;
use PetkaKahin\EloquentRedisMirror\Concerns\CustomRelationResolver;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Relations\RedisBelongsToMany;
use RuntimeException;

/**
 * @phpstan-require-extends Model
 *
 * @mixin Model
 */
trait HasRedisCache
{
    public static function bootHasRedisCache(): void
    {
        static::created(static function (Model $model): void {
            event(new RedisModelChanged($model, 'created'));
        });

        static::updated(static function (Model $model): void {
            /** @var array<int, string> $dirty */
            $dirty = array_keys($model->getChanges());

            event(new RedisModelChanged($model, 'updated', $dirty));
        });

        static::deleted(static function (Model $model): void {
            event(new RedisModelChanged($model, 'deleted'));
        });

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class))) {
            static::restored(static function (Model $model): void {
                event(new RedisModelChanged($model, 'restored'));
            });
        }
    }

    public static function getRedisPrefix(): string
    {
        $class = static::class;

        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($class) ?: $class;
        }

        return Str::snake(class_basename($class));
    }

    public function getRedisKey(): string
    {
        $key = $this->getKey();

        if ($key === null) {
            throw new RuntimeException('Cannot generate Redis key for model without primary key value.');
        }

        return static::getRedisPrefix() . ':' . $key;
    }

    public function getRedisIndexKey(string $relation): string
    {
        return $this->getRedisKey() . ':' . $relation;
    }

    /**
     * @return list<string>
     */
    public function getRedisRelations(): array
    {
        /** @var list<string> $standard */
        $standard = $this->redisRelations ?? [];
        /** @var list<string> $custom */
        $custom = array_keys($this->redisCustomRelations ?? []);

        if (empty($custom)) {
            return $standard;
        }

        return array_values(array_unique(array_merge($standard, $custom)));
    }

    /**
     * @return array<string, string>
     */
    public function getRedisCustomRelations(): array
    {
        /** @var array<string, string> */
        return $this->redisCustomRelations ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function getRedisPivotScoreColumns(): array
    {
        /** @var array<string, string> */
        return $this->redisPivotScore ?? [];
    }

    /**
     * Override newModelQuery to always return a RedisBuilder, wrapping any custom builder.
     * This ensures Redis interception works even when the model defines its own newEloquentBuilder().
     *
     * @return RedisBuilder
     */
    public function newModelQuery()
    {
        $baseQuery = $this->newBaseQueryBuilder();
        $originalBuilder = $this->newEloquentBuilder($baseQuery)->setModel($this);

        if ($originalBuilder instanceof RedisBuilder) {
            return $originalBuilder;
        }

        if (get_class($originalBuilder) === EloquentBuilder::class) {
            return (new RedisBuilder($baseQuery))->setModel($this);
        }

        $redisBuilder = new RedisBuilder($baseQuery);
        $redisBuilder->setModel($this);
        $redisBuilder->setWrappedBuilder($originalBuilder);

        return $redisBuilder;
    }

    /**
     * @param class-string<Model> $related
     */
    public function hasMany($related, $foreignKey = null, $localKey = null): HasMany
    {
        $relation = parent::hasMany($related, $foreignKey, $localKey);
        $this->applyRedisRelationContext($relation);

        return $relation;
    }

    /**
     * @param class-string<Model> $related
     */
    public function hasOne($related, $foreignKey = null, $localKey = null): HasOne
    {
        $relation = parent::hasOne($related, $foreignKey, $localKey);
        $this->applyRedisRelationContext($relation);

        return $relation;
    }

    /**
     * Set Redis relation context on the builder if the calling method is a registered Redis relation.
     * Reads frame [2] from the backtrace: [0]=this, [1]=hasMany/hasOne, [2]=actual relation method.
     *
     * @param Relation<Model, Model, mixed> $relation
     */
    private function applyRedisRelationContext(Relation $relation): void
    {
        $builder = $relation->getQuery();

        if (!$builder instanceof RedisBuilder) {
            return;
        }

        $redisRelations = $this->getRedisRelations();

        if (empty($redisRelations)) {
            return;
        }

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? null;

        if ($caller !== null && in_array($caller, $redisRelations, true)) {
            $builder->setRelationContext($this, $caller);
        }
    }

    /**
     * @param class-string<Model> $related
     */
    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null, $relation = null): RedisBelongsToMany
    {
        if ($relation === null) {
            $relation = $this->guessBelongsToManyRelation();
        }

        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        if ($table === null) {
            $table = $this->joiningTable($related, $instance);
        }

        return new RedisBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation
        );
    }

    /**
     * Intercept lazy loading for custom relation methods registered in $redisCustomRelations.
     * Tries Redis first; on miss or error falls through to the original relation (SQL).
     *
     * @param string $method
     * @return mixed
     */
    protected function getRelationshipFromMethod($method)
    {
        $customRelations = $this->getRedisCustomRelations();

        if (!isset($customRelations[$method])) {
            return parent::getRelationshipFromMethod($method);
        }

        try {
            /** @var CustomRelationResolver $resolver */
            $resolver = app(CustomRelationResolver::class);
            $result = $resolver->resolveFromRedis($this, $method, $customRelations[$method]);

            if ($result !== false) {
                $this->setRelation($method, $result);

                return $result;
            }
        } catch (\Exception) {
            // Redis unavailable — fall through to SQL
        }

        return parent::getRelationshipFromMethod($method);
    }

    /**
     * Dispatch a RedisPivotChanged event for custom BelongsToMany relations.
     * Call this after mutations (attach/detach/sync) on custom relation types
     * that don't go through RedisBelongsToMany.
     *
     * @param string $relationName
     * @param string $action  'attached'|'detached'|'synced'|'toggled'|'updated'
     * @param list<int|string> $ids
     * @param array<int|string, array<string, mixed>> $pivotAttributes
     */
    public function dispatchPivotChange(
        string $relationName,
        string $action,
        array $ids,
        array $pivotAttributes = [],
    ): void {
        event(new RedisPivotChanged(
            $this,
            $relationName,
            $action,
            $ids,
            $pivotAttributes,
        ));
    }
}
