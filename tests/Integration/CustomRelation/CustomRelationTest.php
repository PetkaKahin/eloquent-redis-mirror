<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Concerns\ResolvesRedisRelations;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\CustomRelationProject;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ─── getRedisRelations() merges custom relations ───────────────

it('getRedisRelations включает и standard и custom relations', function () {
    $project = new CustomRelationProject();

    $relations = $project->getRedisRelations();

    expect($relations)->toContain('categories');
    expect($relations)->toContain('tags');
});

it('getRedisCustomRelations возвращает маппинг кастомных типов', function () {
    $project = new CustomRelationProject();

    $custom = $project->getRedisCustomRelations();

    expect($custom)->toBe(['tags' => 'belongsToMany']);
});

// ─── Lazy load: warm cache ─────────────────────────────────────

it('lazy load кастомного BelongsToMany из тёплого кэша — zero SQL', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Вручную прогреваем Redis (как это делает SyncRedisPivot для стандартных relations)
    $resolver = new class {
        use ResolvesRedisRelations;

        public function score(Tag $tag): float
        {
            return $this->scoreFromModel($tag);
        }
    };

    $indexKey = $project->getRedisIndexKey('tags');
    $this->repository->addToIndex($indexKey, (string) $tag1->id, $resolver->score($tag1));
    $this->repository->addToIndex($indexKey, (string) $tag2->id, $resolver->score($tag2));
    $this->repository->executeBatch(markWarmed: [$indexKey]);

    // Pivot data
    $pivotTable = 'project_tag';
    $this->repository->set("{$pivotTable}:{$project->id}:{$tag1->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
    ]);
    $this->repository->set("{$pivotTable}:{$project->id}:{$tag2->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
    ]);

    // Models already cached via create() listener
    DB::enableQueryLog();

    // Lazy load through custom relation
    $fresh = CustomRelationProject::find($project->id);
    DB::flushQueryLog();

    $tags = $fresh->tags;

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();

    expect($tags)->toHaveCount(2);
    expect($tags->pluck('name')->sort()->values()->all())->toBe(['Tag 1', 'Tag 2']);
});

// ─── Lazy load: cold start ─────────────────────────────────────

it('lazy load кастомного BelongsToMany при cold start — fallback на SQL и прогрев Redis', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Insert pivot rows manually (no RedisPivotChanged event for custom relations)
    DB::table('project_tag')->insert([
        ['project_id' => $project->id, 'tag_id' => $tag1->id],
        ['project_id' => $project->id, 'tag_id' => $tag2->id],
    ]);
    Redis::flushdb(); // Full cold start

    // First call — SQL fallback + warm-up
    $fresh = CustomRelationProject::find($project->id);
    $tags = $fresh->tags;

    expect($tags)->toHaveCount(2);

    // Verify Redis was warmed
    $indexKey = $project->getRedisIndexKey('tags');
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->not->toBeNull();
    expect($ids)->toHaveCount(2);
});

it('lazy load кастомного BelongsToMany — повторный вызов после cold start идёт из Redis (zero SQL)', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
    Redis::flushdb();

    // Прогрев через cold start
    $fresh = CustomRelationProject::find($project->id);
    $fresh->tags;

    // Повторный вызов — zero SQL
    DB::enableQueryLog();
    $fresh2 = CustomRelationProject::find($project->id);
    DB::flushQueryLog();
    $tags = $fresh2->tags;

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($tags)->toHaveCount(1);
    expect($tags->first()->name)->toBe('Tag');
});

it('lazy load кастомного BelongsToMany при cold start с пустым результатом помечает warmed', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    Redis::flushdb();

    $fresh = CustomRelationProject::find($project->id);
    $tags = $fresh->tags;

    expect($tags)->toHaveCount(0);

    // Index warmed (empty)
    $indexKey = $project->getRedisIndexKey('tags');
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->not->toBeNull();
    expect($ids)->toBeEmpty();
});

