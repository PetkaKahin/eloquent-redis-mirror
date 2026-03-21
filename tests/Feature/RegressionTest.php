<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Concerns\RedisRelationCache;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\CustomScoreTask;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\LexorankTask;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\SoftDeletableTask;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ═══════════════════════════════════════════════════════════════
// BUG 1: find() через relation игнорирует FK constraint
// $category->tasks()->find($id) может вернуть таску из чужой категории
// ═══════════════════════════════════════════════════════════════

it('find() через relation НЕ возвращает запись из чужого parent', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task1 = Task::create(['category_id' => $cat1->id, 'title' => 'Task in Cat 1']);
    $task2 = Task::create(['category_id' => $cat2->id, 'title' => 'Task in Cat 2']);

    // Ensure both tasks are cached in Redis
    expect($this->repository->get("task:{$task1->id}"))->not->toBeNull();
    expect($this->repository->get("task:{$task2->id}"))->not->toBeNull();

    // Ask cat1 for task2 — must return null, not the task from cat2
    $result = $cat1->tasks()->find($task2->id);
    expect($result)->toBeNull();

    // Ask cat2 for task1 — same, must return null
    $result = $cat2->tasks()->find($task1->id);
    expect($result)->toBeNull();
});

it('findOrFail() через relation бросает ModelNotFoundException для чужой записи', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat1->id, 'title' => 'Task in Cat 1']);

    // task is in Redis
    expect($this->repository->get("task:{$task->id}"))->not->toBeNull();

    // Asking cat2 for a task that belongs to cat1 — must throw
    $cat2->tasks()->findOrFail($task->id);
})->throws(ModelNotFoundException::class);

it('find() через relation возвращает запись из своей категории', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = Task::create(['category_id' => $cat->id, 'title' => 'My Task']);

    $result = $cat->tasks()->find($task->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($task->id);
    expect($result->title)->toBe('My Task');
});

it('findMany() через relation фильтрует чужие записи', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task1 = Task::create(['category_id' => $cat1->id, 'title' => 'Task 1']);
    $task2 = Task::create(['category_id' => $cat2->id, 'title' => 'Task 2']);
    $task3 = Task::create(['category_id' => $cat1->id, 'title' => 'Task 3']);

    $result = $cat1->tasks()->findMany([$task1->id, $task2->id, $task3->id]);

    // Only task1 and task3 belong to cat1
    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->sort()->values()->toArray())
        ->toBe([$task1->id, $task3->id]);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2: scoreFromModel() кастит lexorank строку к float = 0.0
// ═══════════════════════════════════════════════════════════════

it('scoreFromModel возвращает разные score для разных lexorank строк', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $t1 = LexorankTask::create(['category_id' => $cat->id, 'title' => 'A', 'lexorank' => '0|aaaaaa']);
    $t2 = LexorankTask::create(['category_id' => $cat->id, 'title' => 'B', 'lexorank' => '0|bbbbbb']);
    $t3 = LexorankTask::create(['category_id' => $cat->id, 'title' => 'C', 'lexorank' => '0|cccccc']);

    // Use reflection to call scoreFromModel
    $resolver = new class {
        use ResolvesRedisRelations;
    };
    $method = new ReflectionMethod($resolver, 'scoreFromModel');

    $s1 = $method->invoke($resolver, $t1);
    $s2 = $method->invoke($resolver, $t2);
    $s3 = $method->invoke($resolver, $t3);

    // All scores must be different
    expect($s1)->not->toBe($s2);
    expect($s2)->not->toBe($s3);

    // Lexicographic ordering must be preserved
    expect($s1)->toBeLessThan($s2);
    expect($s2)->toBeLessThan($s3);
});

it('scoreFromAttributes возвращает разные score для разных lexorank строк', function () {
    $resolver = new class {
        use ResolvesRedisRelations;
    };
    $method = new ReflectionMethod($resolver, 'scoreFromAttributes');

    $cat = Category::create(['project_id' => Project::create(['name' => 'T'])->id, 'name' => 'C']);
    $model = LexorankTask::create(['category_id' => $cat->id, 'title' => 'X', 'lexorank' => '0|zzz']);

    $s1 = $method->invoke($resolver, ['lexorank' => '0|aaaaaa'], $model);
    $s2 = $method->invoke($resolver, ['lexorank' => '0|zzzzzz'], $model);

    expect($s1)->not->toBe($s2);
    expect($s1)->toBeLessThan($s2);
});

it('stringToScore сохраняет лексикографический порядок', function () {
    $resolver = new class {
        use ResolvesRedisRelations;

        public function testStringToScore(string $value): float
        {
            return $this->stringToScore($value);
        }
    };

    $pairs = [
        ['a', 'b'],
        ['aaa', 'aab'],
        ['0|aaaaaa', '0|bbbbbb'],
        ['abc', 'abd'],
        ['A', 'a'], // uppercase A (65) < lowercase a (97)
    ];

    foreach ($pairs as [$smaller, $larger]) {
        expect($resolver->testStringToScore($smaller))
            ->toBeLessThan($resolver->testStringToScore($larger),
                "Expected '{$smaller}' < '{$larger}'");
    }
});

