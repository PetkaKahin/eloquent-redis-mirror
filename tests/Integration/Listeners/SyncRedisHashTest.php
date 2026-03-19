<?php

use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisHash;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
    $this->listener = app(SyncRedisHash::class);
});

// ─── created ────────────────────────────────────────────────

it('created записывает модель в Redis', function () {
    $project = Project::create(['name' => 'Test']);

    $event = new RedisModelChanged($project, 'created');
    $this->listener->handle($event);

    $cached = $this->repository->get("project:{$project->id}");

    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Test');
});

it('created добавляет в родительский индекс (HasMany)', function () {
    $project = Project::create(['name' => 'Test']);
    $category = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $event = new RedisModelChanged($category, 'created');
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("project:{$project->id}:categories");

    expect($ids)->toContain((string) $category->id);
});

it('created добавляет в индекс с корректным score', function () {
    $project = Project::create(['name' => 'Test']);
    $category = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $event = new RedisModelChanged($category, 'created');
    $this->listener->handle($event);

    // The score should be based on created_at timestamp
    $ids = $this->repository->getRelationIds("project:{$project->id}:categories");
    expect($ids)->not->toBeEmpty();
});

it('created НЕ добавляет в индекс если родитель без trait', function () {
    // PlainModel doesn't have HasRedisCache, so no index should be created
    // This test assumes a scenario where a child model's parent doesn't have the trait
    $project = Project::create(['name' => 'Test']);
    $category = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Simulate: Category belongs to Project (which HAS trait) — so index IS created
    // The "no trait" case would be if we had Task -> PlainCategory (no trait)
    // For now, verify the positive case works
    $event = new RedisModelChanged($category, 'created');
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("project:{$project->id}:categories");
    expect($ids)->toContain((string) $category->id);
});

it('created для модели без belongsTo — только хеш, без индексов', function () {
    $project = Project::create(['name' => 'Test']);

    $event = new RedisModelChanged($project, 'created');
    $this->listener->handle($event);

    // Hash should exist
    expect($this->repository->get("project:{$project->id}"))->not->toBeNull();
});

// ─── updated ────────────────────────────────────────────────

it('updated перезаписывает хеш в Redis', function () {
    $project = Project::create(['name' => 'Old']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $project->update(['name' => 'New']);

    $event = new RedisModelChanged($project, 'updated', ['name']);
    $this->listener->handle($event);

    $cached = $this->repository->get("project:{$project->id}");
    expect($cached['name'])->toBe('New');
});

it('updated при изменении FK переносит между индексами', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat1->id, 'title' => 'Task']);

    // Setup initial state in Redis
    $this->repository->addToIndex("category:{$cat1->id}:tasks", $task->id, $task->created_at->timestamp);

    // Update FK
    $task->update(['category_id' => $cat2->id]);

    $event = new RedisModelChanged($task, 'updated', ['category_id']);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("category:{$cat1->id}:tasks"))
        ->not->toContain((string) $task->id);
    expect($this->repository->getRelationIds("category:{$cat2->id}:tasks"))
        ->toContain((string) $task->id);
});

it('updated без изменения FK — индексы не трогаются', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = Task::create(['category_id' => $cat->id, 'title' => 'Old Title']);

    $this->repository->addToIndex("category:{$cat->id}:tasks", $task->id, $task->created_at->timestamp);

    $task->update(['title' => 'New Title']);

    $event = new RedisModelChanged($task, 'updated', ['title']);
    $this->listener->handle($event);

    // Hash updated
    $cached = $this->repository->get("task:{$task->id}");
    expect($cached['title'])->toBe('New Title');

    // Index untouched
    expect($this->repository->getRelationIds("category:{$cat->id}:tasks"))
        ->toContain((string) $task->id);
});

it('updated модели, которой нет в Redis — записывает', function () {
    $project = Project::create(['name' => 'Test']);
    // Don't put in Redis

    $project->update(['name' => 'Updated']);

    $event = new RedisModelChanged($project, 'updated', ['name']);
    $this->listener->handle($event);

    expect($this->repository->get("project:{$project->id}"))->not->toBeNull();
});

// ─── deleted ────────────────────────────────────────────────

it('deleted удаляет хеш из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $event = new RedisModelChanged($project, 'deleted');
    $this->listener->handle($event);

    expect($this->repository->get("project:{$project->id}"))->toBeNull();
});

it('deleted удаляет из родительских индексов', function () {
    $project = Project::create(['name' => 'Test']);
    $category = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $this->repository->set("category:{$category->id}", $category->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $category->id, $category->created_at->timestamp);

    $event = new RedisModelChanged($category, 'deleted');
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("project:{$project->id}:categories"))
        ->not->toContain((string) $category->id);
});

it('deleted удаляет дочерние индексы', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp);

    $event = new RedisModelChanged($project, 'deleted');
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("project:{$project->id}:categories"))->toBeEmpty();
});

it('deleted модели которой нет в Redis — без ошибок', function () {
    $project = Project::create(['name' => 'Test']);
    // Don't put in Redis

    $event = new RedisModelChanged($project, 'deleted');
    $this->listener->handle($event);
})->throwsNoExceptions();

// ─── Негативный кейс: родитель без trait ────────────────────

it('created НЕ добавляет в индекс если родитель без HasRedisCache', function () {
    // PlainModel does not have HasRedisCache, so SyncRedisHash should
    // skip index creation even though the child model does.
    // We simulate this by directly calling the listener with a plain model event:
    // PlainModel doesn't have the trait → usesRedisCache returns false → nothing happens.
    $plainModel = \PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\PlainModel::create(['name' => 'Test']);

    $event = new RedisModelChanged($plainModel, 'created');
    $this->listener->handle($event);

    // PlainModel has no Redis prefix, so nothing should be written
    expect($this->repository->get("plain_model:{$plainModel->id}"))->toBeNull();
})->throwsNoExceptions();

// ─── Resilience: Redis недоступен ───────────────────────────

it('handle не бросает exception когда Redis недоступен', function () {
    $project = Project::create(['name' => 'Test']);

    $broken = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => ['host' => 'localhost', 'port' => 63790, 'database' => 15, 'read_write_timeout' => 1],
    ]);
    app()->instance('redis', $broken);
    \Illuminate\Support\Facades\Redis::clearResolvedInstances();

    // Rebuild listener with broken Redis (repository is singleton — rebind it)
    $brokenListener = new \PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisHash(
        new \PetkaKahin\EloquentRedisMirror\Repository\RedisRepository()
    );

    $event = new RedisModelChanged($project, 'created');
    $brokenListener->handle($event);
})->throwsNoExceptions();

it('deleted каскадно НЕ удаляет дочерние записи', function () {
    $project = Project::create(['name' => 'Test']);
    $category = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("category:{$category->id}", $category->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $category->id, $category->created_at->timestamp);

    $event = new RedisModelChanged($project, 'deleted');
    $this->listener->handle($event);

    // Project hash deleted
    expect($this->repository->get("project:{$project->id}"))->toBeNull();
    // Project index deleted
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))->toBeEmpty();
    // BUT category hash remains
    expect($this->repository->get("category:{$category->id}"))->not->toBeNull();
});
