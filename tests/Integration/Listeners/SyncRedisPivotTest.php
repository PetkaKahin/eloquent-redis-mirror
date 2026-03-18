<?php

use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Listeners\SyncRedisPivot;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
    $this->listener = app(SyncRedisPivot::class);
});

// ─── attached ───────────────────────────────────────────────

it('attached добавляет в индекс родителя', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag->id], [$tag->id => []]);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("project:{$project->id}:tags"))
        ->toContain((string) $tag->id);
});

it('attached добавляет в обратный индекс (если обе модели с trait)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag->id], [$tag->id => []]);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("tag:{$tag->id}:projects"))
        ->toContain((string) $project->id);
});

it('attached нескольких ID — все в индексе', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag1->id, $tag2->id, $tag3->id], [
        $tag1->id => [],
        $tag2->id => [],
        $tag3->id => [],
    ]);
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("project:{$project->id}:tags");

    expect($ids)->toContain((string) $tag1->id);
    expect($ids)->toContain((string) $tag2->id);
    expect($ids)->toContain((string) $tag3->id);
});

it('attached записывает pivot-запись в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag->id], [
        $tag->id => ['role' => 'primary'],
    ]);
    $this->listener->handle($event);

    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");

    expect($pivotData)->not->toBeNull();
    expect($pivotData['role'])->toBe('primary');
    expect($pivotData['project_id'])->toBe($project->id);
    expect($pivotData['tag_id'])->toBe($tag->id);
});

it('attached без pivot-атрибутов записывает минимальную pivot-запись', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag->id], [$tag->id => []]);
    $this->listener->handle($event);

    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");

    expect($pivotData)->not->toBeNull();
    expect($pivotData['project_id'])->toBe($project->id);
    expect($pivotData['tag_id'])->toBe($tag->id);
});

it('attached нескольких — все pivot-записи созданы', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    $event = new RedisPivotChanged($project, 'tags', 'attached', [$tag1->id, $tag2->id], [
        $tag1->id => ['role' => 'a'],
        $tag2->id => ['role' => 'b'],
    ]);
    $this->listener->handle($event);

    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->not->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->not->toBeNull();
});

// ─── detached ───────────────────────────────────────────────

it('detached удаляет из индекса родителя', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Setup: add to index first
    $this->repository->addToIndex("project:{$project->id}:tags", $tag->id, time());
    $this->repository->addToIndex("tag:{$tag->id}:projects", $project->id, time());
    $this->repository->set("project_tag:{$project->id}:{$tag->id}", ['project_id' => $project->id, 'tag_id' => $tag->id]);

    $event = new RedisPivotChanged($project, 'tags', 'detached', [$tag->id]);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("project:{$project->id}:tags"))
        ->not->toContain((string) $tag->id);
});

it('detached удаляет из обратного индекса', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Setup
    $this->repository->addToIndex("project:{$project->id}:tags", $tag->id, time());
    $this->repository->addToIndex("tag:{$tag->id}:projects", $project->id, time());
    $this->repository->set("project_tag:{$project->id}:{$tag->id}", ['project_id' => $project->id, 'tag_id' => $tag->id]);

    $event = new RedisPivotChanged($project, 'tags', 'detached', [$tag->id]);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("tag:{$tag->id}:projects"))
        ->not->toContain((string) $project->id);
});

it('detached нескольких ID', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Setup
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, time());
    $this->repository->addToIndex("project:{$project->id}:tags", $tag2->id, time());
    $this->repository->addToIndex("tag:{$tag1->id}:projects", $project->id, time());
    $this->repository->addToIndex("tag:{$tag2->id}:projects", $project->id, time());
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", ['project_id' => $project->id, 'tag_id' => $tag1->id]);
    $this->repository->set("project_tag:{$project->id}:{$tag2->id}", ['project_id' => $project->id, 'tag_id' => $tag2->id]);

    $event = new RedisPivotChanged($project, 'tags', 'detached', [$tag1->id, $tag2->id]);
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("project:{$project->id}:tags"))->toBeEmpty();
    expect($this->repository->getRelationIds("tag:{$tag1->id}:projects"))
        ->not->toContain((string) $project->id);
    expect($this->repository->getRelationIds("tag:{$tag2->id}:projects"))
        ->not->toContain((string) $project->id);
});

