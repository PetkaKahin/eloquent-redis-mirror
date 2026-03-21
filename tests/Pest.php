<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use PetkaKahin\EloquentRedisMirror\Builder\RedisBuilder;
use PetkaKahin\EloquentRedisMirror\Concerns\RedisRelationCache;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisHash;
use PetkaKahin\EloquentRedisMirror\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
uses(RefreshDatabase::class)->in('Integration', 'Feature');

afterEach(function () {
    RedisRelationCache::reset();
    SyncRedisHash::resetCache();
    RedisBuilder::resetStrategies();
});
