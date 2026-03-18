<?php

namespace PetkaKahin\EloquentRedisMirror\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use PetkaKahin\EloquentRedisMirror\Builder\RedisBuilder;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Relations\RedisBelongsToMany;

/**
 * @phpstan-require-extends Model
 *
 * @mixin Model
 */
trait HasRedisCache
{
    public static function bootHasRedisCache(): void
    {
        static::created(function (Model $model): void {
            event(new RedisModelChanged($model, 'created'));
        });

        static::updated(function (Model $model): void {
            /** @var array<int, string> $dirty */
            $dirty = array_keys($model->getChanges());
            $updatedAtColumn = $model->getUpdatedAtColumn();
            $dirty = array_values(array_diff($dirty, [$updatedAtColumn]));

            if (!empty($dirty)) {
                event(new RedisModelChanged($model, 'updated', $dirty));
            }
        });

        static::deleted(function (Model $model): void {
            event(new RedisModelChanged($model, 'deleted'));
        });
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
            throw new \RuntimeException('Cannot generate Redis key for model without primary key value.');
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
        /** @var list<string> */
        return $this->redisRelations ?? [];
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    public function newEloquentBuilder($query): RedisBuilder
    {
        return new RedisBuilder($query);
    }

    /**
     * @param class-string<Model> $related
     */
    public function hasMany($related, $foreignKey = null, $localKey = null): HasMany
    {
        $relation = parent::hasMany($related, $foreignKey, $localKey);

        $builder = $relation->getQuery();
        if ($builder instanceof RedisBuilder) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? null;
            if ($caller !== null && in_array($caller, $this->getRedisRelations(), true)) {
                $builder->setRelationContext($this, $caller);
            }
        }

        return $relation;
    }

    /**
     * @param class-string<Model> $related
     */
    public function hasOne($related, $foreignKey = null, $localKey = null): HasOne
    {
        $relation = parent::hasOne($related, $foreignKey, $localKey);

        $builder = $relation->getQuery();
        if ($builder instanceof RedisBuilder) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? null;
            if ($caller !== null && in_array($caller, $this->getRedisRelations(), true)) {
                $builder->setRelationContext($this, $caller);
            }
        }

        return $relation;
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
}