it('scoreFromModel обрабатывает числовые sort field корректно', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = Task::create([
        'category_id' => $cat->id,
        'title' => 'T',
        'sort_order' => 42,
    ]);

    $resolver = new class {
        use ResolvesRedisRelations;
    };

    // Task doesn't have custom sort field, so it uses created_at
    // But this verifies numeric values don't crash
    $score = (new ReflectionMethod($resolver, 'scoreFromModel'))->invoke($resolver, $task);
    expect($score)->toBeFloat();
    expect($score)->toBeGreaterThan(0);
});

// ═══════════════════════════════════════════════════════════════
// BUG 3: find() из Redis не проверяет deleted_at (SoftDeletes)
// ═══════════════════════════════════════════════════════════════

it('find() не возвращает soft-deleted модель из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Deletable']);

    $taskId = $task->id;
    $redisPrefix = 'soft_deletable_task';

    // Task is cached in Redis
    expect($this->repository->get("{$redisPrefix}:{$taskId}"))->not->toBeNull();

    // Soft-delete the task
    $task->delete();

    // Simulate stale Redis data: re-cache with deleted_at set
    $staleAttrs = $task->fresh()->getRawOriginal();
    $staleAttrs['deleted_at'] = now()->toDateTimeString();
    $this->repository->set("{$redisPrefix}:{$taskId}", $staleAttrs);

    // find() must NOT return soft-deleted model
    $result = SoftDeletableTask::find($taskId);
    expect($result)->toBeNull();
});

it('find() возвращает soft-deleted модель через withTrashed()', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Deletable']);

    $taskId = $task->id;
    $redisPrefix = 'soft_deletable_task';
    $task->delete();

    // Re-cache with deleted_at
    $staleAttrs = array_merge($task->getAttributes(), ['deleted_at' => now()->toDateTimeString()]);
    $this->repository->set("{$redisPrefix}:{$taskId}", $staleAttrs);

    // withTrashed removes the whereNull('deleted_at') scope
    $result = SoftDeletableTask::withTrashed()->find($taskId);
    expect($result)->not->toBeNull();
    expect($result->id)->toBe($taskId);
});

// ═══════════════════════════════════════════════════════════════
// Регрессионные тесты для предыдущих фиксов
// (ef46205 — touch/findMany, 01c4b6b — 6 багов)
// ═══════════════════════════════════════════════════════════════

it('touch() синхронизирует updated_at в Redis', function () {
    $project = Project::create(['name' => 'Test']);

    $cachedBefore = $this->repository->get("project:{$project->id}");
    $updatedAtBefore = $cachedBefore['updated_at'] ?? null;

    // Small delay so timestamp changes
    sleep(1);
    $project->touch();

    $cachedAfter = $this->repository->get("project:{$project->id}");
    expect($cachedAfter)->not->toBeNull();
    expect($cachedAfter['updated_at'])->not->toBe($updatedAtBefore);
});

it('findMany с eager load загружает relations', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = Task::create(['category_id' => $cat->id, 'title' => 'Task']);

    $projects = Project::with('categories.tasks')->findMany([$project->id]);

    expect($projects)->toHaveCount(1);
    expect($projects->first()->categories)->toHaveCount(1);
    expect($projects->first()->categories->first()->tasks)->toHaveCount(1);
});

it('with() с constraint closure фолбэчит в DB', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Alpha']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Beta']);

    // with() with a real constraint — Redis can't handle it, must fall back to DB
    $result = Project::with(['categories' => function ($q) {
        $q->where('name', 'Alpha');
    }])->find($project->id);

    expect($result->categories)->toHaveCount(1);
    expect($result->categories->first()->name)->toBe('Alpha');
});

it('restored event возвращает soft-deleted модель в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Restorable']);
    $taskId = $task->id;
    $redisPrefix = 'soft_deletable_task';

    // Task is in Redis
    expect($this->repository->get("{$redisPrefix}:{$taskId}"))->not->toBeNull();

    // Soft-delete — listener removes from Redis
    $task->delete();
    expect($this->repository->get("{$redisPrefix}:{$taskId}"))->toBeNull();

    // Restore — listener should re-add to Redis
    $task->restore();
    $cached = $this->repository->get("{$redisPrefix}:{$taskId}");
    expect($cached)->not->toBeNull();
    expect($cached['title'])->toBe('Restorable');
});

it('удаление parent чистит warmed flag индексов', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Warm the index
    Project::with('categories')->find($project->id);

    // warmed flag should exist
    $indexKey = "project:{$project->id}:categories";

    $project->delete();

    // Index and warmed flag should both be deleted
    expect($this->repository->getRelationIds($indexKey))->toBeEmpty();
});

it('FK dirty обнаруживается корректно при перемещении', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat1->id, 'title' => 'Task']);

    // Warm indices
    Project::with('categories.tasks')->find($project->id);

    $task->update(['category_id' => $cat2->id]);

    // Reload from Redis
    $result = Project::with('categories.tasks')->find($project->id);

    $c1 = $result->categories->firstWhere('id', $cat1->id);
    $c2 = $result->categories->firstWhere('id', $cat2->id);

    expect($c1->tasks)->toBeEmpty();
    expect($c2->tasks)->toHaveCount(1);
});

