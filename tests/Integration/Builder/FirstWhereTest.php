<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ─── first() с where('id', $value) — PK lookup из Redis ──────

it('where(id)->first() читает из Redis при тёплом кэше (zero SQL)', function () {
    $project = Project::create(['name' => 'Test']);

    // Модель уже в Redis через create() listener
    DB::enableQueryLog();

    $found = Project::where('id', $project->id)->first();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($project->id);
    expect($found->name)->toBe('Test');
});

it('where(table.id)->first() читает из Redis при тёплом кэше', function () {
    $project = Project::create(['name' => 'Test']);

    DB::enableQueryLog();

    $found = Project::where('projects.id', $project->id)->first();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($project->id);
});

it('where(id)->first() при cold start фолбэчит в SQL и кэширует', function () {
    $project = Project::create(['name' => 'Cold']);
    Redis::flushdb(); // cold start

    // First call — SQL
    $found = Project::where('id', $project->id)->first();
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Cold');

    // Second call — from Redis (zero SQL)
    DB::enableQueryLog();

    $found2 = Project::where('id', $project->id)->first();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($found2)->not->toBeNull();
    expect($found2->name)->toBe('Cold');
});

it('where(id)->first() возвращает null для несуществующей записи', function () {
    $found = Project::where('id', 99999)->first();
    expect($found)->toBeNull();
});

// ─── first() с non-PK where — fallback в SQL ─────────────────

it('where(name)->first() не использует Redis (non-PK where)', function () {
    $project = Project::create(['name' => 'Unique']);

    // Non-PK where — should go to SQL
    $found = Project::where('name', 'Unique')->first();

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($project->id);
});

it('where(id)->where(name)->first() не использует Redis (multiple wheres)', function () {
    $project = Project::create(['name' => 'Multi']);

    // PK + extra where — should go to SQL
    $found = Project::where('id', $project->id)->where('name', 'Multi')->first();

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($project->id);
});

// ─── get() с limit в контексте relation кэширует результат ───

it('relation->take(N)->get() при SQL fallback кэширует загруженные модели', function () {
    $project = Project::create(['name' => 'Test']);
    $cat1 = \PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category::create([
        'project_id' => $project->id,
        'name' => 'Cat 1',
    ]);

    // Flush and verify cold start
    Redis::flushdb();

    // This goes through get() with limit → SQL fallback, should cache
    $project->categories()->take(1)->get();

    // Model should now be cached in Redis
    $cached = $this->repository->get('category:' . $cat1->id);
    expect($cached)->not->toBeNull();
    expect($cached['name'])->toBe('Cat 1');
});
