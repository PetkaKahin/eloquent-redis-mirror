<p align="center">
  <h1 align="center">Eloquent Redis Mirror</h1>
  <p align="center">
    Redis-кеширование для Laravel Eloquent без конфигурации.
    <br />
    Один trait. Тот же API. Чтение из Redis.
  </p>
</p>

<p align="center">
  <a href="https://packagist.org/packages/petkakahin/eloquent-redis-mirror"><img src="https://img.shields.io/packagist/v/petkakahin/eloquent-redis-mirror.svg" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/petkakahin/eloquent-redis-mirror"><img src="https://img.shields.io/packagist/php-v/petkakahin/eloquent-redis-mirror.svg" alt="PHP Version"></a>
  <a href="../../../LICENSE"><img src="https://img.shields.io/packagist/l/petkakahin/eloquent-redis-mirror.svg" alt="License"></a>
</p>

<p align="center">
  <b>English version:</b> <a href="../../../README.md">README.md</a>
</p>

---

```php
class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];
}

// Тот же Eloquent API — чтение из Redis:
Project::find(7);                                        // Redis GET
Project::with('categories.tasks')->find(7);              // ZRANGE + pipeline GET
$project->categories()->paginate(15);                    // ZCARD + ZRANGE
$project->categories()->exists();                        // ZCARD
$project->tags()->attach([5, 8]);                        // БД + автосинхронизация Redis
```

Добавьте один trait к моделям. Каждый `find()`, `with()`, `first()`, `paginate()`, `exists()` обслуживается из Redis. Запись идёт сначала в БД, затем автоматически синхронизируется в Redis через model events. Cold start обрабатывается прозрачно — первый промах идёт в БД, прогревает Redis, последующие чтения мгновенны.

## Возможности

- **Прозрачное кеширование** — `find`, `findMany`, `with`, `first`, `paginate`, `exists` из Redis
- **Автосинхронизация при записи** — create/update/delete/restore запускают синхронизацию через events
- **Relations** — HasMany, HasOne, BelongsToMany, BelongsTo с Sorted Set индексами
- **Pivot-данные** — атрибуты BelongsToMany pivot кешируются отдельными ключами
- **Кастомные relation** — сторонние пакеты (`belongsToSortedMany` и т.д.) через `$redisCustomRelations`
- **Cold start** — автоматический fallback в БД + прогрев с warmed-флагами (TTL 24ч)
- **Отказоустойчивость** — Redis недоступен = прозрачный fallback в БД, без ошибок
- **Атомарные записи** — `MULTI/EXEC` транзакции, без partial state

## Требования

- PHP 8.2+
- Laravel 11+ / 12+
- Redis (phpredis или predis)

## Установка

```bash
composer require petkakahin/eloquent-redis-mirror
```

ServiceProvider подключается автоматически. Конфигурационные файлы не нужны.

## Быстрый старт

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

    protected array $redisRelations = []; // leaf-модель
}
```

Готово. Каждая модель в цепочке eager-load должна подключать trait. Eloquent API не меняется.

## Документация

Полная документация: **[Документация](documentation.md)**

## Тестирование

```bash
make tests   # 324 теста (Docker + Pest)
make stan    # PHPStan level 6
```

## Лицензия

MIT
