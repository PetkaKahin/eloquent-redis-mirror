<?php

namespace PetkaKahin\EloquentRedisMirror\Concerns;

/**
 * Global in-process cache shared across all classes that use ResolvesRedisRelations.
 *
 * Trait static properties are copied per-class, so without this dedicated class
 * each consumer (RedisBuilder, SyncRedisHash, SyncRedisPivot) would maintain its
 * own independent cache and repeat the same reflection/class_uses work every request.
 */
final class RedisRelationCache
{
    /** @var array<class-string, bool> */
    public static array $traitCheck = [];

    /** @var array<string, list<string>> */
    public static array $reverseRelation = [];

    /**
     * Reset all static caches. Call in test teardown to prevent cross-test pollution.
     */
    public static function reset(): void
    {
        static::$traitCheck = [];
        static::$reverseRelation = [];
    }
}
