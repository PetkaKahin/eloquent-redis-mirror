<p align="center">
  <h1 align="center">Eloquent Redis Mirror</h1>
  <p align="center">
    Zero-config Redis caching layer for Laravel Eloquent.
    <br />
    One trait. Same API. Reads from Redis.
  </p>
</p>

<p align="center">
  <a href="https://packagist.org/packages/petkakahin/eloquent-redis-mirror"><img src="https://img.shields.io/packagist/v/petkakahin/eloquent-redis-mirror.svg" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/petkakahin/eloquent-redis-mirror"><img src="https://img.shields.io/packagist/php-v/petkakahin/eloquent-redis-mirror.svg" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/petkakahin/eloquent-redis-mirror.svg" alt="License"></a>
</p>

---

```php
class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];
}

// Same Eloquent API — reads served from Redis:
Project::find(7);                                        // Redis GET
Project::with('categories.tasks')->find(7);              // ZRANGE + pipeline GET
$project->categories()->paginate(15);                    // ZCARD + ZRANGE
$project->categories()->exists();                        // ZCARD
$project->tags()->attach([5, 8]);                        // DB + auto-sync Redis
```

Add one trait to your models. Every `find()`, `with()`, `first()`, `paginate()`, `exists()` is served from Redis. Writes go to the database first, then automatically sync to Redis via model events. Cold start is handled transparently — first miss hits DB, warms Redis, subsequent reads are instant.

## Features

- **Transparent caching** — `find`, `findMany`, `with`, `first`, `paginate`, `exists` from Redis
- **Auto-sync on write** — create/update/delete/restore trigger Redis sync via events
- **Relations** — HasMany, HasOne, BelongsToMany, BelongsTo with Sorted Set indices
- **Pivot data** — BelongsToMany pivot attributes cached as separate keys
- **Custom relations** — third-party packages (`belongsToSortedMany`, etc.) via `$redisCustomRelations`
- **Cold start** — automatic DB fallback + warm-up with 24h TTL warmed flags
- **Fault-tolerant** — Redis down = transparent fallback to DB, no errors
- **Atomic writes** — `MULTI/EXEC` transactions, no partial state

## Requirements

- PHP 8.2+
- Laravel 11+ / 12+
- Redis (phpredis or predis)

## Installation

```bash
composer require petkakahin/eloquent-redis-mirror
```

ServiceProvider auto-discovered. No config files needed.

## Quick Start

```php
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];

    public function categories(): HasMany { return $this->hasMany(Category::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }
}

class Category extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['tasks'];
}

class Task extends Model
{
    use HasRedisCache;

    protected array $redisRelations = []; // leaf model
}
```

That's it. Every model in the eager-load chain needs the trait. Eloquent API stays the same.

## Documentation

| | |
|---|---|
| **English** | [docs/en.md](docs/en.md) |
| **Русский** | [docs/ru.md](docs/ru.md) |

## Testing

```bash
make tests   # 324 tests (Docker + Pest)
make stan    # PHPStan level 6
```

## License

MIT
