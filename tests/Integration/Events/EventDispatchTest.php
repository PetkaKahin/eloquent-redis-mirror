<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\PlainModel;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
});

it('RedisModelChanged содержит корректную модель', function () {
    Event::fake([RedisModelChanged::class]);

    $project = Project::create(['name' => 'Test']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) use ($project) {
        return $event->model->id === $project->id
            && $event->model instanceof Project;
    });
});

it('RedisModelChanged::updated содержит dirty-поля', function () {
    $project = Project::create(['name' => 'Old', 'description' => 'Original']);

    Event::fake([RedisModelChanged::class]);

    $project->update(['name' => 'New', 'description' => 'Updated']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'updated'
            && in_array('name', $event->dirty)
            && in_array('description', $event->dirty)
            && !in_array('id', $event->dirty)
            && !in_array('created_at', $event->dirty);
    });
});

it('RedisPivotChanged содержит корректного parent', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach($tag->id);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($project) {
        return $event->parent->id === $project->id
            && $event->relationName === 'tags';
    });
});

it('RedisPivotChanged содержит pivotAttributes', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach([$tag->id => ['role' => 'primary']]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'attached'
            && isset($event->pivotAttributes[$tag->id])
            && $event->pivotAttributes[$tag->id]['role'] === 'primary';
    });
});

it('Events не кидаются для моделей без trait', function () {
    Event::fake([RedisModelChanged::class]);

    PlainModel::create(['name' => 'Test']);

    Event::assertNotDispatched(RedisModelChanged::class);
});
