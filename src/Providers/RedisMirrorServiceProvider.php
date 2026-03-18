<?php

namespace PetkaKahin\EloquentRedisMirror\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisHash;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisPivot;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

class RedisMirrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RedisRepository::class);
    }

    public function boot(): void
    {
        Event::listen(RedisModelChanged::class, SyncRedisHash::class);
        Event::listen(RedisPivotChanged::class, SyncRedisPivot::class);
    }
}
