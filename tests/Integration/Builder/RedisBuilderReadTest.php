<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\PlainModel;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ─── find() ─────────────────────────────────────────────────

it('find возвращает модель из Redis (cache hit)', function () {
    $project = Project::create(['name' => 'Test']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    DB::enableQueryLog();

    $found = Project::find($project->id);

    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test');
    expect(DB::getQueryLog())->toBeEmpty('Expected no DB queries on cache hit');
});

it('find идёт в Postgres при cache miss и пишет в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    Redis::flushdb(); // Clear any data written by listeners

    DB::enableQueryLog();

    $found = Project::find($project->id);

    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test');
    expect(DB::getQueryLog())->not->toBeEmpty('Expected DB query on cache miss');
    expect($this->repository->get("project:{$project->id}"))->not->toBeNull();
});

it('find возвращает null для несуществующей записи', function () {
    $result = Project::find(999);

    expect($result)->toBeNull();
    expect($this->repository->get('project:999'))->toBeNull();
});

it('find возвращает полноценную Eloquent-модель с casts', function () {
    $project = Project::create([
        'name' => 'Test',
        'metadata' => ['priority' => 'high'],
        'is_active' => true,
    ]);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $found = Project::find($project->id);

    expect($found->is_active)->toBeBool();
});

it('find возвращает модель с корректными timestamps', function () {
    $project = Project::create(['name' => 'Test']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $found = Project::find($project->id);

    expect($found->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($found->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('find гидратит модель как exists=true', function () {
    $project = Project::create(['name' => 'Test']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $found = Project::find($project->id);

    expect($found->exists)->toBeTrue();
});

// ─── findMany() ─────────────────────────────────────────────

it('findMany возвращает все модели из Redis (все hit)', function () {
    $projects = collect([
        Project::create(['name' => 'First']),
        Project::create(['name' => 'Second']),
        Project::create(['name' => 'Third']),
    ]);

    foreach ($projects as $project) {
        $this->repository->set("project:{$project->id}", $project->getAttributes());
    }

    DB::enableQueryLog();

    $found = Project::findMany($projects->pluck('id')->toArray());

    expect($found)->toHaveCount(3);
    expect(DB::getQueryLog())->toBeEmpty();
});

it('findMany делает fallback для промахов', function () {
    $p1 = Project::create(['name' => 'First']);
    $p2 = Project::create(['name' => 'Second']);
    $p3 = Project::create(['name' => 'Third']);

    // Only p1 in Redis
    $this->repository->set("project:{$p1->id}", $p1->getAttributes());
    // p2 and p3 only in DB — clear Redis for them
    $this->repository->delete("project:{$p2->id}");
    $this->repository->delete("project:{$p3->id}");

    DB::enableQueryLog();

    $found = Project::findMany([$p1->id, $p2->id, $p3->id]);

    expect($found)->toHaveCount(3);

    // p2 and p3 should now be cached in Redis
    expect($this->repository->get("project:{$p2->id}"))->not->toBeNull();
    expect($this->repository->get("project:{$p3->id}"))->not->toBeNull();
});

it('findMany с полностью пустым Redis', function () {
    $p1 = Project::create(['name' => 'First']);
    $p2 = Project::create(['name' => 'Second']);
    $p3 = Project::create(['name' => 'Third']);
    Redis::flushdb();

    $found = Project::findMany([$p1->id, $p2->id, $p3->id]);

    expect($found)->toHaveCount(3);

    // All should now be cached
    expect($this->repository->get("project:{$p1->id}"))->not->toBeNull();
    expect($this->repository->get("project:{$p2->id}"))->not->toBeNull();
    expect($this->repository->get("project:{$p3->id}"))->not->toBeNull();
});

it('findMany с пустым массивом', function () {
    $found = Project::findMany([]);

    expect($found)->toBeEmpty();
});

it('findMany с частично несуществующими ID', function () {
    $p1 = Project::create(['name' => 'First']);
    $p2 = Project::create(['name' => 'Second']);

    $found = Project::findMany([$p1->id, $p2->id, 999]);

    expect($found)->toHaveCount(2);
});

it('findMany сохраняет порядок ID', function () {
    $p1 = Project::create(['name' => 'First']);
    $p2 = Project::create(['name' => 'Second']);
    $p3 = Project::create(['name' => 'Third']);

    $found = Project::findMany([$p3->id, $p1->id, $p2->id]);

    expect($found[0]->id)->toBe($p3->id);
    expect($found[1]->id)->toBe($p1->id);
    expect($found[2]->id)->toBe($p2->id);
});

// ─── Eager loading — with() ────────────────────────────────

it('with() загружает HasMany relation из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);

    // Ensure data is in Redis
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("category:{$cat1->id}", $cat1->getAttributes());
    $this->repository->set("category:{$cat2->id}", $cat2->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat1->id, $cat1->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:categories", $cat2->id, $cat2->created_at->timestamp);

    DB::enableQueryLog();

    $result = Project::with('categories')->find($project->id);

    expect($result->categories)->toHaveCount(2);
    expect(DB::getQueryLog())->toBeEmpty();
});

it('with() делает fallback для промахов в relation', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);

    // Only project and cat1 in Redis, cat2 only in DB
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("category:{$cat1->id}", $cat1->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat1->id, $cat1->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:categories", $cat2->id, $cat2->created_at->timestamp);

    $result = Project::with('categories')->find($project->id);

    expect($result->categories)->toHaveCount(2);
    // cat2 should now be in Redis
    expect($this->repository->get("category:{$cat2->id}"))->not->toBeNull();
});

it('with() при пустом индексе — возвращает пустую коллекцию', function () {
    $project = Project::create(['name' => 'Test']);
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    // Empty index — no categories added

    $result = Project::with('categories')->find($project->id);

    expect($result->categories)->toBeEmpty();
});

it('with() при отсутствующем индексе — fallback в Postgres', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);

    // Project in Redis but NO index
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    $result = Project::with('categories')->find($project->id);

    expect($result->categories)->toHaveCount(1);
    // Index should now exist in Redis
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))->toContain((string) $cat->id);
});

it('nested with(categories.tasks) загружает из Redis рекурсивно', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task1 = Task::create(['category_id' => $cat->id, 'title' => 'Task 1']);
    $task2 = Task::create(['category_id' => $cat->id, 'title' => 'Task 2']);

    // Put everything in Redis
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("category:{$cat->id}", $cat->getAttributes());
    $this->repository->set("task:{$task1->id}", $task1->getAttributes());
    $this->repository->set("task:{$task2->id}", $task2->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp);
    $this->repository->addToIndex("category:{$cat->id}:tasks", $task1->id, $task1->created_at->timestamp);
    $this->repository->addToIndex("category:{$cat->id}:tasks", $task2->id, $task2->created_at->timestamp);

    DB::enableQueryLog();

    $result = Project::with('categories.tasks')->find($project->id);

    expect($result->categories)->toHaveCount(1);
    expect($result->categories->first()->tasks)->toHaveCount(2);
    expect(DB::getQueryLog())->toBeEmpty();
});

