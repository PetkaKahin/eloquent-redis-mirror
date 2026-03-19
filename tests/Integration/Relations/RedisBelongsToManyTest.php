<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;

beforeEach(function () {
    Redis::flushdb();
});

// ─── attach() ───────────────────────────────────────────────

it('attach выполняет стандартный INSERT и кидает event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach($tag->id);

    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'attached'
            && in_array($tag->id, $event->ids);
    });
});

it('attach нескольких ID кидает один event с массивом', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach([$tag1->id, $tag2->id]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag1, $tag2) {
        return $event->action === 'attached'
            && count($event->ids) === 2
            && in_array($tag1->id, $event->ids)
            && in_array($tag2->id, $event->ids);
    });
});

it('attach с pivot-атрибутами передаёт их в event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach([$tag->id => ['role' => 'primary']]);

    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'primary',
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'attached'
            && isset($event->pivotAttributes[$tag->id])
            && $event->pivotAttributes[$tag->id]['role'] === 'primary';
    });
});

it('attach с общими pivot-атрибутами для всех ID', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->attach([$tag1->id, $tag2->id], ['role' => 'member']);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag1, $tag2) {
        return $event->action === 'attached'
            && ($event->pivotAttributes[$tag1->id]['role'] ?? null) === 'member'
            && ($event->pivotAttributes[$tag2->id]['role'] ?? null) === 'member';
    });
});

// ─── detach() ───────────────────────────────────────────────

it('detach удаляет из pivot и кидает event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->detach($tag->id);

    $this->assertDatabaseMissing('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'detached'
            && in_array($tag->id, $event->ids);
    });
});

it('detach всех (без аргументов)', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $project->tags()->attach([$tag1->id, $tag2->id]);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->detach();

    $this->assertDatabaseMissing('project_tag', ['project_id' => $project->id]);

    Event::assertDispatched(RedisPivotChanged::class);
});

// ─── sync() ─────────────────────────────────────────────────

it('sync добавляет новые и удаляет старые', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $tag3 = Tag::create(['name' => 'Tag 3']);
    $project->tags()->attach([$tag1->id, $tag2->id]);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->sync([$tag2->id, $tag3->id]);

    $this->assertDatabaseMissing('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
    ]);
    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
    ]);
    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag3->id,
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) {
        return $event->action === 'synced';
    });
});

it('sync без изменений', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach($tag->id);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->sync([$tag->id]);

    // Database state unchanged
    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
    ]);
});

it('sync с обновлёнными pivot-атрибутами включает обновлённый ID в event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach([$tag->id => ['role' => 'old']]);

    Event::fake([RedisPivotChanged::class]);

    // Sync same tag but with updated pivot attributes
    $project->tags()->sync([$tag->id => ['role' => 'new']]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'synced'
            && in_array($tag->id, $event->ids)
            && isset($event->pivotAttributes[$tag->id])
            && $event->pivotAttributes[$tag->id]['role'] === 'new';
    });
});

// ─── toggle() ───────────────────────────────────────────────

it('toggle переключает состояние и кидает event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag1 = Tag::create(['name' => 'Tag 1']);
    $tag2 = Tag::create(['name' => 'Tag 2']);
    $project->tags()->attach($tag1->id);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->toggle([$tag1->id, $tag2->id]);

    // tag1 was attached, now detached
    $this->assertDatabaseMissing('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag1->id,
    ]);
    // tag2 was not attached, now attached
    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag2->id,
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) {
        return $event->action === 'toggled';
    });
});

// ─── updateExistingPivot() ──────────────────────────────────

it('updateExistingPivot обновляет и кидает event', function () {
    $project = Project::create(['name' => 'Test']);
    $tag = Tag::create(['name' => 'Tag']);
    $project->tags()->attach([$tag->id => ['role' => 'old']]);

    Event::fake([RedisPivotChanged::class]);

    $project->tags()->updateExistingPivot($tag->id, ['role' => 'new']);

    $this->assertDatabaseHas('project_tag', [
        'project_id' => $project->id,
        'tag_id' => $tag->id,
        'role' => 'new',
    ]);

    Event::assertDispatched(RedisPivotChanged::class, function ($event) use ($tag) {
        return $event->action === 'updated'
            && in_array($tag->id, $event->ids)
            && ($event->pivotAttributes[$tag->id]['role'] ?? null) === 'new';
    });
});
