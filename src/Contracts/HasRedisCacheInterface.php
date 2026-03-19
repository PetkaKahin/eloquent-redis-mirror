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
}
