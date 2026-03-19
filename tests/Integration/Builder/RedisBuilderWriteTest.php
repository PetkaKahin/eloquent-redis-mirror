<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
});

// ─── create() ───────────────────────────────────────────────

it('create записывает в Postgres и кидает event', function () {
    Event::fake([RedisModelChanged::class]);

    $project = Project::create(['name' => 'Test']);

    expect($project->exists)->toBeTrue();
    $this->assertDatabaseHas('projects', ['name' => 'Test']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'created';
    });
});

it('create НЕ пишет в Redis напрямую', function () {
    Event::fake([RedisModelChanged::class]);

    $project = Project::create(['name' => 'Test']);

    $repository = app(RedisRepository::class);
    expect($repository->get("project:{$project->id}"))->toBeNull();
});

// ─── update() ───────────────────────────────────────────────

it('update записывает в Postgres и кидает event с dirty', function () {
    $project = Project::create(['name' => 'Old']);

    Event::fake([RedisModelChanged::class]);

    $project->update(['name' => 'New']);

    $this->assertDatabaseHas('projects', ['name' => 'New']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'updated'
            && in_array('name', $event->dirty);
    });
});

it('update с изменением FK кидает event с FK в dirty', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat1->id, 'title' => 'Task']);

    Event::fake([RedisModelChanged::class]);

    $task->update(['category_id' => $cat2->id]);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'updated'
            && in_array('category_id', $event->dirty);
    });
});

it('update без изменений не кидает event', function () {
    $project = Project::create(['name' => 'Test']);

    Event::fake([RedisModelChanged::class]);

    $project->update(['name' => 'Test']); // Same value

    Event::assertNotDispatched(RedisModelChanged::class);
});

it('update НЕ пишет в Redis напрямую', function () {
    $project = Project::create(['name' => 'Old']);
    // At this point, the created listener has written 'Old' to Redis

    Event::fake([RedisModelChanged::class]);

    $project->update(['name' => 'New']);

    $repository = app(RedisRepository::class);
    // Redis value must still be 'Old': Builder didn't update Redis,
    // only the faked-out listener would have done that.
    $cached = $repository->get("project:{$project->id}");
    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Old');
});

// ─── delete() ───────────────────────────────────────────────

it('delete удаляет из Postgres и кидает event', function () {
    $project = Project::create(['name' => 'Test']);
    $projectId = $project->id;

    Event::fake([RedisModelChanged::class]);

    $project->delete();

    $this->assertDatabaseMissing('projects', ['id' => $projectId]);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'deleted';
    });
});

it('delete НЕ пишет в Redis напрямую', function () {
    $project = Project::create(['name' => 'Test']);
    $projectId = $project->id;

    // Pre-populate Redis so we can verify it was NOT cleared by the Builder
    $repository = app(RedisRepository::class);
    $repository->set("project:{$projectId}", $project->getAttributes());

    Event::fake([RedisModelChanged::class]);

    $project->delete();

    // Redis entry must still be there — Builder should NOT touch Redis on delete
    expect($repository->get("project:{$projectId}"))->not->toBeNull();
});
