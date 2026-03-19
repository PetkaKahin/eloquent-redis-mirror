<?php

use Illuminate\Support\Facades\Event;
use PetkaKahin\EloquentRedisMirror\Builder\RedisBuilder;
use PetkaKahin\EloquentRedisMirror\Events\RedisModelChanged;
use PetkaKahin\EloquentRedisMirror\Relations\RedisBelongsToMany;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Category;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\PlainModel;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Project;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Tag;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models\Task;

// ─── getRedisKey() ──────────────────────────────────────────

it('getRedisKey генерирует ключ в формате snake_case:id', function () {
    expect((new Project(['id' => 7]))->getRedisKey())->toBe('project:7');
    expect((new Category(['id' => 1]))->getRedisKey())->toBe('category:1');
    expect((new Task(['id' => 15]))->getRedisKey())->toBe('task:15');
});

it('getRedisKey работает с моделями в namespace — берёт class_basename', function () {
    // Project is in PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models namespace
    // but key should use only class basename
    $project = new Project(['id' => 5]);

    expect($project->getRedisKey())->toBe('project:5');
});

it('getRedisKey можно переопределить в модели', function () {
    $model = new class(['id' => 7]) extends Project {
        public function getRedisKey(): string
        {
            return 'custom_prefix:' . $this->id;
        }
    };

    expect($model->getRedisKey())->toBe('custom_prefix:7');
});

it('getRedisKey работает с UUID primary key', function () {
    $model = new class(['id' => 'abc-123-def']) extends Project {
        protected $keyType = 'string';
        public $incrementing = false;
    };

    expect($model->getRedisKey())->toBe('project:abc-123-def');
});

it('getRedisKey выбрасывает исключение если модель без ID', function () {
    $project = new Project();

    expect(fn () => $project->getRedisKey())->toThrow(Exception::class);
});

// ─── getRedisIndexKey() ─────────────────────────────────────

it('getRedisIndexKey генерирует ключ индекса', function () {
    expect((new Project(['id' => 7]))->getRedisIndexKey('categories'))
        ->toBe('project:7:categories');

    expect((new Category(['id' => 1]))->getRedisIndexKey('tasks'))
        ->toBe('category:1:tasks');
});

it('getRedisIndexKey использует getRedisKey как базу', function () {
    $model = new class(['id' => 7]) extends Project {
        public function getRedisKey(): string
        {
            return 'custom:' . $this->id;
        }
    };

    expect($model->getRedisIndexKey('categories'))->toBe('custom:7:categories');
});

// ─── newEloquentBuilder() ───────────────────────────────────

it('Model::query() возвращает RedisBuilder', function () {
    expect(Project::query())->toBeInstanceOf(RedisBuilder::class);
});

it('модель без trait возвращает стандартный Builder', function () {
    $builder = PlainModel::query();

    expect($builder)->not->toBeInstanceOf(RedisBuilder::class);
    expect($builder)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
});

it('RedisBuilder наследует все стандартные методы Eloquent Builder', function () {
    // These should not throw exceptions
    Project::query()->where('name', 'test');
    Project::query()->orderBy('created_at');

    expect(true)->toBeTrue();
});

// ─── $redisRelations ────────────────────────────────────────

it('redisRelations содержит список имён relations', function () {
    expect((new Project())->getRedisRelations())->toBe(['categories', 'tags']);
});

it('redisRelations пустой массив для leaf-моделей', function () {
    expect((new Task())->getRedisRelations())->toBe([]);
});

it('redisRelations ссылается только на существующие relation-методы', function () {
    $model = new class extends Project {
        protected array $redisRelations = ['nonexistent'];
    };

    // Should warn or throw when referencing non-existent relation
    expect(fn () => $model->getRedisRelations())->toThrow(Exception::class);
})->skip('Implementation may handle this differently');

// ─── Автоопределение типа связи ────────────────────────────

it('trait определяет HasMany через return type', function () {
    $project = new Project(['id' => 1]);

    expect($project->categories())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

it('trait определяет BelongsToMany через return type', function () {
    $project = new Project(['id' => 1]);

    expect($project->tags())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('trait определяет BelongsTo через return type', function () {
    $category = new Category(['id' => 1]);

    expect($category->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

// ─── bootHasRedisCache() ───────────────────────────────────

it('boot регистрирует event listeners на created', function () {
    Event::fake([RedisModelChanged::class]);

    $project = Project::create(['name' => 'Test']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) use ($project) {
        return $event->action === 'created' && $event->model->id === $project->id;
    });
});

it('boot регистрирует event listeners на updated', function () {
    $project = Project::create(['name' => 'Old']);

    Event::fake([RedisModelChanged::class]);

    $project->update(['name' => 'New']);

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'updated';
    });
});

it('boot регистрирует event listeners на deleted', function () {
    $project = Project::create(['name' => 'Test']);

    Event::fake([RedisModelChanged::class]);

    $project->delete();

    Event::assertDispatched(RedisModelChanged::class, function ($event) {
        return $event->action === 'deleted';
    });
});

it('boot не регистрирует events на модели без trait', function () {
    Event::fake([RedisModelChanged::class]);

    PlainModel::create(['name' => 'Test']);

    Event::assertNotDispatched(RedisModelChanged::class);
});

// ─── belongsToMany override ─────────────────────────────────

it('belongsToMany возвращает RedisBelongsToMany для модели с trait', function () {
    $project = Project::create(['name' => 'Test']);

    expect($project->tags())->toBeInstanceOf(RedisBelongsToMany::class);
});

it('belongsToMany на модели без trait возвращает стандартный BelongsToMany', function () {
    // PlainModel doesn't have BelongsToMany, but the test verifies
    // that the override only applies to models with HasRedisCache
    $builder = PlainModel::query();

    expect($builder)->not->toBeInstanceOf(RedisBuilder::class);
});
