# Documentation — Eloquent Redis Mirror

**Laravel package for mirroring Eloquent models to Redis.** Add one trait — your standard Eloquent API stays the same, but reads are served from Redis with automatic write-through synchronization.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Quick Start](#quick-start)
- [Model Configuration](#model-configuration)
- [Custom Relation Types](#custom-relation-types)
- [What Gets Intercepted](#what-gets-intercepted)
- [Edge Cases](#edge-cases)
- [Architecture](#architecture)

---

## How It Works

```
                         Your code
            ┌──── READ ──────────────── WRITE ────────────────┐
            │  Project::find(7)         $project->update()    │
            │  Project::with(...).find  $project->delete()    │
            │  $project->categories()   tags()->attach(...)   │
            └────────┬──────────────────────────┬─────────────┘
                     │                          │
            ┌────────▼────────────┐    ┌────────▼────────────┐
            │    RedisBuilder     │    │  Eloquent + model   │
            │  find/findMany/with │    │  lifecycle hooks    │
            │  first / paginate   │    │  (bootHasRedisCache)│
            └────────┬────────────┘    └────────┬────────────┘
                     │                          │
            ┌────────▼────────────┐    ┌────────▼────────────┐
            │   RedisRepository   │    │     Database        │
            │  GET/SET/ZADD/ZCARD │    │ INSERT/UPDATE/DELETE│
            └────────┬────────────┘    └────────┬────────────┘
                     │                          │ dispatch Event
                     ▼                          ▼
  ┌─────────────────────┐       ┌─────────────────────┐
  │                     │       │  RedisModelChanged  │
  │    Redis Server     │       │  RedisPivotChanged  │
  │                     │       └──────────┬──────────┘
  │  project:7    {...} │                  │
  │  category:1   {...} │                  ▼
  │                     │       ┌─────────────────────┐
  │  project:7:         │       │   SyncRedisHash     │
  │   categories  [1,2] │◄──────│   SyncRedisPivot    │
  │                     │       └─────────────────────┘
  │  project_tag:       │         Listener → Repository
  │   7:5         {...} │         → update Redis
  └─────────────────────┘

  READ:  Redis hit → return
         Redis miss → DB → write to Redis → return
  WRITE: DB → model event → Listener → Redis
```

### Storage Model

Each record is a separate JSON key. Relations use Sorted Set indices:

```
SET  project:7          '{"id":7,"name":"Kanban"}'
SET  category:1         '{"id":1,"project_id":7,"name":"Backlog"}'

ZADD project:7:categories  1704067200 "1"     ← Sorted Set index
ZADD project:7:categories  1704153600 "2"

ZADD project:7:tags        1704067200 "5"     ← BelongsToMany
SET  project_tag:7:5    '{"project_id":7,"tag_id":5,"role":"primary"}'
```

When a single record is updated, only that key is overwritten — not the entire cache.

---

## Quick Start

### 1. Add the trait to your model

```php
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
```

### 2. Add the trait to related models

Every model in the eager-load chain needs the trait:

```php
class Category extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['tasks'];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}

class Task extends Model
{
    use HasRedisCache;

    protected array $redisRelations = []; // Leaf model: trait caches the record itself
}
```

### 3. Done

The Eloquent API doesn't change:

```php
// READ — from Redis (with automatic DB fallback)
$project = Project::find(7);
$project = Project::with('categories.tasks')->find(7);
$projects = Project::findMany([1, 2, 3]);

// Relation context — also from Redis
$first  = $project->categories()->first();
$page   = $project->categories()->paginate(15);
$exists = $project->categories()->exists();

// Auth provider: where('id', $id)->first() also from Redis
$user = User::where('id', $id)->first();

// WRITE — to DB + auto-sync to Redis
$project->update(['name' => 'New Name']);
$project->delete();
$project->tags()->attach([5, 8], ['role' => 'primary']);
$project->tags()->sync([5, 10]);
$project->tags()->detach(5);
```

---

## Model Configuration

### `$redisRelations`

Array of relation method names for which the package maintains Sorted Set indices in Redis. This enables `with()`, `first()`, `paginate()`, and `exists()` via relation to work from Redis.

```php
protected array $redisRelations = ['categories', 'tags', 'firstCategory'];
```

If the model is a leaf (no child relations to cache), set an empty array `[]`.

### `$redisPivotScore`

By default, sorted set indices use the related model's attributes for score (e.g. `created_at`). If ordering is determined by a pivot column (like `position` in `BelongsToSortedMany`), add `$redisPivotScore`:

```php
protected array $redisPivotScore = [
    'tags' => 'position', // key = relation name, value = pivot column
];
```

`ZADD project:7:tags` uses `pivot.position` as score instead of `tag.created_at`. Numeric values and strings (lexorank: `aaa|bbb`) are both supported. Score updates automatically on `updateExistingPivot`.

### `$redisCustomRelations`

Maps custom relation methods from third-party packages to base types for Redis. See [Custom Relation Types](#custom-relation-types) for details.

```php
protected array $redisCustomRelations = [
    'projects' => 'belongsToMany', // belongsToSortedMany() from a third-party package
];
```

---

## Custom Relation Types

Third-party packages often define custom relation methods (`belongsToSortedMany`, `morphToSortedMany`, etc.) that return custom classes instead of standard `BelongsToMany`/`HasMany`. These relations bypass the package's interception because:

- `belongsToSortedMany()` creates `BelongsToSortedMany` directly, bypassing `HasRedisCache::belongsToMany()` (which returns `RedisBelongsToMany`)
- The relation's internal builder doesn't receive relation context for Redis

The package supports caching custom relations through three mechanisms:

### Step 1: `$redisCustomRelations` — type mapping

```php
class User extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['posts'];

    // Mapping: method name → base Redis type
    // Supported types: 'belongsToMany', 'hasMany', 'hasOne', 'belongsTo'
    protected array $redisCustomRelations = [
        'projects' => 'belongsToMany',
    ];
```

This enables:
- **Lazy load** (`$user->projects`) — served from Redis via `CustomRelationResolver`
- **Eager load** (`User::with('projects')->find(1)`) — served from Redis via eager loading strategy

### Step 2: `withRedisContext()` — intercept exists()/count()

Lazy load and eager load work automatically after step 1. But direct method calls on the relation object (`$user->projects()->exists()`) require the internal builder to know about relation context. Wrap the relation method return in `withRedisContext()`:

```php
    // In the User model:
    public function projects(): BelongsToSortedMany
    {
        return $this->withRedisContext('projects',
            $this->belongsToSortedMany(Project::class, 'user_project')
        );
    }
}
```

**What it does:** `$user->projects()->exists()` checks ZCARD in Redis instead of SQL. When additional constraints are present (e.g. `wherePivot`), it automatically falls back to SQL.

**When needed:** if you call methods on the relation object (`->exists()`, `->count()`). If you only use lazy load (`$user->projects`) or eager load (`with('projects')`), `withRedisContext` is not required.

### Step 3: `dispatchPivotChange()` — write synchronization

Standard `hasMany`/`belongsToMany` are synced automatically via `attach()`/`detach()`/`sync()` interception. Custom relations use their own write methods, so call `dispatchPivotChange()` after mutations:

```php
// Attach:
$user->projects()->attach($projectId, ['position' => 1]);
$user->dispatchPivotChange('projects', 'attached', [$projectId], [
    $projectId => ['position' => 1],
]);

// Detach:
$user->projects()->detach($projectId);
$user->dispatchPivotChange('projects', 'detached', [$projectId]);

// Sync:
$result = $user->projects()->sync([1, 2, 3]);
$allIds = array_merge($result['attached'], $result['detached'], $result['updated']);
$user->dispatchPivotChange('projects', 'synced', $allIds, $pivotAttributes);
```

Supported actions: `attached`, `detached`, `synced`, `toggled`, `updated`.

### Full Example

```php
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class User extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['posts'];

    protected array $redisCustomRelations = [
        'projects' => 'belongsToMany',
    ];

    protected array $redisPivotScore = [
        'projects' => 'position',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function projects(): BelongsToSortedMany
    {
        return $this->withRedisContext('projects',
            $this->belongsToSortedMany(Project::class, 'user_project')
        );
    }
}

// Reading — all from Redis:
$user->projects;                          // lazy load
User::with('projects')->find(1);          // eager load
$user->projects()->exists();              // ZCARD

// Writing — DB + manual Redis sync:
$user->projects()->attach($id);
$user->dispatchPivotChange('projects', 'attached', [$id]);
```

---

## What Gets Intercepted

### Intercepted (Redis)

| Method | Behavior |
|--------|----------|
| `find($id)` | Redis GET → WHERE/scope check → miss: DB → SET |
| `findMany($ids)` | Pipeline GET → miss: WHERE IN → Pipeline SET |
| `where('id', $id)->first()` | PK query detection → `find()` via Redis |
| `with('relation')` | ZRANGE index → findMany |
| `$relation->first()` | ZRANGE 0 0 → find |
| `$relation->paginate()` | ZCARD + ZRANGE LIMIT → findMany |
| `$relation->exists()` | ZCARD > 0 (HasMany, BelongsToMany, custom with `withRedisContext`) |
| `$relation->get()` | ZRANGE → findMany (no limit/offset) |

### Not Intercepted (Database)

| Method | Reason |
|--------|--------|
| `where('name', ...)->get()` | Non-PK filtering — Redis can't evaluate |
| `orderBy()`, `groupBy()` | Sorting/grouping not cached |
| `count()`, `sum()`, `avg()` | Aggregates always hit DB |
| `Model::all()`, `Model::get()` | No relation context or PK — DB |
| `$relation->exists()` with `wherePivot` | Extra constraints — SQL fallback |
| `insert()`, `join()`, `raw()` | Complex queries go directly to DB |
| `DB::table()->...` | Raw query builder — doesn't trigger Eloquent events |

### Cold Start

When Redis is empty (or warmed flag has expired), the first request hits the database. The result is automatically written to Redis and marked with a warmed flag (24h TTL). Warm-up is lazy — on demand.

This works at all levels:
- `find()` / `findMany()` — model cached on first miss
- `where('id', $id)->first()` — model cached via `find()`
- `$relation->get()` / `first()` — index + models warmed on cold start
- Custom relations via `$redisCustomRelations` — warmed via `CustomRelationResolver`

---

## Edge Cases

### Redis Unavailable

All operations fall back to the database via standard Eloquent. Write operations save to DB; the listener catches the Redis exception and logs it. When Redis recovers, data warms up through cold start.

### Atomic Redis Writes

Batch operations (`executeBatch`) use `MULTI/EXEC` — an atomic Redis transaction. If Redis goes down mid-write, none of the commands in the batch execute.

### Race Conditions

Two parallel updates to the same record: both write to DB (handled by DB transactions), both fire events, the second listener overwrites Redis. Result is correct — last-write-wins, source of truth is always the database.

### SoftDeletes

Models with `SoftDeletes` are fully supported. `find()` checks `deleted_at` from the Redis cache via global scopes. The `restored` event syncs the restored record back to Redis.

### Score Recalculation Optimization

On model update, ZADD is only executed if the sort score actually changed. For models with `getRedisSortScore()`, the package compares current and previous scores.

### Eloquent-Only Operations

`DB::table()->insert()`, raw SQL, and query builder without models are **not intercepted** — Redis won't know about the changes. Synchronization only works through Eloquent model events.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Layer 1: HasRedisCache trait                               │
│  Model configuration, Builder override, relation override   │
├─────────────────────────────────────────────────────────────┤
│  Layer 2: RedisBuilder                                      │
│  Intercept find/findMany/with/first/paginate/exists         │
├─────────────────────────────────────────────────────────────┤
│  Layer 3: RedisRepository                                   │
│  Redis wrapper (GET/SET/ZADD/ZRANGE/ZCARD)                  │
│  Batch writes via MULTI/EXEC (atomic transactions)          │
├─────────────────────────────────────────────────────────────┤
│  Layer 4: Events + Listeners                                │
│  RedisModelChanged → SyncRedisHash                          │
│  RedisPivotChanged → SyncRedisPivot                         │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow

#### READ — `Project::find(7)`

```
RedisBuilder::find(7)
  → Repository::get('project:7')
    → HIT:  json_decode → newFromBuilder() → return model
    → MISS: parent::find(7) → DB SELECT → Repository::set() → return model
```

#### READ — `Project::with('categories.tasks')->find(7)`

```
RedisBuilder::find(7)                                        // project from Redis
  → eagerLoadRelations()
    → Repository::getRelationIds('project:7:categories')     // ZRANGE → [1, 2, 3]
    → Repository::getMany(['category:1', 'category:2', ...]) // pipeline GET
      → For each Category:
        → Repository::getRelationIds('category:1:tasks')     // ZRANGE → [10, 11]
        → Repository::getMany(['task:10', 'task:11'])        // pipeline GET
```

#### WRITE — `$project->tags()->attach([5, 8])`

```
DB INSERT into pivot
  → RedisBelongsToMany dispatch → SyncRedisPivot::handle()
    → Repository::addToIndex('project:7:tags', 5, $score)
    → Repository::addToIndex('tag:5:projects', 7, $score)   // reverse index
    → Repository::set('project_tag:7:5', {pivot attrs})
```

### Redis Key Format

| Type | Format | Example |
|------|--------|---------|
| Model | `{snake_case}:{id}` | `project:7` |
| Index | `{snake_case}:{id}:{relation}` | `project:7:categories` |
| Warmed flag | `{index_key}:warmed` | `project:7:categories:warmed` |
| Pivot record | `{pivot_table}:{parent_id}:{related_id}` | `project_tag:7:5` |

### Package Structure

```
src/
├── Traits/
│   └── HasRedisCache.php              # Model trait
├── Builder/
│   ├── RedisBuilder.php               # Custom Eloquent Builder
│   └── EagerLoad/
│       ├── EagerLoadStrategy.php      # Strategy interface
│       ├── FetchesRelatedModels.php   # Shared model fetching trait
│       ├── BelongsToLoader.php        # FK on parent, no sorted set
│       ├── HasManyLoader.php          # HasMany + HasOne (sorted set)
│       └── BelongsToManyLoader.php    # Pivot + sorted set
├── Repository/
│   └── RedisRepository.php            # Redis wrapper (MULTI/EXEC)
├── Relations/
│   └── RedisBelongsToMany.php         # BelongsToMany with event dispatch
├── Concerns/
│   ├── CustomRelationResolver.php     # Custom relation Redis resolver
│   ├── RedisRelationCache.php         # Global static cache
│   └── ResolvesRedisRelations.php     # Reverse relations, scoring
├── Contracts/
│   └── HasRedisCacheInterface.php     # Internal contract
├── Events/
│   ├── RedisModelChanged.php          # Event: create/update/delete/restore
│   └── RedisPivotChanged.php          # Event: attach/detach/sync/toggle
├── Listeners/
│   ├── SyncRedisHash.php              # Updates record hash + indices
│   └── SyncRedisPivot.php            # Updates pivot records + indices
└── Providers/
    └── RedisMirrorServiceProvider.php  # Event listener registration
```