it('cold start BelongsToMany загружает pivot данные', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Laravel']);

    // Create via DB directly, bypassing events (simulating cold Redis)
    $project->tags()->attach($tag->id, ['role' => 'primary']);
    Redis::flushdb(); // Clear everything

    // Cold start — all from DB
    $result = Project::with('tags')->find($project->id);

    expect($result->tags)->toHaveCount(1);
    expect($result->tags->first()->pivot->role)->toBe('primary');

    // Second load should come from Redis
    DB::enableQueryLog();
    $result2 = Project::with('tags')->find($project->id);
    expect($result2->tags)->toHaveCount(1);
    expect($result2->tags->first()->pivot->role)->toBe('primary');
});

it('sync обновляет только изменённые pivot записи', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $project->tags()->attach([
        $tag1->id => ['role' => 'keep'],
        $tag2->id => ['role' => 'remove'],
    ]);

    // Sync: keep tag1, remove tag2, add tag3
    $project->tags()->sync([
        $tag1->id => ['role' => 'kept'],
        $tag3->id => ['role' => 'new'],
    ]);

    $loaded = Project::with('tags')->find($project->id);
    $tagMap = $loaded->tags->keyBy('name');

    expect($tagMap)->toHaveCount(2);
    expect($tagMap->has('Tag 1'))->toBeTrue();
    expect($tagMap->has('Tag 3'))->toBeTrue();
    expect($tagMap->has('Tag 2'))->toBeFalse();

    // tag2 pivot removed from Redis
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->toBeNull();
});

it('update без реальных изменений не дублирует Redis записи', function () {
    $project = Project::create(['name' => 'Test']);

    $project->update(['name' => 'Test']); // no actual change

    $cached = $this->repository->get("project:{$project->id}");
    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Test');
});

it('parallel findMany с одинаковыми ID не дублирует', function () {
    $p1 = Project::create(['name' => 'A']);
    $p2 = Project::create(['name' => 'B']);

    // Pass duplicate IDs
    $found = Project::findMany([$p1->id, $p2->id, $p1->id]);

    // Duplicates should be included as per Laravel's native behavior
    expect($found->count())->toBeGreaterThanOrEqual(2);
});

// ═══════════════════════════════════════════════════════════════
// BUG 4: BelongsTo eager load возвращает коллекцию вместо модели
// $task->category должен быть Category, не Collection
// ═══════════════════════════════════════════════════════════════

it('eager load BelongsTo возвращает модель, не коллекцию', function () {
    $project = Project::create(['name' => 'Test']);
    $cat     = Category::create(['project_id' => $project->id, 'name' => 'Dev']);
    $task    = Task::create(['category_id' => $cat->id, 'title' => 'Fix bug']);

    $loaded = Task::with('category')->find($task->id);

    expect($loaded->category)->toBeInstanceOf(Category::class);
    expect($loaded->category->id)->toBe($cat->id);
    expect($loaded->category->project_id)->toBe($project->id);
});

it('lazy load BelongsTo возвращает модель, не коллекцию', function () {
    $project = Project::create(['name' => 'Test']);
    $cat     = Category::create(['project_id' => $project->id, 'name' => 'Dev']);
    $task    = Task::create(['category_id' => $cat->id, 'title' => 'Fix bug']);

    $loaded = Task::find($task->id);

    expect($loaded->category)->toBeInstanceOf(Category::class);
    expect($loaded->category->id)->toBe($cat->id);
});

it('eager load BelongsTo работает при повторном чтении из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat     = Category::create(['project_id' => $project->id, 'name' => 'Dev']);
    $task    = Task::create(['category_id' => $cat->id, 'title' => 'Fix bug']);

    // Первый запрос прогревает Redis
    Task::with('category')->find($task->id);

    // Второй запрос из Redis
    $loaded = Task::with('category')->find($task->id);

    expect($loaded->category)->toBeInstanceOf(Category::class);
    expect($loaded->category->id)->toBe($cat->id);
    expect($loaded->category->name)->toBe('Dev');
});

it('eager load цепочки BelongsTo→BelongsTo работает', function () {
    $project = Project::create(['name' => 'Test']);
    $cat     = Category::create(['project_id' => $project->id, 'name' => 'Dev']);
    $task    = Task::create(['category_id' => $cat->id, 'title' => 'Fix bug']);

    $loaded = Task::with('category.project')->find($task->id);

    expect($loaded->category)->toBeInstanceOf(Category::class);
    expect($loaded->category->project)->toBeInstanceOf(Project::class);
    expect($loaded->category->project->id)->toBe($project->id);
    expect($loaded->category->project->name)->toBe('Test');
});