// ─── Eager load ────────────────────────────────────────────────

it('eager load кастомного BelongsToMany через with() — warm cache', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag A']);
    $tag2 = Tag::create(['name' => 'Tag B']);

    $resolver = new class {
        use ResolvesRedisRelations;

        public function score(Tag $tag): float
        {
            return $this->scoreFromModel($tag);
        }
    };

    $indexKey = $project->getRedisIndexKey('tags');
    $this->repository->addToIndex($indexKey, (string) $tag1->id, $resolver->score($tag1));
    $this->repository->addToIndex($indexKey, (string) $tag2->id, $resolver->score($tag2));
    $this->repository->executeBatch(markWarmed: [$indexKey]);

    $pivotTable = 'project_tag';
    $this->repository->set("{$pivotTable}:{$project->id}:{$tag1->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
    ]);
    $this->repository->set("{$pivotTable}:{$project->id}:{$tag2->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
    ]);

    DB::enableQueryLog();

    $result = CustomRelationProject::with('tags')->find($project->id);

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();

    expect($result->tags)->toHaveCount(2);
});

// ─── dispatchPivotChange ───────────────────────────────────────

it('dispatchPivotChange синхронизирует Redis при attach кастомного relation', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Simulate what a third-party package would do: raw pivot insert + dispatchPivotChange
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);

    $project->dispatchPivotChange('tags', 'attached', [$tag->id]);

    // Verify Redis was updated
    $indexKey = $project->getRedisIndexKey('tags');
    $ids = $this->repository->getRelationIdsChecked($indexKey);

    expect($ids)->not->toBeNull();
    expect($ids)->toContain((string) $tag->id);

    // Verify pivot data cached
    $pivotKey = "project_tag:{$project->id}:{$tag->id}";
    $pivotData = $this->repository->get($pivotKey);
    expect($pivotData)->not->toBeNull();
});

it('dispatchPivotChange синхронизирует Redis при detach кастомного relation', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // First attach
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
    $project->dispatchPivotChange('tags', 'attached', [$tag->id]);

    // Now detach
    DB::table('project_tag')
        ->where('project_id', $project->id)
        ->where('tag_id', $tag->id)
        ->delete();
    $project->dispatchPivotChange('tags', 'detached', [$tag->id]);

    // Verify Redis was cleaned
    $indexKey = $project->getRedisIndexKey('tags');
    $ids = $this->repository->getRelationIdsChecked($indexKey);

    expect($ids)->toBeEmpty();
});

// ─── exists() на custom relation через withRedisContext ──────────

it('exists() через custom BelongsToMany возвращает true из Redis (zero SQL)', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    // Warm index via dispatchPivotChange
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
    $project->dispatchPivotChange('tags', 'attached', [$tag->id]);

    DB::enableQueryLog();

    $exists = $project->tags()->exists();

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
    expect($exists)->toBeTrue();
});

it('exists() через custom BelongsToMany возвращает false для пустого warmed индекса', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
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

it('exists() через custom BelongsToMany при cold start фолбэчит в SQL', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
    Redis::flushdb();

    $exists = $project->tags()->exists();

    expect($exists)->toBeTrue();
});

it('exists() через custom BelongsToMany с wherePivot фолбэчит в SQL', function () {
    $project = CustomRelationProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    DB::table('project_tag')->insert([
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'primary',
    ]);
    $project->dispatchPivotChange('tags', 'attached', [$tag->id], [$tag->id => ['role' => 'primary']]);

    // wherePivot adds extra where → SQL fallback
    $exists = $project->tags()->wherePivot('role', 'primary')->exists();

    expect($exists)->toBeTrue();
});

// ─── Relation type on custom_relation_project has correct prefix ───

it('CustomRelationProject использует правильный redis prefix', function () {
    expect(CustomRelationProject::getRedisPrefix())->toBe('custom_relation_project');
});
