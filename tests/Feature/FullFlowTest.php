<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

it('полный цикл: create → find из Redis', function () {
    $project = Project::create(['name' => 'Test']);

    // Listener should have written to Redis
    DB::enableQueryLog();

    $found = Project::find($project->id);

    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test');
    // Should be from Redis — no SELECT queries
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
});

it('полный цикл: create → update → find актуальных данных', function () {
    $project = Project::create(['name' => 'Old']);
    $project->update(['name' => 'Updated']);

    $found = Project::find($project->id);

    expect($found->name)->toBe('Updated');
});

it('полный цикл: create → delete → find возвращает null', function () {
    $project = Project::create(['name' => 'Test']);
    $projectId = $project->id;

    $project->delete();

    expect(Project::find($projectId))->toBeNull();
    expect($this->repository->get("project:{$projectId}"))->toBeNull();
});

it('полный цикл: create parent + children → with() из Redis', function () {
    $project = Project::create(['name' => 'Test']);

    $categories = collect();
    for ($i = 1; $i <= 3; $i++) {
        $cat = Category::create(['project_id' => $project->id, 'name' => "Category {$i}"]);
        $categories->push($cat);

        // Create tasks for first 2 categories
        if ($i <= 2) {
            for ($j = 1; $j <= 2; $j++) {
                Task::create(['category_id' => $cat->id, 'title' => "Task {$i}-{$j}"]);
            }
        }
    }

    // All data should be in Redis via listeners
    DB::enableQueryLog();

    $result = Project::with('categories.tasks')->find($project->id);

    expect($result)->not->toBeNull();
    expect($result->categories)->toHaveCount(3);

    $totalTasks = $result->categories->sum(fn ($c) => $c->tasks->count());
    expect($totalTasks)->toBe(4);
});

it('полный цикл: перемещение task между категориями', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $task = Task::create(['category_id' => $cat1->id, 'title' => 'Movable Task']);

    // Move task from cat1 to cat2
    $task->update(['category_id' => $cat2->id]);

    $result = Project::with('categories.tasks')->find($project->id);

    $cat1Tasks = $result->categories->firstWhere('id', $cat1->id)->tasks;
    $cat2Tasks = $result->categories->firstWhere('id', $cat2->id)->tasks;

    expect($cat1Tasks)->toBeEmpty();
    expect($cat2Tasks)->toHaveCount(1);
    expect($cat2Tasks->first()->title)->toBe('Movable Task');
});

it('полный цикл: attach/detach pivot с pivot-данными', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Laravel']);

    $project->tags()->attach($tag->id, ['role' => 'primary']);

    // Verify pivot cached in Redis
    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");
    expect($pivotData)->not->toBeNull();
    expect($pivotData['role'])->toBe('primary');

    $loaded = Project::with('tags')->find($project->id);
    expect($loaded->tags)->toHaveCount(1);
    expect($loaded->tags->first()->name)->toBe('Laravel');
    expect($loaded->tags->first()->pivot->role)->toBe('primary');

    $project->tags()->detach($tag->id);

    // Pivot record should be deleted
    expect($this->repository->get("project_tag:{$project->id}:{$tag->id}"))->toBeNull();

    $loaded = Project::with('tags')->find($project->id);
    expect($loaded->tags)->toBeEmpty();
});

it('полный цикл: sync pivot обновляет pivot-записи', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $project->tags()->attach([$tag1->id, $tag2->id]);

    $project->tags()->sync([$tag2->id, $tag3->id]);

    $loaded = Project::with('tags')->find($project->id);
    $tagNames = $loaded->tags->pluck('name')->sort()->values()->toArray();

    expect($tagNames)->toBe(['Tag 2', 'Tag 3']);

    // tag1 pivot removed from Redis
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
});

it('полный цикл: updateExistingPivot обновляет Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $project->tags()->attach([$tag->id => ['role' => 'old']]);

    $project->tags()->updateExistingPivot($tag->id, ['role' => 'new']);

    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");
    expect($pivotData['role'])->toBe('new');
});

it('полный цикл: with(tags) загружает pivot-данные из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    $project->tags()->attach([
        $tag1->id => ['role' => 'primary'],
        $tag2->id => ['role' => 'secondary'],
    ]);

    DB::enableQueryLog();

    $loaded = Project::with('tags')->find($project->id);

    expect($loaded->tags)->toHaveCount(2);

    $tagsByName = $loaded->tags->keyBy('name');
    expect($tagsByName['Tag 1']->pivot->role)->toBe('primary');
    expect($tagsByName['Tag 2']->pivot->role)->toBe('secondary');
});

it('полный цикл: cold start (Redis пуст)', function () {
    // Create data directly in DB, bypassing events
    $project = Project::withoutEvents(fn () => Project::create(['name' => 'Test']));
    $cat = Category::withoutEvents(fn () => Category::create([
        'project_id' => $project->id,
        'name' => 'Cat',
    ]));
    $task = Task::withoutEvents(fn () => Task::create([
        'category_id' => $cat->id,
        'title' => 'Task',
    ]));

    // Redis is completely empty
    expect($this->repository->get("project:{$project->id}"))->toBeNull();

    // First load — from DB, writes to Redis
    $result = Project::with('categories.tasks')->find($project->id);

    expect($result)->not->toBeNull();
    expect($result->categories)->toHaveCount(1);
    expect($result->categories->first()->tasks)->toHaveCount(1);

    // Now Redis should be populated
    expect($this->repository->get("project:{$project->id}"))->not->toBeNull();
    expect($this->repository->get("category:{$cat->id}"))->not->toBeNull();

    // Second load — from Redis
    DB::enableQueryLog();

    $result2 = Project::with('categories.tasks')->find($project->id);

    expect($result2)->not->toBeNull();
    expect($result2->categories)->toHaveCount(1);

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
});

it('полный цикл: удаление parent чистит индексы', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Verify index exists
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))
        ->toContain((string) $cat->id);

    $project->delete();

    // Project hash deleted
    expect($this->repository->get("project:{$project->id}"))->toBeNull();
    // Project index deleted
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))->toBeEmpty();
    // Category hash remains (cascade is app/DB responsibility)
    expect($this->repository->get("category:{$cat->id}"))->not->toBeNull();
});

it('полный цикл: массовое создание и чтение', function () {
    $project = Project::create(['name' => 'Big Project']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Main']);

    $tasks = collect();
    for ($i = 1; $i <= 100; $i++) {
        $tasks->push(Task::create(['category_id' => $cat->id, 'title' => "Task {$i}"]));
    }

    DB::enableQueryLog();

    $result = Project::with('categories.tasks')->find($project->id);

    expect($result->categories->first()->tasks)->toHaveCount(100);
});

it('полный цикл: параллельные обновления одной записи', function () {
    $project = Project::create(['name' => 'Original']);

    // Simulate two sequential updates (parallel in real life)
    $project->update(['name' => 'Update 1']);
    $project->update(['name' => 'Update 2']);

    $found = Project::find($project->id);

    // Last write wins
    expect($found->name)->toBe('Update 2');

    // Redis and DB should be consistent
    $fromDb = Project::query()->where('id', $project->id)->first();
    expect($found->name)->toBe($fromDb->name);
});