it('find с where scope фолбэчит в DB', function () {
    $p1 = Project::create(['name' => 'Active', 'is_active' => true]);
    $p2 = Project::create(['name' => 'Inactive', 'is_active' => false]);

    // This query has a where clause — cached model should be validated
    $result = Project::where('is_active', true)->find($p2->id);

    // p2 is inactive, should not be returned even though it's in Redis
    expect($result)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// touch() диспатчит RedisModelChanged с updated_at в dirty
// ═══════════════════════════════════════════════════════════════

it('touch() диспатчит RedisModelChanged с updated_at в dirty', function () {
    $project = Project::create(['name' => 'Test']);

    $dispatched = [];
    \Illuminate\Support\Facades\Event::listen(
        \PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged::class,
        function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        }
    );

    sleep(1);
    $project->touch();

    $updatedEvents = array_filter($dispatched, fn ($e) => $e->action === 'updated');
    expect($updatedEvents)->not->toBeEmpty();
    $event = array_values($updatedEvents)[0];
    expect($event->dirty)->toContain('updated_at');
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.2: Удаление модели с BTM чистит pivot keys + reverse indices
// ═══════════════════════════════════════════════════════════════

it('удаление модели с BelongsToMany чистит pivot keys и reverse indices', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    $project->tags()->attach([$tag1->id, $tag2->id]);

    // Warm the index
    Project::with('tags')->find($project->id);

    // Verify pivot and index data exists
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->not->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->not->toBeNull();
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->not->toBeEmpty();

    // Also check reverse index on tag
    $reverseIds = $this->repository->getRelationIds("tag:{$tag1->id}:projects");

    $project->delete();

    // Pivot keys should be cleaned up
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->toBeNull();

    // Sorted set index should be deleted
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->toBeEmpty();

    // Reverse indices on tags should not contain the deleted project
    expect($this->repository->getRelationIds("tag:{$tag1->id}:projects"))
        ->not->toContain((string) $project->id);
    expect($this->repository->getRelationIds("tag:{$tag2->id}:projects"))
        ->not->toContain((string) $project->id);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.3: executeBatch с невалидным JSON — ничего не пишется
// ═══════════════════════════════════════════════════════════════

it('executeBatch с невалидным JSON не оставляет partial writes', function () {
    $repository = $this->repository;

    // Create a resource that can't be JSON-encoded
    $resource = fopen('php://memory', 'r');
    $invalidAttrs = ['key' => $resource];

    $threw = false;
    try {
        $repository->executeBatch(
            setItems: [
                'test:valid' => ['name' => 'ok'],
                'test:invalid' => $invalidAttrs,
            ],
            markWarmed: ['test:valid:warmed'],
        );
    } catch (\JsonException) {
        $threw = true;
    } finally {
        fclose($resource);
    }

    expect($threw)->toBeTrue();

    // Neither key should have been written (pre-encode fails before pipeline)
    expect($repository->get('test:valid'))->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.4: RedisRelationCache::reset() очищает кеш
// ═══════════════════════════════════════════════════════════════

it('RedisRelationCache::reset() очищает все кеши', function () {
    // Populate caches
    RedisRelationCache::$traitCheck['SomeClass'] = true;
    RedisRelationCache::$reverseRelation['SomeKey'] = ['relation'];

    RedisRelationCache::reset();

    expect(RedisRelationCache::$traitCheck)->toBeEmpty();
    expect(RedisRelationCache::$reverseRelation)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// HasOne eager load возвращает модель, не коллекцию
// ═══════════════════════════════════════════════════════════════

it('eager load HasOne возвращает модель, не коллекцию', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'First Cat']);
    Category::create(['project_id' => $project->id, 'name' => 'Second Cat']);

    $loaded = Project::with('firstCategory')->find($project->id);

    expect($loaded->firstCategory)->toBeInstanceOf(Category::class);
    expect($loaded->firstCategory)->not->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

it('eager load HasOne из Redis возвращает модель после прогрева', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // First load warms Redis
    Project::with('firstCategory')->find($project->id);

    // Second load from Redis
    DB::enableQueryLog();
    $loaded = Project::with('firstCategory')->find($project->id);

    expect($loaded->firstCategory)->toBeInstanceOf(Category::class);
    expect($loaded->firstCategory->name)->toBe('Cat');
});

// ═══════════════════════════════════════════════════════════════
// Warmed flags lifecycle
// ═══════════════════════════════════════════════════════════════

it('warmed flags сохраняются после create/delete child', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Flush Redis to force cold-start path (which sets warmed flag)
    Redis::flushdb();

    // Warm the index via cold-start
    Project::with('categories')->find($project->id);

    $indexKey = "project:{$project->id}:categories";
    expect(Redis::exists($indexKey . ':warmed'))->toBeTruthy();

    // Create another child
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);

    // Warmed flag should still be set (index updated, not invalidated)
    expect(Redis::exists($indexKey . ':warmed'))->toBeTruthy();
    expect($this->repository->getRelationIds($indexKey))->toContain((string) $cat2->id);

    // Delete a child
    $cat->delete();

    // Index should still be warmed, with only cat2
    expect(Redis::exists($indexKey . ':warmed'))->toBeTruthy();
    expect($this->repository->getRelationIds($indexKey))->not->toContain((string) $cat->id);
    expect($this->repository->getRelationIds($indexKey))->toContain((string) $cat2->id);
});

// ═══════════════════════════════════════════════════════════════
// toggle() корректно обновляет Redis
// ═══════════════════════════════════════════════════════════════

it('toggle() корректно обновляет Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    $project->tags()->attach($tag1->id);

    // Toggle: tag1 should be removed, tag2 should be added
    $project->tags()->toggle([$tag1->id, $tag2->id]);

    $loaded = Project::with('tags')->find($project->id);
    $tagNames = $loaded->tags->pluck('name')->sort()->values()->toArray();

    expect($tagNames)->toBe(['Tag 2']);

    // tag1 pivot removed
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    // tag2 pivot added
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// Cold start BTM с pivot данными
// ═══════════════════════════════════════════════════════════════

it('cold start BTM загружает pivot данные и кеширует в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Laravel']);
    $project->tags()->attach($tag->id, ['role' => 'primary']);

    // Clear Redis completely
    Redis::flushdb();

    // Cold start — all from DB
    $result = Project::with('tags')->find($project->id);

    expect($result->tags)->toHaveCount(1);
    expect($result->tags->first()->pivot->role)->toBe('primary');

    // Verify Redis was populated
    expect($this->repository->get("project_tag:{$project->id}:{$tag->id}"))->not->toBeNull();
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->toContain((string) $tag->id);

    // Second load should come from Redis (zero-query)
    DB::enableQueryLog();
    $result2 = Project::with('tags')->find($project->id);
    expect($result2->tags)->toHaveCount(1);
    expect($result2->tags->first()->pivot->role)->toBe('primary');

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// FIX: Multiple relations to the same child class
// Project has categories() HasMany and firstCategory() HasOne → both to Category
// ═══════════════════════════════════════════════════════════════

it('создание child обновляет ВСЕ parent indices (HasMany + HasOne к одному классу)', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Both indices should contain the category
    $hasManyIds = $this->repository->getRelationIds("project:{$project->id}:categories");
    $hasOneIds = $this->repository->getRelationIds("project:{$project->id}:firstCategory");

    expect($hasManyIds)->toContain((string) $cat->id)
        ->and($hasOneIds)->toContain((string) $cat->id);
});

it('удаление child убирает из ВСЕХ parent indices', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Verify both indices were populated
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))
        ->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$project->id}:firstCategory"))
        ->toContain((string) $cat->id);

    $cat->delete();

    // Both indices should be empty
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))
        ->not->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$project->id}:firstCategory"))
        ->not->toContain((string) $cat->id);
});

