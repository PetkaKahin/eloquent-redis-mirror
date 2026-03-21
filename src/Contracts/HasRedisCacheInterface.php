<?php

namespace PetkaKahin\EloquentRedisMirror\Contracts;

interface HasRedisCacheInterface
{
    public static function getRedisPrefix(): string;

    public function getRedisKey(): string;

    public function getRedisIndexKey(string $relation): string;

    /**
     * @return list<string>
     */
    public function getRedisRelations(): array;

    /**
     * Pivot columns to use as ZADD scores for BelongsToMany relations.
     * Example: ['categories' => 'position'] — sort by pivot.position instead of model attribute.
     *
     * @return array<string, string>  relationName => pivotColumnName
     */
    public function getRedisPivotScoreColumns(): array;

    /**
     * Custom relation methods mapped to their base Redis type.
     * Example: ['projects' => 'belongsToMany'] — treat custom belongsToSortedMany() as BelongsToMany for Redis.
     *
     * @return array<string, string>  relationName => baseType ('belongsToMany'|'hasMany'|'hasOne'|'belongsTo')
     */
    public function getRedisCustomRelations(): array;
}
