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

// ─── Fix #1: get()/first() без relation context ────────────────

it('get() без relation context кеширует модели в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    Redis::flushdb(); // Очистить всё, включая данные от listeners

    // Первый вызов — SQL
    Project::where('name', 'Test')->get();

    // Модель должна быть в Redis
    $cached = $this->repository->get("project:{$project->id}");
    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Test');
});

it('get() без relation context — повторный вызов find() идёт из Redis (zero SQL)', function () {
    $project = Project::create(['name' => 'Test']);
    Redis::flushdb();

    // Прогрев через get()
    Project::where('name', 'Test')->get();

    // find() должен попасть в кеш
    DB::enableQueryLog();
    $found = Project::find($project->id);
    DB::flushQueryLog();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Test');
});

it('first() без relation context кеширует модель в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    Redis::flushdb();

    // Первый вызов — SQL
    Project::where('name', 'Test')->first();

    // Модель должна быть в Redis
    $cached = $this->repository->get("project:{$project->id}");
    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Test');
});

// ─── Fix #2: get() с relation context, cold start ──────────────

it('get() через relation при cold start прогревает индекс в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    Redis::flushdb(); // Полный cold start

    // Первый вызов — SQL + warm-up
    $categories = $project->categories()->get();
    expect($categories)->toHaveCount(2);

    // Индекс должен быть прогрет
    $indexKey = $project->getRedisIndexKey('categories');
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->not->toBeNull();
    expect($ids)->toHaveCount(2);
    expect($ids)->toContain((string) $cat1->id);
    expect($ids)->toContain((string) $cat2->id);
});

it('get() через relation при cold start — повторный вызов идёт из Redis (zero SQL)', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    Redis::flushdb();

    // Прогрев
    $project->categories()->get();

    // Повторный вызов — zero SQL
    DB::enableQueryLog();
    $fresh = Project::find($project->id);
    $categories = $fresh->categories()->get();
    DB::flushQueryLog();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($categories)->toHaveCount(2);
});

it('get() через relation при cold start с пустым результатом помечает warmed', function () {
    $project = Project::create(['name' => 'Test']);
    Redis::flushdb();

    // Пустой relation — должен пометить warmed
    $categories = $project->categories()->get();
    expect($categories)->toHaveCount(0);

    // Индекс warmed (пустой)
    $indexKey = $project->getRedisIndexKey('categories');
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->not->toBeNull();
    expect($ids)->toBeEmpty();
});

it('first() через relation при cold start кеширует модель', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    Redis::flushdb();

    // first() при cold start
    $first = $project->firstCategory()->first();
    expect($first)->not->toBeNull();
    expect($first->name)->toBe('Cat');

    // Модель закеширована
    $cached = $this->repository->get("category:{$cat->id}");
    expect($cached)->not->toBeNull();
});