it('detached удаляет pivot-записи из Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    // Setup pivot records
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", ['project_id' => $project->id, 'tag_id' => $tag1->id]);
    $this->repository->set("project_tag:{$project->id}:{$tag2->id}", ['project_id' => $project->id, 'tag_id' => $tag2->id]);
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, time());
    $this->repository->addToIndex("project:{$project->id}:tags", $tag2->id, time());

    $event = new RedisPivotChanged($project, 'tags', 'detached', [$tag1->id, $tag2->id]);
    $this->listener->handle($event);

    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    expect($this->repository->get("project_tag:{$project->id}:{$tag2->id}"))->toBeNull();
});

// ─── synced ─────────────────────────────────────────────────

it('synced добавляет новые и удаляет старые', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    // Setup: tag1 and tag2 in index
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, time());
    $this->repository->addToIndex("project:{$project->id}:tags", $tag2->id, time());
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", ['project_id' => $project->id, 'tag_id' => $tag1->id]);

    // Synced: attach tag3, detach tag1
    $event = new RedisPivotChanged(
        $project,
        'tags',
        'synced',
        [$tag3->id, $tag1->id],
        [$tag3->id => ['role' => 'new']],
    );
    $this->listener->handle($event);

    $ids = $this->repository->getRelationIds("project:{$project->id}:tags");

    expect($ids)->toContain((string) $tag2->id);
    expect($ids)->toContain((string) $tag3->id);
    expect($ids)->not->toContain((string) $tag1->id);
});

it('synced обновляет обратные индексы', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    // Setup
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, time());
    $this->repository->addToIndex("tag:{$tag1->id}:projects", $project->id, time());

    $event = new RedisPivotChanged(
        $project,
        'tags',
        'synced',
        [$tag3->id, $tag1->id],
        [$tag3->id => []],
    );
    $this->listener->handle($event);

    expect($this->repository->getRelationIds("tag:{$tag1->id}:projects"))
        ->not->toContain((string) $project->id);
    expect($this->repository->getRelationIds("tag:{$tag3->id}:projects"))
        ->toContain((string) $project->id);
});

it('synced создаёт pivot-записи для новых и удаляет для старых', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag3 = Tag::create(['name' => 'Tag 3']);

    // Setup existing pivot
    $this->repository->set("project_tag:{$project->id}:{$tag1->id}", ['project_id' => $project->id, 'tag_id' => $tag1->id]);
    $this->repository->addToIndex("project:{$project->id}:tags", $tag1->id, time());

    $event = new RedisPivotChanged(
        $project,
        'tags',
        'synced',
        [$tag3->id, $tag1->id],
        [$tag3->id => ['role' => 'admin']],
    );
    $this->listener->handle($event);

    // Old pivot deleted
    expect($this->repository->get("project_tag:{$project->id}:{$tag1->id}"))->toBeNull();
    // New pivot created
    $newPivot = $this->repository->get("project_tag:{$project->id}:{$tag3->id}");
    expect($newPivot)->not->toBeNull();
    expect($newPivot['role'])->toBe('admin');
});

// ─── updated ─────────────────────────────────────────────

it('updated перезаписывает pivot-запись в Redis', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Setup existing pivot
    $this->repository->set("project_tag:{$project->id}:{$tag->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'old',
    ]);

    $event = new RedisPivotChanged($project, 'tags', 'updated', [$tag->id], [
        $tag->id => ['role' => 'new'],
    ]);
    $this->listener->handle($event);

    $pivotData = $this->repository->get("project_tag:{$project->id}:{$tag->id}");
    expect($pivotData['role'])->toBe('new');
});

it('updated не трогает индексы (связь не меняется)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    // Setup
    $this->repository->addToIndex("project:{$project->id}:tags", $tag->id, time());
    $this->repository->set("project_tag:{$project->id}:{$tag->id}", [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'old',
    ]);

    $event = new RedisPivotChanged($project, 'tags', 'updated', [$tag->id], [
        $tag->id => ['role' => 'new'],
    ]);
    $this->listener->handle($event);

    // Index should still contain the tag
    expect($this->repository->getRelationIds("project:{$project->id}:tags"))
        ->toContain((string) $tag->id);
});