it('with() с несколькими relations', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    // Put in Redis
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("category:{$cat->id}", $cat->getAttributes());
    $this->repository->set("tag:{$tag->id}", $tag->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:tags", $tag->id, $tag->created_at->timestamp);

    DB::enableQueryLog();

    $result = Project::with(['categories', 'tags'])->find($project->id);

    expect($result->categories)->toHaveCount(1);
    expect($result->tags)->toHaveCount(1);
    expect(DB::getQueryLog())->toBeEmpty();
});

it('with() загружает BelongsToMany с pivot-данными из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Put models in Redis
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("tag:{$tag1->id}", $tag1->getAttributes());
    $this->repository->set("tag:{$tag2->id}", $tag2->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, $tag1->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:tags", $tag2->id, $tag2->created_at->timestamp);

    // Put pivot data in Redis
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
        'role' => 'primary',
    ]);
    $this->repository->set("project_tag:{$project->id}:{$tag2->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
        'role' => 'secondary',
    ]);

    DB::enableQueryLog();

    $result = Project::with('tags')->find($project->id);

    expect($result->tags)->toHaveCount(2);

    $tagsByName = $result->tags->keyBy('name');
    expect($tagsByName['Tag 1']->pivot->role)->toBe('primary');
    expect($tagsByName['Tag 2']->pivot->role)->toBe('secondary');

    expect(DB::getQueryLog())->toBeEmpty();
});

it('with() BelongsToMany fallback при отсутствии pivot-записей в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Put models and index in Redis, but NO pivot records
    $this->repository->set("project:{$project->id}", $project->getAttributes());
    $this->repository->set("tag:{$tag->id}", $tag->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:tags", $tag->id, $tag->created_at->timestamp);

    // Attach in DB (this also writes to Redis via listener, so flush pivot)
    $project->tags()->attach($tag->id, ['role' => 'admin']);
    $this->repository->delete("project_tag:{$project->id}:{$tag->id}");

    $result = Project::with('tags')->find($project->id);

    expect($result->tags)->toHaveCount(1);
    // Pivot should still have basic FK data even without Redis cache
    expect($result->tags->first()->pivot)->not->toBeNull();
});

it('with() не ломает стандартное поведение для не-Redis моделей', function () {
    PlainModel::create(['name' => 'Test']);

    // PlainModel doesn't have Redis trait, should use standard Eloquent
    $result = PlainModel::query()->first();

    expect($result)->not->toBeNull();
});

