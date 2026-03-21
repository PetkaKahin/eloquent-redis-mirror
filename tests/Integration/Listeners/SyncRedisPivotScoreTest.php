<?php

use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisPivot;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\PivotScoredProject;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
    $this->listener = app(SyncRedisPivot::class);
});

// ─── pivot scoring: attached ──────────────────────────────────

it('attached использует pivot.position как score для sorted set', function () {
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tagA = Tag::create(['name' => 'Alpha']);
    $tagB = Tag::create(['name' => 'Beta']);
    $tagC = Tag::create(['name' => 'Gamma']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tagA->id, $tagB->id, $tagC->id], [
        $tagA->id => ['position' => 30],
        $tagB->id => ['position' => 10],
        $tagC->id => ['position' => 20],
    ]);
    $this->listener->handle($event);

    // ZRANGE returns IDs ordered by score (position)
    $ids = $this->repository->getRelationIds("pivot_scored_project:{$project->id}:tags");

    expect($ids)->toBe([(string) $tagB->id, (string) $tagC->id, (string) $tagA->id]);
});

it('attached без pivot score column использует model score (обычное поведение)', function () {
    // Tag model does NOT have redisPivotScore for 'projects', so reverse index uses model score
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag->id], [
        $tag->id => ['position' => 5],
    ]);
    $this->listener->handle($event);

    // Forward index should use position
    $forwardIds = $this->repository->getRelationIds("pivot_scored_project:{$project->id}:tags");
    expect($forwardIds)->toContain((string) $tag->id);

    // Reverse index (tag → projects) should exist and use model score (no pivot scoring on Tag side)
    $reverseIds = $this->repository->getRelationIds("tag:{$tag->id}:projects");
    expect($reverseIds)->toContain((string) $project->id);
});

// ─── pivot scoring: synced ────────────────────────────────────

it('synced использует pivot.position как score', function () {
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tagA = Tag::create(['name' => 'A']);
    $tagB = Tag::create(['name' => 'B']);

    // Setup: tagA already attached
    $this->repository->addToIndex("pivot_scored_project:{$project->id}:tags", $tagA->id, 100);

    // Sync: re-attach tagA (position changed) + attach tagB
    $event = new RedisPivotChanged(
        $project,
        'tags',
        'synced',
        [$tagA->id, $tagB->id],
        [
            $tagA->id => ['position' => 20],
            $tagB->id => ['position' => 10],
        ],
    );
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("pivot_scored_project:{$project->id}:tags");

    // tagB (position=10) before tagA (position=20)
    expect($ids)->toBe([(string) $tagB->id, (string) $tagA->id]);
});

// ─── pivot scoring: updated ───────────────────────────────────

it('updated обновляет score в sorted set при смене position', function () {
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tagA = Tag::create(['name' => 'A']);
    $tagB = Tag::create(['name' => 'B']);

    // Setup: tagA=10, tagB=20
    $this->repository->addToIndex("pivot_scored_project:{$project->id}:tags", $tagA->id, 10);
    $this->repository->addToIndex("pivot_scored_project:{$project->id}:tags", $tagB->id, 20);
    $this->repository->set("project_tag:{$project->id}:{$tagA->id}", [
        'project_id' => $project->id,
        'tag_id' => $tagA->id,
        'position' => 10,
    ]);

    // Update tagA position to 30 (should now be after tagB)
    $event = new RedisPivotChanged($project, 'tags', 'updated', [$tagA->id], [
        $tagA->id => ['position' => 30],
    ]);
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("pivot_scored_project:{$project->id}:tags");

    // tagB (score=20) before tagA (score=30)
    expect($ids)->toBe([(string) $tagB->id, (string) $tagA->id]);
});

it('updated сохраняет pivot данные при обновлении score', function () {
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Setup existing pivot with role
    $this->repository->set("project_tag:{$project->id}:{$tag->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'primary',
        'position' => 10,
    ]);
    $this->repository->addToIndex("pivot_scored_project:{$project->id}:tags", $tag->id, 10);

    // Update position
    $event = new RedisPivotChanged($project, 'tags', 'updated', [$tag->id], [
        $tag->id => ['position' => 50],
    ]);
    $this->listener->handle($event);

    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");

    expect($pivotData['role'])->toBe('primary');
    expect($pivotData['position'])->toBe(50);
});

// ─── pivot scoring: string values (lexorank) ──────────────────

it('attached поддерживает строковые значения pivot score (lexorank)', function () {
    $project = PivotScoredProject::create(['name' => 'Test']);
    $tagA = Tag::create(['name' => 'A']);
    $tagB = Tag::create(['name' => 'B']);
    $tagC = Tag::create(['name' => 'C']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tagA->id, $tagB->id, $tagC->id], [
        $tagA->id => ['position' => 'c'],
        $tagB->id => ['position' => 'a'],
        $tagC->id => ['position' => 'b'],
    ]);
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("pivot_scored_project:{$project->id}:tags");

    // Alphabetic order: a < b < c
    expect($ids)->toBe([(string) $tagB->id, (string) $tagC->id, (string) $tagA->id]);
});
