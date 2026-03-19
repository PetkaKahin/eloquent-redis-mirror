# Eloquent Redis Mirror

**Laravel-пакет для зеркалирования Eloquent-моделей в Redis.** Подключается через один trait — стандартный Eloquent API работает как раньше, но read-операции обслуживаются из Redis с автоматической синхронизацией при записи.

```php
class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];
}

// Всё работает без изменений — но данные читаются из Redis:
Project::find(7);
Project::with('categories.tasks')->find(7);
$project->tags()->attach([5, 8], ['role' => 'primary']);
$project->categories()->paginate(15);
```

---

## Как это работает

```
                          Ваш код
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
            │   RedisRepository   │    │    БД (Postgres)    │
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
  │   7:5         {...} │         → обновить Redis
  └─────────────────────┘

  READ:  Redis hit → вернуть
         Redis miss → БД (Postgres) → записать в Redis → вернуть
  WRITE: БД (Postgres) → model event → Listener → Redis
```

### Принцип хранения

Каждая запись — отдельный JSON-ключ. Связи — Sorted Set индексы:

```
SET  project:7          '{"id":7,"name":"Канбан"}'
SET  category:1         '{"id":1,"project_id":7,"name":"Backlog"}'

ZADD project:7:categories  1704067200 "1"     ← Sorted Set индекс
ZADD project:7:categories  1704153600 "2"

ZADD project:7:tags        1704067200 "5"     ← BelongsToMany
SET  project_tag:7:5    '{"project_id":7,"tag_id":5,"role":"primary"}'
```

При обновлении одной записи перезаписывается только она, а не весь кеш.

---

## Требования

- PHP 8.2+
- Laravel 11+
- Redis (любой драйвер: phpredis, predis)

## Установка

```bash
composer require petkakahin/eloquent-redis-mirror
```

ServiceProvider регистрируется автоматически через package discovery.

---

## Быстрый старт

### 1. Добавьте trait к модели

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

### 2. Добавьте trait к связанным моделям

Каждая модель в цепочке eager loading должна подключать trait:

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

    protected array $redisRelations = [];
    // Leaf-модель: trait кеширует саму запись
}
```

### 3. Готово

Eloquent API не меняется. Всё работает как раньше:

```php
// READ — из Redis (с автоматическим fallback в БД)
$project = Project::find(7);
$project = Project::with('categories.tasks')->find(7);
$projects = Project::findMany([1, 2, 3]);

// Relation-контекст — тоже из Redis
$first = $project->categories()->first();
$page  = $project->categories()->paginate(15);

// WRITE — в БД + автосинхронизация Redis
$project->update(['name' => 'Новое имя']);
$project->delete();
$project->tags()->attach([5, 8], ['role' => 'primary']);
$project->tags()->sync([5, 10]);
$project->tags()->detach(5);
```

---

## Архитектура

Пакет состоит из 4 слоёв с чёткими границами ответственности:

```
┌─────────────────────────────────────────────────────────────┐
│  Слой 1: HasRedisCache trait                                │
│  Конфигурация модели, подмена Builder, подмена relations    │
├─────────────────────────────────────────────────────────────┤
│  Слой 2: RedisBuilder                                       │
│  Перехват find/findMany/with/first/paginate                 │
│  При WRITE — Builder не участвует. Eloquent model events    │
│  (bootHasRedisCache) кидают event → Listener → Redis        │
├─────────────────────────────────────────────────────────────┤
│  Слой 3: RedisRepository                                    │
│  Обёртка над Redis (GET/SET/ZADD/ZRANGE/ZCARD)              │
│  Не знает про Eloquent — принимает string/array/int         │
├─────────────────────────────────────────────────────────────┤
│  Слой 4: Events + Listeners                                 │
│  RedisModelChanged → SyncRedisHash                          │
│  RedisPivotChanged → SyncRedisPivot                         │
│  Синхронизация Redis при записи через RedisRepository       │
└─────────────────────────────────────────────────────────────┘
```

### Границы (нарушение = баг)

- **RedisBuilder** при WRITE только кидает event, НЕ пишет в Redis
- **RedisBuilder** читает Redis только через RedisRepository
- **Listeners** работают с Redis только через RedisRepository
- **RedisRepository** не знает про Eloquent — принимает `string`/`array`/`int`
- **HasRedisCache** trait не содержит логики кеширования — только конфигурация

---

## Поток данных

### READ — `Project::find(7)`

```
RedisBuilder::find(7)
  → Repository::get('project:7')
    → HIT:  json_decode → newFromBuilder() → вернуть модель
    → MISS: parent::find(7) → Postgres SELECT
            → Repository::set('project:7', attributes)
            → вернуть модель
```

### READ — `Project::with('categories.tasks')->find(7)`

```
RedisBuilder::find(7)                                        // project из Redis
  → eagerLoadRelations()
    → Repository::getRelationIds('project:7:categories')     // ZRANGE → [1, 2, 3]
    → Repository::getMany(['category:1', 'category:2', ...]) // pipeline GET
      → Для каждой Category:
        → Repository::getRelationIds('category:1:tasks')     // ZRANGE → [10, 11]
        → Repository::getMany(['task:10', 'task:11'])        // pipeline GET