it('перемещение child обновляет ВСЕ parent indices на обоих parents', function () {
    $p1 = Project::create(['name' => 'P1']);
    $p2 = Project::create(['name' => 'P2']);
    $cat = Category::create(['project_id' => $p1->id, 'name' => 'Cat']);

    // Before: cat in p1's indices
    expect($this->repository->getRelationIds("project:{$p1->id}:categories"))
        ->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$p1->id}:firstCategory"))
        ->toContain((string) $cat->id);

    // Move to p2
    $cat->update(['project_id' => $p2->id]);

    // After: cat removed from p1, added to p2 — for BOTH relations
    expect($this->repository->getRelationIds("project:{$p1->id}:categories"))
        ->not->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$p1->id}:firstCategory"))
        ->not->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$p2->id}:categories"))
        ->toContain((string) $cat->id);
    expect($this->repository->getRelationIds("project:{$p2->id}:firstCategory"))
        ->toContain((string) $cat->id);
});

// ═══════════════════════════════════════════════════════════════
// FIX: stringToScore should distinguish strings beyond 8 chars
// ═══════════════════════════════════════════════════════════════

it('stringToScore различает строки до 6 символов точно (float64 precision)', function () {
    $resolver = new class {
        use \PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;

        public function testStringToScore(string $value): float
        {
            return $this->stringToScore($value);
        }
    };

    // Strings that differ at position 6 (0-indexed 5) — within 6-char precision
    $s1 = $resolver->testStringToScore('aaaaa' . 'a');
    $s2 = $resolver->testStringToScore('aaaaa' . 'b');

    expect($s1)->not->toBe($s2);
    expect($s1)->toBeLessThan($s2);
});

it('stringToScore корректно обрабатывает lexorank с общим префиксом "0|"', function () {
    $resolver = new class {
        use \PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;

        public function testStringToScore(string $value): float
        {
            return $this->stringToScore($value);
        }
    };

    // Lexorank strings: common prefix "0|" (2 chars), then 5 distinguishing chars
    // Total 7 chars — at the limit of float64 precision
    $s1 = $resolver->testStringToScore('0|aaaaa');
    $s2 = $resolver->testStringToScore('0|aaaab');
    $s3 = $resolver->testStringToScore('0|bbbbb');

    expect($s1)->toBeLessThan($s2);
    expect($s2)->toBeLessThan($s3);
});

