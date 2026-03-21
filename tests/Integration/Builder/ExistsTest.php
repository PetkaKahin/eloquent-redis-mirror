<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ─── exists() через HasMany (RedisBuilder) ─────────────────────

it('exists() через HasMany возвращает true из Redis (zero SQL)', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Данные в Redis через listeners
    DB::enableQueryLog();

    $exists = $project->categories()->exists();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($exists)->toBeTrue();
});

it('exists() через HasMany возвращает false из Redis для пустого warmed индекса', function () {
    $project = Project::create(['name' => 'Test']);
    // Пометить индекс warmed но пустой
    $indexKey = $project->getRedisIndexKey('categories');
    $this->repository->executeBatch(markWarmed: [$indexKey]);

    DB::enableQueryLog();

    $exists = $project->categories()->exists();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($exists)->toBeFalse();
});

it('exists() через HasMany при cold start фолбэчит в SQL', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    Redis::flushdb(); // cold start

    $exists = $project->categories()->exists();

    expect($exists)->toBeTrue();
});

// ─── exists() через BelongsToMany (RedisBelongsToMany) ────────

it('exists() через BelongsToMany возвращает true из Redis (zero SQL)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    DB::enableQueryLog();

    $exists = $project->tags()->exists();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($exists)->toBeTrue();
});

it('exists() через BelongsToMany возвращает false из Redis для пустого warmed индекса', function () {
    $project = Project::create(['name' => 'Test']);
    $indexKey = $project->getRedisIndexKey('tags');
    $this->repository->executeBatch(markWarmed: [$indexKey]);

    DB::enableQueryLog();

    $exists = $project->tags()->exists();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($exists)->toBeFalse();
});

it('exists() через BelongsToMany при cold start фолбэчит в SQL', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
    Redis::flushdb();

    $exists = $project->tags()->exists();

    expect($exists)->toBeTrue();
});

it('exists() через BelongsToMany с wherePivot фолбэчит в SQL', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id, ['role' => 'primary']);

    // wherePivot добавляет extra where → SQL fallback
    $exists = $project->tags()->wherePivot('role', 'primary')->exists();

    expect($exists)->toBeTrue();
});

it('exists() через BelongsToMany с wherePivot корректно возвращает false', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id, ['role' => 'primary']);

    $exists = $project->tags()->wherePivot('role', 'nonexistent')->exists();

    expect($exists)->toBeFalse();
});