```

### READ — BelongsToMany с pivot

```
Project::with('tags')->find(7)
  → Repository::getRelationIds('project:7:tags')                        // [5, 8]
  → Repository::getMany(['tag:5', 'tag:8'])                             // модели
  → Repository::getMany(['project_tag:7:5', 'project_tag:7:8'])        // pivot
  → Собрать $tag->pivot
```

### WRITE — `$project->update([...])`

```
Eloquent save → Postgres UPDATE
  → bootHasRedisCache (updated hook) → event(RedisModelChanged($project, 'updated', ['name']))
    → SyncRedisHash::handle()
      → Repository::set('project:7', attributes)
```

### WRITE — изменение FK

```
$task->update(['category_id' => 2])  // было 1
  → Postgres UPDATE
  → bootHasRedisCache (updated hook) → SyncRedisHash::handle()
    → Repository::set('task:15', attributes)
    → Repository::removeFromIndex('category:1:tasks', 15)
    → Repository::addToIndex('category:2:tasks', 15, $score)
```

### WRITE — pivot

```
$project->tags()->attach([5, 8], ['role' => 'primary'])
  → Postgres INSERT в pivot
  → RedisBelongsToMany dispatch event → SyncRedisPivot::handle()
    → Repository::addToIndex('project:7:tags', 5, $score)
    → Repository::addToIndex('tag:5:projects', 7, $score)  // обратный индекс
    → Repository::set('project_tag:7:5', {pivot attrs})
```

---

## Формат ключей Redis

| Тип | Формат | Пример |
|-----|--------|--------|
| Модель | `{snake_case}:{id}` | `project:7` |
| Индекс | `{snake_case}:{id}:{relation}` | `project:7:categories` |
| Pivot-запись | `{pivot_table}:{parent_id}:{related_id}` | `project_tag:7:5` |

---

## Что перехватывается, а что нет

### Перехватывается (Redis)

| Метод | Поведение |
|-------|-----------|
| `find($id)` | Redis → hit: вернуть, miss: Postgres → записать в Redis |
| `findMany($ids)` | Pipeline GET → промахи одним WHERE IN → Pipeline SET |
| `with('relation')` | ZRANGE из индекса → findMany по ID |
| `first()` через relation | ZRANGE 0 0 → find по ID |
| `paginate()` через relation | ZCARD + ZRANGE LIMIT → findMany → LengthAwarePaginator |

### Не перехватывается (Postgres)

| Метод | Причина |
|-------|---------|
| `where()`, `orderBy()`, `groupBy()` | Фильтрация и сортировка не кешируются |
| `count()`, `sum()`, `avg()` | Агрегации всегда в БД |
| `Model::first()`, `Model::get()` | Без relation-контекста идут в Postgres |
| `Model::all()` | Не перехватывается |
| `insert()`, `join()`, `raw()` | Сложные запросы идут напрямую в БД |

---

## Edge cases

### Cold start

При пустом Redis первый запрос идёт в Postgres, результат автоматически записывается в Redis. Прогрев lazy — по мере обращений.

### Redis недоступен

Все операции fallback на Postgres через стандартный Eloquent. WRITE-операции записывают в Postgres, listener ловит исключение Redis и логирует. При восстановлении Redis — данные прогреются через cold start.

### Race conditions

Два параллельных обновления одной записи: оба пишут в Postgres (разруливается транзакциями), оба кидают event, второй listener перезапишет Redis. Итог корректный — last-write-wins, source of truth всегда Postgres.

### Только Eloquent-операции

`DB::table()->insert()`, raw SQL и query builder без моделей **не перехватываются** — Redis не узнает об изменениях. Синхронизация работает только через Eloquent model events.

---

## Структура пакета

```
src/
├── Traits/
│   └── HasRedisCache.php              # Trait для модели
├── Builder/
│   └── RedisBuilder.php               # Кастомный Eloquent Builder
├── Repository/
│   └── RedisRepository.php            # Обёртка над Redis-командами
├── Relations/
│   └── RedisBelongsToMany.php         # BelongsToMany с event dispatch
├── Events/
│   ├── RedisModelChanged.php          # Event: create/update/delete
│   └── RedisPivotChanged.php          # Event: attach/detach/sync/toggle
├── Listeners/
│   ├── SyncRedisHash.php              # Обновляет хеш записи + индексы
│   └── SyncRedisPivot.php             # Обновляет pivot-записи + индексы
└── Providers/
    └── RedisMirrorServiceProvider.php  # Регистрация event listeners
```

---

## Тестирование

```bash
# Тесты (требуется Docker)
make tests

# Статический анализ
make stan
```

Покрытие: 171 тест — unit (RedisRepository), integration (Builder, Events, Listeners, Relations, Trait), feature (полные end-to-end сценарии).

---

## Лицензия

MIT