// ─── first() через relation ────────────────────────────────

it('first() через relation возвращает одну модель из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $cat3 = Category::create(['project_id' => $project->id, 'name' => 'Cat 3']);

    // Put in Redis
    $this->repository->set("category:{$cat1->id}", $cat1->getAttributes());
    $this->repository->set("category:{$cat2->id}", $cat2->getAttributes());
    $this->repository->set("category:{$cat3->id}", $cat3->getAttributes());
    $this->repository->addToIndex("project:{$project->id}:categories", $cat1->id, $cat1->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:categories", $cat2->id, $cat2->created_at->timestamp);
    $this->repository->addToIndex("project:{$project->id}:categories", $cat3->id, $cat3->created_at->timestamp);

    $result = $project->categories()->first();

    expect($result)->not->toBeNull();
    expect($result)->toBeInstanceOf(Category::class);
});

it('first() при пустом индексе возвращает null', function () {
    $project = Project::create(['name' => 'Test']);

    // Empty index
    $result = $project->categories()->first();

    expect($result)->toBeNull();
});

it('first() без relation-контекста идёт в Postgres', function () {
    Project::create(['name' => 'Test']);

    DB::enableQueryLog();

    $result = Project::query()->first();

    expect($result)->not->toBeNull();
    expect(DB::getQueryLog())->not->toBeEmpty();
});

// ─── paginate() через relation ─────────────────────────────

it('paginate возвращает LengthAwarePaginator из Redis', function () {
    $project = Project::create(['name' => 'Test']);

    for ($i = 1; $i <= 10; $i++) {
        $cat = Category::create(['project_id' => $project->id, 'name' => "Cat {$i}"]);
        $this->repository->set("category:{$cat->id}", $cat->getAttributes());
        $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp + $i);
    }

    $paginator = $project->categories()->paginate(3);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class);
    expect($paginator->items())->toHaveCount(3);
    expect($paginator->total())->toBe(10);
    expect($paginator->lastPage())->toBe(4);
});

it('paginate вторая страница', function () {
    $project = Project::create(['name' => 'Test']);

    for ($i = 1; $i <= 10; $i++) {
        $cat = Category::create(['project_id' => $project->id, 'name' => "Cat {$i}"]);
        $this->repository->set("category:{$cat->id}", $cat->getAttributes());
        $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp + $i);
    }

    $paginator = $project->categories()->paginate(3, ['*'], 'page', 2);

    expect($paginator->items())->toHaveCount(3);
});

it('paginate последняя неполная страница', function () {
    $project = Project::create(['name' => 'Test']);

    for ($i = 1; $i <= 10; $i++) {
        $cat = Category::create(['project_id' => $project->id, 'name' => "Cat {$i}"]);
        $this->repository->set("category:{$cat->id}", $cat->getAttributes());
        $this->repository->addToIndex("project:{$project->id}:categories", $cat->id, $cat->created_at->timestamp + $i);
    }

    $paginator = $project->categories()->paginate(3, ['*'], 'page', 4);

    expect($paginator->items())->toHaveCount(1);
});

it('paginate пустого индекса', function () {
    $project = Project::create(['name' => 'Test']);

    $paginator = $project->categories()->paginate(3);

    expect($paginator->items())->toBeEmpty();
    expect($paginator->total())->toBe(0);
});

// ─── Fallback при недоступном Redis ─────────────────────────

it('find работает через Postgres когда Redis недоступен', function () {
    $project = Project::create(['name' => 'Test']);

    $broken = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => ['host' => 'localhost', 'port' => 63790, 'database' => 15, 'read_write_timeout' => 1],
    ]);
    app()->instance('redis', $broken);
    Redis::clearResolvedInstances();

    $found = Project::find($project->id);

    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test');
});

it('findMany работает через Postgres когда Redis недоступен', function () {
    $p1 = Project::create(['name' => 'First']);
    $p2 = Project::create(['name' => 'Second']);

    $broken = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => ['host' => 'localhost', 'port' => 63790, 'database' => 15, 'read_write_timeout' => 1],
    ]);
    app()->instance('redis', $broken);
    Redis::clearResolvedInstances();

    $found = Project::findMany([$p1->id, $p2->id]);

    expect($found)->toHaveCount(2);
});

it('with() работает через Postgres когда Redis недоступен', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $broken = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => ['host' => 'localhost', 'port' => 63790, 'database' => 15, 'read_write_timeout' => 1],
    ]);
    app()->instance('redis', $broken);
    Redis::clearResolvedInstances();

    $result = Project::with('categories')->find($project->id);

    expect($result)->not->toBeNull();
    expect($result->categories)->toHaveCount(1);
});
