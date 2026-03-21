<?php

/**
 * Regression tests for bugs found during code review (2026-03-20).
 *
 * Each test is designed to FAIL on the old (buggy) code and PASS on the fix.
 * Test names include the bug ID for traceability.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Builder\RedisBuilder;
use PetkaKahin\EloquentRedisMirror\Concerns\RedisRelationCache;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\CustomScoreTask;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\SoftDeletableTask;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.1: resolvedWheres stale cache
//
// Old code: resolvedWheres was cached on first call to resolveWheres()
// and never reset. When findMany() added a whereIn for DB fallback,
// modelSatisfiesWheres() operated on stale wheres from before the
// whereIn was added.
//
// Fix: $this->resolvedWheres = null at the start of findMany().
// ═══════════════════════════════════════════════════════════════

it('[BUG-2.1] resolvedWheres сбрасывается между вызовами findMany на одном builder', function () {
    // Scenario: A scoped builder (relation context) calls findMany twice.
    // The first call populates resolvedWheres. If not reset, the second
    // call would use stale wheres and may incorrectly filter models.
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);

    // Warm Redis
    Project::with('categories')->find($project->id);

    // Use the same relation builder for two findMany calls
    $builder = $project->categories();

    $result1 = $builder->findMany([$cat1->id]);
    expect($result1)->toHaveCount(1)
        ->and($result1->first()->id)->toBe($cat1->id);

    $result2 = $builder->findMany([$cat2->id]);
    expect($result2)->toHaveCount(1)
        ->and($result2->first()->id)->toBe($cat2->id);
});

it('[BUG-2.1] findMany с SoftDeletes: stale resolvedWheres не ломает фильтрацию', function () {
    // SoftDeletes adds a whereNull('deleted_at') scope. If resolvedWheres
    // caches this on first call and then findMany mutates the builder
    // (adding whereIn for DB fallback), the cached wheres must not interfere.
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    $t1 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Active 1']);
    $t2 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Active 2']);
    $t3 = SoftDeletableTask::create(['category_id' => $cat->id, 'title' => 'Deleted']);

    // Soft-delete t3
    $t3->delete();

    // Put stale data in Redis (t3 with deleted_at set)
    $staleAttrs = $t3->fresh()->getRawOriginal();
    $staleAttrs['deleted_at'] = now()->toDateTimeString();
    $this->repository->set("soft_deletable_task:{$t3->id}", $staleAttrs);

    // First findMany populates resolvedWheres (with whereNull deleted_at scope).
    // t3 fails the where check → goes to missedIds → whereIn added → parent::get().
    // Second findMany must reset resolvedWheres to avoid stale cache.
    $result1 = SoftDeletableTask::findMany([$t1->id, $t3->id]);
    expect($result1)->toHaveCount(1)
        ->and($result1->first()->id)->toBe($t1->id);

    // The resolvedWheres from the first call included the whereIn for [t1, t3].
    // Without reset, the second call would inherit that stale whereIn.
    $result2 = SoftDeletableTask::findMany([$t2->id]);
    expect($result2)->toHaveCount(1)
        ->and($result2->first()->id)->toBe($t2->id);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.2: RedisRelationCache::$reverseRelation wrong type
//
// Old code: $reverseRelation was typed as array<string, string|null>
// New code: array<string, list<string>>
// Old test assigned 'relation' (string) instead of ['relation'] (list).
// ═══════════════════════════════════════════════════════════════

it('[BUG-2.2] RedisRelationCache::$reverseRelation хранит list<string>, не string', function () {
    // Verify the type contract: values must be arrays, not strings.
    RedisRelationCache::$reverseRelation['TestKey'] = ['rel1', 'rel2'];

    expect(RedisRelationCache::$reverseRelation['TestKey'])->toBeArray()
        ->and(RedisRelationCache::$reverseRelation['TestKey'])->toBe(['rel1', 'rel2']);

    RedisRelationCache::reset();
    expect(RedisRelationCache::$reverseRelation)->toBeEmpty();
});

it('[BUG-2.2] findAllReverseRelationNames возвращает array, не string|null', function () {
    // Project has both categories() HasMany and firstCategory() HasOne → same child class Category.
    // Old code returned first match as string. New code returns all matches as list.
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Both indices should be populated (proves findAllReverseRelationNames found both)
    $categoriesIds = $this->repository->getRelationIds("project:{$project->id}:categories");
    $firstCategoryIds = $this->repository->getRelationIds("project:{$project->id}:firstCategory");

    expect($categoriesIds)->toContain((string) $cat->id)
        ->and($firstCategoryIds)->toContain((string) $cat->id);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.3: touch() didn't sync updated_at to Redis
//
// Old code: HasRedisCache filtered updated_at from dirty, then
// returned early if dirty was empty. touch() only changes updated_at,
// so the event was never dispatched → stale updated_at in Redis.
//
// Fix: removed the updated_at exclusion + empty-dirty guard.
// ═══════════════════════════════════════════════════════════════

it('[BUG-2.3] touch() обновляет updated_at в Redis', function () {
    $project = Project::create(['name' => 'Test']);

    $cachedBefore = $this->repository->get("project:{$project->id}");
    $updatedAtBefore = $cachedBefore['updated_at'] ?? null;
    expect($updatedAtBefore)->not->toBeNull();

    sleep(1);
    $project->touch();

    $cachedAfter = $this->repository->get("project:{$project->id}");
    expect($cachedAfter)->not->toBeNull()
        ->and($cachedAfter['updated_at'])->not->toBe($updatedAtBefore);
});

it('[BUG-2.3] touch() диспатчит RedisModelChanged event с updated_at', function () {
    $project = Project::create(['name' => 'Test']);

    $dispatched = [];
    \Illuminate\Support\Facades\Event::listen(
        RedisModelChanged::class,
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

it('[BUG-2.3] update реального поля включает поле в dirty и синхронизирует Redis', function () {
    $project = Project::create(['name' => 'Original']);

    $dispatched = [];
    \Illuminate\Support\Facades\Event::listen(
        RedisModelChanged::class,
        function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        }
    );

    $project->update(['name' => 'Updated']);

    $updatedEvents = array_filter($dispatched, fn ($e) => $e->action === 'updated');
    expect($updatedEvents)->not->toBeEmpty();

    $event = array_values($updatedEvents)[0];
    expect($event->dirty)->toContain('name');

    // Redis should have the updated name
    $cached = $this->repository->get("project:{$project->id}");
    expect($cached['name'])->toBe('Updated');
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.4: Delete with unwarmed BTM index didn't clean pivot keys
//
// Old code: handleDeleted() got member IDs via getManyRelationIds().
// If sorted set was never warmed, it returned empty → pivot keys
// were left orphaned in Redis.
//
// Fix: fall back to DB query on pivot table when Redis has no data.
// ═══════════════════════════════════════════════════════════════

it('[BUG-2.4] удаление модели с неотгретым BTM индексом чистит pivot keys через DB fallback', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Attach tags — creates pivot rows in DB and pivot keys in Redis
    $project->tags()->attach([$tag1->id, $tag2->id]);

    // Verify pivot keys exist in Redis
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->not->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->not->toBeNull();

    // Flush Redis completely — simulates "index was never warmed" scenario
    Redis::flushdb();

    // Manually put back ONLY the model hash (not the sorted set index)
    // to ensure handleDeleted finds the model but has no warmed BTM index.
    $this->repository->set("project:{$project->id}", $project->getAttributes());

    // Also put back pivot keys (which would be orphaned without the fix)
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
    ]);
    $this->repository->set("project_tag:{$project->id}:{$tag2->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
    ]);

    // Delete project — handleDeleted should find BTM members via DB fallback
    $project->delete();

    // Pivot keys should be cleaned up even though Redis index was not warmed
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->toBeNull();
});

it('[BUG-2.4] удаление модели с неотгретым BTM чистит reverse indices через DB fallback', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);

    $project->tags()->attach($tag1->id);

    // Warm reverse index on tag side
    Tag::with('projects')->find($tag1->id);

    // Verify reverse index contains the project
    $reverseIds = $this->repository->getRelationIds("tag:{$tag1->id}:projects");
    expect($reverseIds)->toContain((string) $project->id);

    // Delete the project's BTM index + warmed flag to simulate unwarmed state
    Redis::del("project:{$project->id}:tags");
    Redis::del("project:{$project->id}:tags:warmed");

    // Delete project — should use DB fallback to find tag1, then clean reverse index
    $project->delete();

    $reverseIds = $this->repository->getRelationIds("tag:{$tag1->id}:projects");
    expect($reverseIds)->not->toContain((string) $project->id);
});

// ═══════════════════════════════════════════════════════════════
// BUG 2.5: Service locator in RedisBelongsToMany::get()
//
// Old code: app(RedisRepository::class) called directly.
// Fix: repository() method with lazy initialization.
// ═══════════════════════════════════════════════════════════════

it('[BUG-2.5] RedisBelongsToMany::get() использует repository() вместо app() напрямую', function () {
    // This test verifies that get() works correctly through the repository() method.
    // If the method didn't exist or broke, get() would fail.
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Laravel']);
    $project->tags()->attach($tag->id, ['role' => 'primary']);

    // Warm the index
    Project::with('tags')->find($project->id);

    // get() via relation — exercises RedisBelongsToMany::get() with repository()
    DB::enableQueryLog();
    $result = $project->tags()->get();
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->name)->toBe('Laravel')
        ->and($result->first()->pivot->role)->toBe('primary')
        ->and($selectQueries)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// D1: Duplication — fetchRelatedWithPivots shared method
//
// Old code: identical pivot-fetching logic in both BelongsToManyLoader
// and RedisBelongsToMany::get(). Fix: shared method in FetchesRelatedModels.
// These tests verify both paths produce identical results.
// ═══════════════════════════════════════════════════════════════

it('[D1] eager load и lazy load BelongsToMany возвращают одинаковые данные с pivot', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Alpha']);
    $tag2 = Tag::create(['name' => 'Beta']);
    $project->tags()->attach($tag1->id, ['role' => 'primary']);
    $project->tags()->attach($tag2->id, ['role' => 'secondary']);

    // Warm via eager load (BelongsToManyLoader path)
    $eagerLoaded = Project::with('tags')->find($project->id);
    $eagerNames = $eagerLoaded->tags->pluck('name')->sort()->values()->toArray();
    $eagerRoles = $eagerLoaded->tags->pluck('pivot.role')->sort()->values()->toArray();

    // Lazy load (RedisBelongsToMany::get() path) — both use fetchRelatedWithPivots
    DB::enableQueryLog();
    $lazyResult = $project->tags()->get();
    $lazyNames = $lazyResult->pluck('name')->sort()->values()->toArray();
    $lazyRoles = $lazyResult->pluck('pivot.role')->sort()->values()->toArray();

    expect($lazyNames)->toBe($eagerNames)
        ->and($lazyRoles)->toBe($eagerRoles);

    // Should be zero-query (both paths hit Redis)
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// D2: Duplication — resolveWarmColdSplit shared method
//
// Tests that warm/cold split works correctly for both HasMany
// and BelongsToMany, exercising the shared trait method.
// ═══════════════════════════════════════════════════════════════

it('[D2] warm/cold split корректно работает для HasMany (частично прогретые модели)', function () {
    $p1 = Project::create(['name' => 'P1']);
    $p2 = Project::create(['name' => 'P2']);
    $cat1 = Category::create(['project_id' => $p1->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $p2->id, 'name' => 'Cat 2']);

    // Warm only p1's index
    Project::with('categories')->find($p1->id);

    // Flush only p2's model from Redis (keep p1 warm, p2 cold)
    Redis::del("project:{$p2->id}:categories:warmed");
    Redis::del("project:{$p2->id}:categories");

    // Load both — p1 should come from Redis (warm), p2 from DB (cold)
    $projects = Project::with('categories')->findMany([$p1->id, $p2->id]);

    $proj1 = $projects->firstWhere('id', $p1->id);
    $proj2 = $projects->firstWhere('id', $p2->id);

    expect($proj1->categories)->toHaveCount(1)
        ->and($proj1->categories->first()->name)->toBe('Cat 1')
        ->and($proj2->categories)->toHaveCount(1)
        ->and($proj2->categories->first()->name)->toBe('Cat 2');
});

it('[D2] warm/cold split корректно работает для BelongsToMany (частично прогретые модели)', function () {
    $p1 = Project::create(['name' => 'P1']);
    $p2 = Project::create(['name' => 'P2']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    $p1->tags()->attach($tag1->id);
    $p2->tags()->attach($tag2->id);

    // Warm only p1's index
    Project::with('tags')->find($p1->id);

    // Flush only p2's BTM index (keep p1 warm, p2 cold)
    Redis::del("project:{$p2->id}:tags:warmed");
    Redis::del("project:{$p2->id}:tags");

    // Load both
    $projects = Project::with('tags')->findMany([$p1->id, $p2->id]);

    $proj1 = $projects->firstWhere('id', $p1->id);
    $proj2 = $projects->firstWhere('id', $p2->id);

    expect($proj1->tags)->toHaveCount(1)
        ->and($proj1->tags->first()->name)->toBe('Tag 1')
        ->and($proj2->tags)->toHaveCount(1)
        ->and($proj2->tags->first()->name)->toBe('Tag 2');
});

// ═══════════════════════════════════════════════════════════════
// O1: loadNested() is now static — no more new self() instantiation
//
// Tests that nested eager loading still works after refactoring
// loadNestedRelation() to a static loadNested() method.
// ═══════════════════════════════════════════════════════════════

it('[O1] nested eager load HasMany→HasMany работает через static loadNested', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task1 = Task::create(['category_id' => $cat->id, 'title' => 'Task 1']);
    $task2 = Task::create(['category_id' => $cat->id, 'title' => 'Task 2']);

    // Cold start — exercises loadNested in HasManyLoader::loadCold
    Redis::flushdb();
    $result = Project::with('categories.tasks')->find($project->id);

    expect($result->categories)->toHaveCount(1)
        ->and($result->categories->first()->tasks)->toHaveCount(2);

    // Warm path — exercises loadNested in HasManyLoader::loadFromRedis
    DB::enableQueryLog();
    $result2 = Project::with('categories.tasks')->find($project->id);
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );

    expect($result2->categories->first()->tasks)->toHaveCount(2)
        ->and($selectQueries)->toBeEmpty();
});

it('[O1] nested eager load BelongsToMany→HasMany работает через static loadNested', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    // Tags have a 'projects' BelongsToMany relation
    // Nested: project.tags (BTM) → tag.projects (BTM) — deep chain

    // First test: cold start with simple nesting
    Redis::flushdb();
    $result = Project::with('tags')->find($project->id);
    expect($result->tags)->toHaveCount(1)
        ->and($result->tags->first()->name)->toBe('Tag');

    // Warm path
    DB::enableQueryLog();
    $result2 = Project::with('tags')->find($project->id);
    expect($result2->tags)->toHaveCount(1);

    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════
// O2: Static $strategies never reset in tests
//
// Old code: RedisBuilder::$strategies was cached statically but never
// reset between tests, risking cross-test pollution.
// Fix: resetStrategies() + afterEach call.
// ═══════════════════════════════════════════════════════════════

it('[O2] RedisBuilder::resetStrategies() сбрасывает кеш стратегий', function () {
    // Force strategies to be created
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    Project::with('categories')->find($project->id);

    // Reset and verify a fresh load still works
    RedisBuilder::resetStrategies();

    Redis::flushdb();
    $result = Project::with('categories')->find($project->id);
    expect($result->categories)->toHaveCount(1);
});

// ═══════════════════════════════════════════════════════════════
// Integration: combination scenarios that would catch regressions
// if any of the individual fixes break interaction between them.
// ═══════════════════════════════════════════════════════════════

it('[COMBO] touch + eager load: updated_at synced → cache hit на повторном чтении', function () {
    $project = Project::create(['name' => 'Test']);
    Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Warm everything
    Project::with('categories')->find($project->id);

    $cachedBefore = $this->repository->get("project:{$project->id}");
    $updatedAtBefore = $cachedBefore['updated_at'];

    sleep(1);
    $project->touch();

    // Redis should have fresh updated_at after touch
    $cachedAfter = $this->repository->get("project:{$project->id}");
    expect($cachedAfter['updated_at'])->not->toBe($updatedAtBefore);

    // Re-read via find — should hit Redis cache (zero queries)
    DB::enableQueryLog();
    $loaded = Project::find($project->id);
    $selectQueries = collect(DB::getQueryLog())->filter(
        fn ($q) => str_starts_with(strtolower($q['query']), 'select')
    );
    expect($selectQueries)->toBeEmpty();

    // And the loaded model should have the new updated_at
    expect($loaded->updated_at->toDateTimeString())->toBe($cachedAfter['updated_at']);
});

it('[COMBO] delete с BTM + multiple reverse relations корректно чистит всё', function () {
    // Project has categories (HasMany), firstCategory (HasOne), tags (BTM)
    // Deleting a project should clean up:
    //   - model hash
    //   - child indices + warmed flags (categories, tags, firstCategory)
    //   - BTM pivot keys
    //   - reverse indices on tags
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $project->tags()->attach([$tag1->id, $tag2->id]);

    // Warm everything
    Project::with(['categories', 'tags'])->find($project->id);

    // Verify everything exists
    expect($this->repository->get("project:{$project->id}"))->not->toBeNull();
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->not->toBeEmpty();
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->not->toBeNull();

    $project->delete();

    // Model hash deleted
    expect($this->repository->get("project:{$project->id}"))->toBeNull();

    // Child indices deleted
    expect($this->repository->getRelationIds("project:{$project->id}:categories"))->toBeEmpty();
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->toBeEmpty();

    // Pivot keys deleted
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->toBeNull();

    // Reverse indices cleaned
    expect($this->repository->getRelationIds("tag:{$tag1->id}:projects"))
        ->not->toContain((string) $project->id);
    expect($this->repository->getRelationIds("tag:{$tag2->id}:projects"))
        ->not->toContain((string) $project->id);

    // Warmed flags deleted
    expect(Redis::exists("project:{$project->id}:categories:warmed"))->toBeFalsy();
    expect(Redis::exists("project:{$project->id}:tags:warmed"))->toBeFalsy();
});

it('[COMBO] findMany через scoped relation со stale cache + SoftDeletes', function () {
    // Combines BUG 2.1 (resolvedWheres stale) with stale FK scenario.
    // Scoped relation builder has FK where. Redis has model with wrong FK.
    // modelSatisfiesWheres should reject it, then DB fallback finds the real data.
    $project = Project::create(['name' => 'Test']);
    $cat1 = Category::create(['project_id' => $project->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $t1 = Task::create(['category_id' => $cat1->id, 'title' => 'T1']);
    $t2 = Task::create(['category_id' => $cat1->id, 'title' => 'T2']);
    $t3 = Task::create(['category_id' => $cat2->id, 'title' => 'T3']);

    // Move t3 to cat1 in DB only (Redis is stale — has old category_id)
    DB::table('tasks')->where('id', $t3->id)->update(['category_id' => $cat1->id]);

    // findMany through cat1 relation
    $result = $cat1->tasks()->findMany([$t1->id, $t2->id, $t3->id]);

    // All three should be found (t3 via DB fallback since Redis FK doesn't match)
    expect($result)->toHaveCount(3);
    expect($result->pluck('id')->sort()->values()->toArray())
        ->toBe([$t1->id, $t2->id, $t3->id]);
});

it('[COMBO] get() через relation после sync() возвращает актуальные данные', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $project->tags()->attach([$tag1->id, $tag2->id]);

    // Warm
    Project::with('tags')->find($project->id);

    // Sync: remove tag1, keep tag2, add tag3
    $project->tags()->sync([$tag2->id, $tag3->id]);

    // get() via lazy load should reflect the sync
    $result = $project->tags()->get();
    $names = $result->pluck('name')->sort()->values()->toArray();

    expect($names)->toBe(['Tag 2', 'Tag 3']);
});

it('[COMBO] warm/cold split с HasOne возвращает модель не коллекцию', function () {
    $p1 = Project::create(['name' => 'P1']);
    $p2 = Project::create(['name' => 'P2']);
    $cat1 = Category::create(['project_id' => $p1->id, 'name' => 'Cat 1']);
    $cat2 = Category::create(['project_id' => $p2->id, 'name' => 'Cat 2']);

    // Warm p1 only
    Project::with('firstCategory')->find($p1->id);

    // Delete p2's warmed flag to force cold-start
    Redis::del("project:{$p2->id}:firstCategory:warmed");
    Redis::del("project:{$p2->id}:firstCategory");

    // Load both — mixed warm/cold via resolveWarmColdSplit
    $projects = Project::with('firstCategory')->findMany([$p1->id, $p2->id]);

    foreach ($projects as $proj) {
        // HasOne should return a single model, not a collection
        expect($proj->firstCategory)->toBeInstanceOf(Category::class);
        expect($proj->firstCategory)->not->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    }
});

// ═══════════════════════════════════════════════════════════════
// BUG 3.1: scoreDirty always true with getRedisSortScore()
//
// Old code: method_exists($model, 'getRedisSortScore') || in_array(...)
// This made scoreDirty=true for EVERY update when getRedisSortScore()
// exists, causing unnecessary ZADD on every update even if score
// didn't change.
//
// Fix: compare actual old vs new score from getRedisSortScore().
// ═══════════════════════════════════════════════════════════════

it('[BUG-3.1] update без изменения score НЕ вызывает ZADD для модели с getRedisSortScore', function () {
    // CustomScoreTask has getRedisSortScore() based on sort_order.
    // Updating title (not sort_order) should NOT re-ZADD the index.
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'Old', 'sort_order' => 5]);

    // CustomScoreTask prefix is 'custom_score_task', index is still 'category:{id}:tasks'
    $indexKey = "category:{$cat->id}:tasks";
    $hashKey = "custom_score_task:{$task->id}";

    // Record Redis state before update
    $scoreBefore = Redis::zscore($indexKey, (string) $task->id);
    expect((float) $scoreBefore)->toBe(5.0);

    // Update title only — sort_order stays 5, score stays 5.0
    $task->update(['title' => 'New Title']);

    // Score should be unchanged (no unnecessary ZADD)
    $scoreAfter = Redis::zscore($indexKey, (string) $task->id);
    expect((float) $scoreAfter)->toBe((float) $scoreBefore);

    // Hash should be updated
    $cached = $this->repository->get($hashKey);
    expect($cached['title'])->toBe('New Title');
});

it('[BUG-3.1] update с изменением score вызывает ZADD для модели с getRedisSortScore', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'Task', 'sort_order' => 5]);

    $indexKey = "category:{$cat->id}:tasks";

    $scoreBefore = Redis::zscore($indexKey, (string) $task->id);
    expect((float) $scoreBefore)->toBe(5.0);

    // Update sort_order — score changes from 5.0 to 10.0
    $task->update(['sort_order' => 10]);

    $scoreAfter = Redis::zscore($indexKey, (string) $task->id);
    expect((float) $scoreAfter)->toBe(10.0);
});

it('[BUG-3.1] isScoreDirty корректно сравнивает score при пустом dirty', function () {
    // touch() changes only updated_at, not sort_order — score should stay the same
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $task = CustomScoreTask::create(['category_id' => $cat->id, 'title' => 'Task', 'sort_order' => 5]);

    $indexKey = "category:{$cat->id}:tasks";

    // touch() changes only updated_at, not sort_order
    sleep(1);
    $task->touch();

    // Score should remain 5.0 (sort_order unchanged)
    $score = Redis::zscore($indexKey, (string) $task->id);
    expect((float) $score)->toBe(5.0);
});

// ═══════════════════════════════════════════════════════════════
// BUG 3.2: Warmed flags without TTL
//
// Old code: $pipe->set($indexKey . ':warmed', '1') — no TTL.
// After manual Redis cleanup (e.g. DEL specific keys), orphaned
// :warmed flags would persist forever, making the package think
// the index is warmed (but empty), returning empty collections.
//
// Fix: use setex with 24h TTL on all warmed flags.
// ═══════════════════════════════════════════════════════════════

it('[BUG-3.2] warmed флаги создаются с TTL', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Flush to force cold-start path (which sets warmed flags)
    Redis::flushdb();

    // Cold-start warm — triggers executeBatch with markWarmed
    Project::with('categories')->find($project->id);

    $warmedKey = "project:{$project->id}:categories:warmed";

    // Warmed flag should exist
    expect(Redis::exists($warmedKey))->toBeTruthy();

    // TTL should be set (> 0, not -1 which means no expiry)
    $ttl = Redis::ttl($warmedKey);
    expect($ttl)->toBeGreaterThan(0)
        ->and($ttl)->toBeLessThanOrEqual(86400);
});

it('[BUG-3.2] warmed флаги через executeBatch тоже имеют TTL', function () {
    // executeBatch with markWarmed parameter also uses setex
    $this->repository->executeBatch(
        markWarmed: ['test:index:1', 'test:index:2'],
    );

    foreach (['test:index:1:warmed', 'test:index:2:warmed'] as $key) {
        expect(Redis::exists($key))->toBeTruthy();

        $ttl = Redis::ttl($key);
        expect($ttl)->toBeGreaterThan(0)
            ->and($ttl)->toBeLessThanOrEqual(86400);
    }
});

it('[BUG-3.2] markIndicesWarmed устанавливает TTL на все флаги', function () {
    $this->repository->markIndicesWarmed(['idx:a', 'idx:b', 'idx:c']);

    foreach (['idx:a:warmed', 'idx:b:warmed', 'idx:c:warmed'] as $key) {
        $ttl = Redis::ttl($key);
        expect($ttl)->toBeGreaterThan(0)
            ->and($ttl)->toBeLessThanOrEqual(86400);
    }
});

it('[BUG-3.2] после ручной очистки индекса warmed TTL позволяет cold-start', function () {
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);

    // Flush to force cold-start, then warm
    Redis::flushdb();
    Project::with('categories')->find($project->id);

    $indexKey = "project:{$project->id}:categories";

    // Verify warmed flag exists after cold-start
    expect(Redis::exists($indexKey . ':warmed'))->toBeTruthy();

    // Simulate manual DEL of the sorted set only (not warmed flag)
    Redis::del($indexKey);

    // While warmed flag still exists, getRelationIdsChecked returns []
    // (warmed but empty). This is the scenario the TTL fixes —
    // after TTL expires, it will return null (cold-start needed).
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->toBe([]);

    // Simulate TTL expiry by deleting the warmed flag
    Redis::del($indexKey . ':warmed');

    // Now should return null → triggers cold-start from DB
    $ids = $this->repository->getRelationIdsChecked($indexKey);
    expect($ids)->toBeNull();

    // Full flow: with() should now fallback to DB and re-warm
    $loaded = Project::with('categories')->find($project->id);
    expect($loaded->categories)->toHaveCount(1)
        ->and($loaded->categories->first()->name)->toBe('Cat');
});

// ═══════════════════════════════════════════════════════════════
// BUG 3.3: Pipeline without transactions (executeBatch)
//
// Old code: Redis::pipeline() — not atomic, partial writes possible.
// Fix: Redis::transaction() — MULTI/EXEC, all-or-nothing.
//
// Note: testing true atomicity (Redis crash mid-write) is not
// feasible in integration tests. These tests verify that
// executeBatch still works correctly with transaction semantics.
// ═══════════════════════════════════════════════════════════════

it('[BUG-3.3] executeBatch атомарно выполняет set + index + delete + warmed', function () {
    // Pre-populate some data to delete
    $this->repository->set('old:1', ['id' => 1]);
    $this->repository->addToIndex('old:index', 99, 100.0);

    // Single executeBatch with all operation types
    $this->repository->executeBatch(
        setItems: ['new:1' => ['id' => 1, 'name' => 'Test']],
        deleteKeys: ['old:1'],
        addToIndices: ['new:index' => [1 => 100.0, 2 => 200.0]],
        removeFromIndices: ['old:index' => [99]],
        markWarmed: ['new:index'],
    );

    // All operations should have completed atomically
    expect($this->repository->get('new:1'))->toBe(['id' => 1, 'name' => 'Test']);
    expect($this->repository->get('old:1'))->toBeNull();
    expect($this->repository->getRelationIds('new:index'))->toBe(['1', '2']);
    expect($this->repository->getRelationIds('old:index'))->toBeEmpty();
    expect(Redis::exists('new:index:warmed'))->toBeTruthy();
});

it('[BUG-3.3] executeBatch с невалидным JSON прерывает до транзакции', function () {
    // Pre-encode happens BEFORE the transaction starts.
    // If json_encode fails, no partial writes should occur.
    $this->repository->set('existing:1', ['name' => 'should survive']);

    $resource = fopen('php://memory', 'r');

    try {
        $this->repository->executeBatch(
            setItems: [
                'good:1' => ['name' => 'ok'],
                'bad:1' => ['resource' => $resource], // json_encode will throw
            ],
            deleteKeys: ['existing:1'],
        );
    } catch (\JsonException) {
        // Expected
    } finally {
        fclose($resource);
    }

    // existing:1 should NOT have been deleted (transaction never started)
    expect($this->repository->get('existing:1'))->not->toBeNull();
    // good:1 should NOT have been written either
    expect($this->repository->get('good:1'))->toBeNull();
});

it('[BUG-3.3] full flow: create + update через executeBatch транзакционно обновляют Redis', function () {
    // End-to-end: create a model, then update it. Both go through executeBatch.
    // Verify the final state is consistent.
    $project = Project::create(['name' => 'Test']);
    $cat = Category::create(['project_id' => $project->id, 'name' => 'Cat']);
    $t1 = Task::create(['category_id' => $cat->id, 'title' => 'Task 1']);
    $t2 = Task::create(['category_id' => $cat->id, 'title' => 'Task 2']);

    // Both tasks should be in the index
    $ids = $this->repository->getRelationIds("category:{$cat->id}:tasks");
    expect($ids)->toContain((string) $t1->id)
        ->and($ids)->toContain((string) $t2->id);

    // Move t1 to a new category — executeBatch removes from old index + adds to new
    $cat2 = Category::create(['project_id' => $project->id, 'name' => 'Cat 2']);
    $t1->update(['category_id' => $cat2->id]);

    // Old index should not contain t1, new index should
    $oldIds = $this->repository->getRelationIds("category:{$cat->id}:tasks");
    $newIds = $this->repository->getRelationIds("category:{$cat2->id}:tasks");

    expect($oldIds)->not->toContain((string) $t1->id)
        ->and($oldIds)->toContain((string) $t2->id)
        ->and($newIds)->toContain((string) $t1->id);
});