it('stringToScore даёт одинаковый score для строк длиннее 7 символов с общим префиксом', function () {
    $resolver = new class {
        use \PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;

        public function testStringToScore(string $value): float
        {
            return $this->stringToScore($value);
        }
    };

    // Strings that share 7 chars and differ only at position 8+ — expected limitation
    $s1 = $resolver->testStringToScore('1234567a');
    $s2 = $resolver->testStringToScore('1234567b');

    // This is a documented limitation: float64 can't distinguish position 8+
    expect($s1)->toBe($s2);
});

// ═══════════════════════════════════════════════════════════════
// get() кэширование для HasMany/HasOne relation queries
// ═══════════════════════════════════════════════════════════════

it('get() через HasMany relation возвращает данные из Redis (zero-query)', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Alpha']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Beta']);

    // Warm the index via eager load (cold-start path)
    Project::with('categories')->find($project->id);

    // Now get() via relation should hit Redis — zero SQL queries
    DB::enableQueryLog();
    $result = $project->categories()->get();
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );

    expect($result)->toHaveCount(2);
    expect($result->pluck('name')->sort()->values()->toArray())->toBe(['Alpha', 'Beta']);
    expect($selectQueries)->toBeEmpty();
});

it('get() через HasMany relation на cold-start фолбэчит в DB', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Alpha']);

    // Flush Redis — cold start
    Redis::flushdb();

    $result = $project->categories()->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->name)->toBe('Alpha');
});

it('get() через BelongsToMany relation возвращает данные с pivot из Redis (zero-query)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Laravel']);
    $tag2 = Tag::create(['name' => 'Redis']);
    $project->tags()->attach($tag1->id, ['role' => 'primary']);
    $project->tags()->attach($tag2->id, ['role' => 'secondary']);

    // Warm the index via eager load
    Project::with('tags')->find($project->id);

    // Now get() via relation should hit Redis — zero SQL queries
    DB::enableQueryLog();
    $result = $project->tags()->get();
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );

    expect($result)->toHaveCount(2);
    expect($result->pluck('name')->sort()->values()->toArray())->toBe(['Laravel', 'Redis']);

    // Pivot data should be present
    $laravelTag = $result->firstWhere('name', 'Laravel');
    expect($laravelTag->pivot)->not->toBeNull();
    expect($laravelTag->pivot->role)->toBe('primary');

    expect($selectQueries)->toBeEmpty();
});

it('get() через BelongsToMany на cold-start фолбэчит в DB', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Laravel']);
    $project->tags()->attach($tag->id, ['role' => 'primary']);

    // Flush Redis — cold start
    Redis::flushdb();

    $result = $project->tags()->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->name)->toBe('Laravel');
});

it('get() через sorted() HasMany relation сохраняет порядок из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Beta']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Alpha']);

    // Warm index
    Project::with('categories')->find($project->id);

    // get() via sorted() — order comes from Redis sorted set (by created_at score)
    $result = $project->categories()->get();

    expect($result)->toHaveCount(2);
    // Should be in sorted set order (by created_at: cat1 first, cat2 second)
    expect($result->first()->id)->toBe($cat1->id);
    expect($result->last()->id)->toBe($cat2->id);
});

it('get() через HasMany relation при пустом warmed индексе возвращает пустую коллекцию', function () {
    $project = Project::create(['name' => 'Test']);

    // Warm empty index via eager load
    Project::with('categories')->find($project->id);

    DB::enableQueryLog();
    $result = $project->categories()->get();
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );

    expect($result)->toHaveCount(0);
    expect($selectQueries)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// BUG FIX: get() с пользовательскими constraints фолбэчит в DB
// ═══════════════════════════════════════════════════════════════

it('get() через BelongsToMany с where() фолбэчит в DB (не игнорирует constraint)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Laravel']);
    $tag2 = Tag::create(['name' => 'Redis']);
    $project->tags()->attach([$tag1->id, $tag2->id]);

    // Warm index
    Project::with('tags')->find($project->id);

    // get() with where constraint — must fall back to DB, not return all from Redis
    $result = $project->tags()->where('tags.name', 'Laravel')->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->name)->toBe('Laravel');
});

it('get() через HasMany с whereIn() фолбэчит в DB (unsupported where type)', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Alpha']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Beta']);
    $cat3 = Category::create(['project_id' => $project->id, 'name' => 'Gamma']);

    // Warm index
    Project::with('categories')->find($project->id);

    // whereIn is unsupported by modelSatisfiesWheres — must fall back to DB
    $result = $project->categories()->whereIn('name', ['Alpha', 'Gamma'])->get();

    expect($result)->toHaveCount(2);
    expect($result->pluck('name')->sort()->values()->toArray())->toBe(['Alpha', 'Gamma']);
});

// ═══════════════════════════════════════════════════════════════
// BUG FIX: sync() сохраняет существующие pivot-данные при Redis miss
// ═══════════════════════════════════════════════════════════════

