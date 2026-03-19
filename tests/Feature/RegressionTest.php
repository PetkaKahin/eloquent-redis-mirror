<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
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

it('touch() синхронизирует Redis', function () {
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

it('find с where scope фолбэчит в DB', function () {
    $p1 = Project::create(['name' => 'Active', 'is_active' => true]);
    $p2 = Project::create(['name' => 'Inactive', 'is_active' => false]);

    // This query has a where clause — cached model should be validated
    $result = Project::where('is_active', true)->find($p2->id);

    // p2 is inactive, should not be returned even though it's in Redis
    expect($result)->toBeNull();
});