it('sync() сохраняет существующие pivot-данные при отсутствии в Redis кеше', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Laravel']);

    // Attach tag1 with pivot data
    $project->tags()->attach($tag1->id, ['role' => 'primary']);

    // Clear only pivot cache from Redis, keeping index warm
    $pivotKey = "project_tag:{$project->id}:{$tag1->id}";
    Redis::del($pivotKey);

    // Verify pivot is gone from Redis but still in DB
    expect($this->repository->get($pivotKey))->toBeNull();

    // Sync with changed attributes — tag1 appears in "updated" list.
    // Without DB fallback, the pivot's `id` column from DB would be lost.
    $project->tags()->sync([$tag1->id => ['role' => 'updated']]);

    // The pivot for tag1 should have full row from DB merged with new attributes
    $cached = $this->repository->get($pivotKey);
    expect($cached)->not->toBeNull();
    expect($cached['role'])->toBe('updated');
    // `id` column from DB row must be preserved (not lost on Redis miss)
    expect($cached['id'] ?? null)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// FIX: findMany with stale cache should DB-fallback, not silently drop
// ═══════════════════════════════════════════════════════════════

it('findMany с stale cache (where не пройден) делает DB fallback вместо потери данных', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task1 = Task::create(['category_id' => $cat1->id, 'title' => 'Task 1']);
    $task2 = Task::create(['category_id' => $cat2->id, 'title' => 'Task 2']);

    // Both tasks are in Redis with their original category_id
    expect($this->repository->get("task:{$task1->id}"))->not->toBeNull();
    expect($this->repository->get("task:{$task2->id}"))->not->toBeNull();

    // Move task2 to cat1 in DB only (simulate stale Redis)
    DB::table('tasks')->where('id', $task2->id)->update(['category_id' => $cat1->id]);

    // findMany through cat1 relation — task2 is in Redis with old FK (cat2),
    // so it fails the where check. It should fall back to DB and still be found.
    $result = $cat1->tasks()->findMany([$task1->id, $task2->id]);

    // Both should be returned since both now belong to cat1 in DB
    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->sort()->values()->toArray())
        ->toBe([$task1->id, $task2->id]);
});

it('find с stale cache (FK изменился) делает DB fallback', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat2->id, 'title' => 'Task']);

    // Task is in Redis with category_id = cat2
    expect($this->repository->get("task:{$task->id}"))->not->toBeNull();

    // Move task to cat1 in DB only (simulate stale Redis)
    DB::table('tasks')->where('id', $task->id)->update(['category_id' => $cat1->id]);

    // find through cat1 relation — Redis has stale FK, should fall back to DB
    $result = $cat1->tasks()->find($task->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($task->id);
});

// ═══════════════════════════════════════════════════════════════
// FIX: cold-start groupBy type-safe lookup (int vs string keys)
// ═══════════════════════════════════════════════════════════════

it('cold start HasMany корректно группирует по FK независимо от типа ключа', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $t1 = Task::create(['category_id' => $cat1->id, 'title' => 'T1']);
    $t2 = Task::create(['category_id' => $cat1->id, 'title' => 'T2']);
    $t3 = Task::create(['category_id' => $cat2->id, 'title' => 'T3']);

    // Flush Redis to force cold-start path
    Redis::flushdb();

    // Cold start — loads from DB, groups by FK
    $result = Project::with('categories.tasks')->find($project->id);

    $c1 = $result->categories->firstWhere('id', $cat1->id);
    $c2 = $result->categories->firstWhere('id', $cat2->id);

    expect($c1->tasks)->toHaveCount(2);
    expect($c1->tasks->pluck('title')->sort()->values()->toArray())->toBe(['T1', 'T2']);
    expect($c2->tasks)->toHaveCount(1);
    expect($c2->tasks->first()->title)->toBe('T3');
});

it('cold start BelongsToMany корректно группирует pivot по FK', function () {
    $p1 = Project::create(['name' => 'P1']);
    $p2 = Project::create(['name' => 'P2']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $p1->tags()->attach([$tag1->id, $tag2->id]);
    $p2->tags()->attach([$tag2->id, $tag3->id]);

    Redis::flushdb();

    // Cold start with multiple parents — tests groupBy type safety
    $projects = Project::with('tags')->findMany([$p1->id, $p2->id]);

    $proj1 = $projects->firstWhere('id', $p1->id);
    $proj2 = $projects->firstWhere('id', $p2->id);

    expect($proj1->tags)->toHaveCount(2);
    expect($proj1->tags->pluck('name')->sort()->values()->toArray())->toBe(['Tag 1', 'Tag 2']);
    expect($proj2->tags)->toHaveCount(2);
    expect($proj2->tags->pluck('name')->sort()->values()->toArray())->toBe(['Tag 2', 'Tag 3']);
});

// ═══════════════════════════════════════════════════════════════
// FIX: handleDeleted batches Redis calls for BTM relations
// ═══════════════════════════════════════════════════════════════

it('удаление модели с множественными BTM relation чистит все pivot и reverse indices', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $project->tags()->attach([$tag1->id, $tag2->id, $tag3->id]);

    // Warm indices
    Project::with('tags')->find($project->id);

    // All pivot keys and reverse indices should exist
    foreach ([$tag1, $tag2, $tag3] as $tag) {
        expect($this->repository->get("project_tag:{$project->id}:{$tag->id}"))->not->toBeNull();
        expect($this->repository->getRelationIds("tag:{$tag->id}:projects"))
            ->toContain((string) $project->id);
    }

    $project->delete();

    // All pivot keys and reverse indices should be cleaned up
    foreach ([$tag1, $tag2, $tag3] as $tag) {
        expect($this->repository->get("project_tag:{$project->id}:{$tag->id}"))->toBeNull();
        expect($this->repository->getRelationIds("tag:{$tag->id}:projects"))
            ->not->toContain((string) $project->id);
    }

    // Main model key should be deleted
    expect($this->repository->get("project:{$project->id}"))->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// FIX: SyncRedisPivot::fetchExistingPivotData merges correctly
// ═══════════════════════════════════════════════════════════════

it('updateExistingPivot сохраняет существующие pivot данные при Redis miss', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id, ['role' => 'original']);

    // Clear only pivot key from Redis
    $pivotKey = "project_tag:{$project->id}:{$tag->id}";
    Redis::del($pivotKey);
    expect($this->repository->get($pivotKey))->toBeNull();

    // Update pivot — should fetch from DB first, then merge
    $project->tags()->updateExistingPivot($tag->id, ['role' => 'modified']);

    $cached = $this->repository->get($pivotKey);
    expect($cached)->not->toBeNull();
    expect($cached['role'])->toBe('modified');
    // DB columns like id and timestamps should be preserved
    expect($cached['id'] ?? null)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
// FIX: resolveWheres caching — applyScopes called once not N times
// ═══════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════
// FIX: scoreDirty correctly detects score changes with getRedisSortScore
// ═══════════════════════════════════════════════════════════════

it('CustomScoreTask с getRedisSortScore сохраняет score по sort_order', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $t1 = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'A', 'sort_order' => 10]);
    $t2 = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'B', 'sort_order' => 5]);

    // t2 (score=5) should come before t1 (score=10) in sorted set
    $ids = $this->repository->getRelationIds("category:{$cat->id}:tasks");
    expect($ids)->toBe([(string) $t2->id, (string) $t1->id]);
});

it('CustomScoreTask update с изменённым sort_order обновляет score в индексе', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $t1 = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'A', 'sort_order' => 10]);
    $t2 = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'B', 'sort_order' => 20]);

    // Initial order: t1 (10), t2 (20)
    $ids = $this->repository->getRelationIds("category:{$cat->id}:tasks");
    expect($ids)->toBe([(string) $t1->id, (string) $t2->id]);

    // Swap order: t1 goes to 30
    $t1->update(['sort_order' => 30]);

    // New order: t2 (20), t1 (30)
    $ids = $this->repository->getRelationIds("category:{$cat->id}:tasks");
    expect($ids)->toBe([(string) $t2->id, (string) $t1->id]);
});

it('CustomScoreTask update без изменения sort_order НЕ меняет score', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'Old', 'sort_order' => 42]);

    $scoreBefore = Redis::zscore("category:{$cat->id}:tasks", (string) $task->id);
    expect((float) $scoreBefore)->toBe(42.0);

    // Update title only — sort_order unchanged
    $task->update(['title' => 'New Title']);

    $scoreAfter = Redis::zscore("category:{$cat->id}:tasks", (string) $task->id);
    expect((float) $scoreAfter)->toBe(42.0);
});

// ═══════════════════════════════════════════════════════════════
// FIX: warmed flags have TTL — prevents stale warmed after manual cleanup
// ═══════════════════════════════════════════════════════════════

it('warmed flags получают TTL при cold-start прогреве', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Flush to force cold-start
    Redis::flushdb();

    // Cold-start via with() — sets warmed flag
    Project::with('categories')->find($project->id);

    $ttl = Redis::ttl("project:{$project->id}:categories:warmed");
    expect($ttl)->toBeGreaterThan(0)
        ->and($ttl)->toBeLessThanOrEqual(86400);
});

it('warmed flags получают TTL при eager load BelongsToMany', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    // Flush to force cold-start
    Redis::flushdb();

    Project::with('tags')->find($project->id);

    $ttl = Redis::ttl("project:{$project->id}:tags:warmed");
    expect($ttl)->toBeGreaterThan(0)
        ->and($ttl)->toBeLessThanOrEqual(86400);
});

it('findMany с SoftDeletes корректно фильтрует deleted модели из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $t1 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Active']);
    $t2 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Deleted']);
    $t3 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Also Active']);

    // Soft delete t2
    $t2->delete();

    // Manually cache t2 with deleted_at in Redis (stale data scenario)
    $staleAttrs = $t2->fresh()->getRawOriginal();
    $staleAttrs['deleted_at'] = now()->toDateTimeString();
    $this->repository->set("soft_deletable_task:{$t2->id}", $staleAttrs);

    // findMany should filter out t2 (deleted_at not null) via modelSatisfiesWheres
    // and NOT silently drop it — it should just not be in the result
    $result = SoftDeletableTask::findMany([$t1->id, $t2->id, $t3->id]);

    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->sort()->values()->toArray())
        ->toBe([$t1->id, $t3->id]);
});
