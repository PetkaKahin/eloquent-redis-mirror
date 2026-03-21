# Eloquent Redis Mirror

**Laravel-пакет для зеркалирования Eloquent-моделей в Redis.** Подключается через один trait — стандартный Eloquent API работает как раньше, но read-операции обслуживаются из Redis с автоматической синхронизацией при записи.

```php
class Project extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['categories', 'tags'];
}

// Всё работает без изменений — но данные читаются из Redis:
Project::find(7);                                          // Redis GET
Project::with('categories.tasks')->find(7);                // Redis ZRANGE + pipeline GET
$project->categories()->paginate(15);                      // Redis ZCARD + ZRANGE
$project->categories()->exists();                          // Redis ZCARD
$project->tags()->attach([5, 8], ['role' => 'primary']);   // БД + автосинхронизация Redis
```

---

## Содержание

- [Как это работает](#как-это-работает)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Конфигурация модели](#конфигурация-модели)
- [Кастомные relation-типы](#кастомные-relation-типы)
- [Что перехватывается, а что нет](#что-перехватывается-а-что-нет)
- [Edge cases](#edge-cases)
- [Архитектура](#архитектура)
- [Тестирование](#тестирование)

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
         Redis miss → БД → записать в Redis → вернуть
  WRITE: БД → model event → Listener → Redis
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

    protected array $redisRelations = []; // Leaf-модель: trait кеширует саму запись
}
```

### 3. Готово

Eloquent API не меняется:

```php
// READ — из Redis (с автоматическим fallback в БД)
$project = Project::find(7);
$project = Project::with('categories.tasks')->find(7);
$projects = Project::findMany([1, 2, 3]);

// Relation-контекст — тоже из Redis
$first  = $project->categories()->first();
$page   = $project->categories()->paginate(15);
$exists = $project->categories()->exists();

// Auth provider: where('id', $id)->first() тоже из Redis
$user = User::where('id', $id)->first();

// WRITE — в БД + автосинхронизация Redis
$project->update(['name' => 'Новое имя']);
$project->delete();
$project->tags()->attach([5, 8], ['role' => 'primary']);
$project->tags()->sync([5, 10]);
$project->tags()->detach(5);
```

---

## Конфигурация модели

### `$redisRelations`

Массив имён relation-методов, для которых пакет хранит Sorted Set индексы в Redis. Это позволяет `with()`, `first()`, `paginate()` и `exists()` через relation работать из Redis.

```php
protected array $redisRelations = ['categories', 'tags', 'firstCategory'];
```

Если модель — leaf (нет дочерних relations для кеширования), укажите пустой массив `[]`.

### `$redisPivotScore`

По умолчанию sorted set индексы используют атрибуты связанной модели для score (например, `created_at`). Если порядок определяется pivot-колонкой (как `position` в `BelongsToSortedMany`), добавьте `$redisPivotScore`:

```php
protected array $redisPivotScore = [
    'tags' => 'position', // ключ — имя relation, значение — колонка в pivot-таблице
];
```

`ZADD project:7:tags` использует `pivot.position` как score вместо `tag.created_at`. Поддерживаются числовые значения и строковые (lexorank: `aaa|bbb`). При `updateExistingPivot` score обновляется автоматически.

### `$redisCustomRelations`

Маппинг кастомных relation-методов из сторонних пакетов на базовые типы для Redis. Подробнее — в разделе [Кастомные relation-типы](#кастомные-relation-типы).

```php
protected array $redisCustomRelations = [
    'projects' => 'belongsToMany', // belongsToSortedMany() из стороннего пакета
];
```

---

## Кастомные relation-типы

Сторонние пакеты часто определяют собственные relation-методы (`belongsToSortedMany`, `morphToSortedMany` и т.д.), которые возвращают кастомные классы вместо стандартных `BelongsToMany`/`HasMany`. Такие relation обходят перехват пакета, потому что:

- `belongsToSortedMany()` создаёт `BelongsToSortedMany` напрямую, минуя `HasRedisCache::belongsToMany()` (который возвращает `RedisBelongsToMany`)
- Внутренний builder relation не получает relation context для Redis

Пакет поддерживает кеширование кастомных relation через три механизма:

### Шаг 1: `$redisCustomRelations` — маппинг типов

```php
class User extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['posts'];

    // Маппинг: имя метода → базовый тип для Redis
    // Поддерживаемые типы: 'belongsToMany', 'hasMany', 'hasOne', 'belongsTo'
    protected array $redisCustomRelations = [
        'projects' => 'belongsToMany',
    ];
```

Это включает:
- **Lazy load** (`$user->projects`) — обслуживается из Redis через `CustomRelationResolver`
- **Eager load** (`User::with('projects')->find(1)`) — обслуживается из Redis через стратегию eager loading

### Шаг 2: `withRedisContext()` — перехват exists()/count()

Lazy load и eager load работают автоматически после шага 1. Но прямые вызовы методов на relation-объекте (`$user->projects()->exists()`) требуют, чтобы внутренний builder знал о relation context. Для этого оберните return relation-метода в `withRedisContext()`:

```php
    // В модели User:
    public function projects(): BelongsToSortedMany
    {
        return $this->withRedisContext('projects',
            $this->belongsToSortedMany(Project::class, 'user_project')
        );
    }
}
```

**Что это даёт:** `$user->projects()->exists()` проверяет ZCARD в Redis вместо SQL. При наличии дополнительных constraint (например `wherePivot`) — автоматический fallback в SQL.

**Когда нужно:** если вы вызываете методы на relation-объекте (`->exists()`, `->count()`). Если используете только lazy load (`$user->projects`) или eager load (`with('projects')`), `withRedisContext` не обязателен.

### Шаг 3: `dispatchPivotChange()` — синхронизация записи

Стандартные `hasMany`/`belongsToMany` синхронизируются автоматически через перехват `attach()`/`detach()`/`sync()`. Кастомные relation используют свои методы записи, поэтому после мутаций вызовите `dispatchPivotChange()`:

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

Поддерживаемые action: `attached`, `detached`, `synced`, `toggled`, `updated`.

### Полный пример кастомного relation

```php
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class User extends Model
{
    use HasRedisCache;

    protected array $redisRelations = ['posts'];

    protected array $redisCustomRelations = [
        'projects' => 'belongsToMany',
    ];

    // Опционально: сортировка по pivot-колонке
    protected array $redisPivotScore = [
        'projects' => 'position',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // Кастомный relation из стороннего пакета
    public function projects(): BelongsToSortedMany
    {
        return $this->withRedisContext('projects',
            $this->belongsToSortedMany(Project::class, 'user_project')
        );
    }
}

// Чтение — всё из Redis:
$user->projects;                          // lazy load из Redis
User::with('projects')->find(1);          // eager load из Redis
$user->projects()->exists();              // ZCARD из Redis

// Запись — БД + ручная синхронизация Redis:
$user->projects()->attach($id);
$user->dispatchPivotChange('projects', 'attached', [$id]);
```

---

## Что перехватывается, а что нет

### Перехватывается (Redis)

| Метод | Поведение |
|-------|-----------|
| `find($id)` | Redis GET → проверка WHERE/scopes → miss: БД → SET |
| `findMany($ids)` | Pipeline GET → miss: WHERE IN → Pipeline SET |
| `where('id', $id)->first()` | Детект PK-запроса → `find()` через Redis |
| `with('relation')` | ZRANGE индекса → findMany |
| `$relation->first()` | ZRANGE 0 0 → find |
| `$relation->paginate()` | ZCARD + ZRANGE LIMIT → findMany |
| `$relation->exists()` | ZCARD > 0 (HasMany, BelongsToMany, кастомные с `withRedisContext`) |
| `$relation->get()` | ZRANGE → findMany (без limit/offset) |

### Не перехватывается (БД)

| Метод | Причина |
|-------|---------|
| `where('name', ...)->get()` | Фильтрация не по PK — Redis не может оценить |
| `orderBy()`, `groupBy()` | Сортировка/группировка не кешируется |
| `count()`, `sum()`, `avg()` | Агрегации всегда в БД |
| `Model::all()`, `Model::get()` | Без relation-контекста и PK — БД |
| `$relation->exists()` с `wherePivot` | Дополнительные constraint — fallback в SQL |
| `insert()`, `join()`, `raw()` | Сложные запросы идут напрямую в БД |
| `DB::table()->...` | Raw query builder — не проходит через Eloquent events |

### Cold start

При пустом Redis (или истёкшем warmed-флаге) первый запрос идёт в БД. Результат автоматически записывается в Redis и помечается warmed-флагом (TTL 24 часа). Прогрев lazy — по мере обращений.

Это работает на всех уровнях:
- `find()` / `findMany()` — модель кешируется при первом промахе
- `where('id', $id)->first()` — модель кешируется через `find()`
- `$relation->get()` / `first()` — индекс + модели прогреваются при cold start
- Кастомные relation через `$redisCustomRelations` — прогрев через `CustomRelationResolver`

---

## Edge cases

### Redis недоступен

Все операции fallback на БД через стандартный Eloquent. WRITE-операции записывают в БД, listener ловит исключение Redis и логирует. При восстановлении Redis — данные прогреются через cold start.

### Атомарность записи в Redis

Batch-операции (`executeBatch`) используют `MULTI/EXEC` — атомарную транзакцию Redis. Если Redis упадёт mid-write, ни одна команда из батча не выполнится.

### Race conditions

Два параллельных обновления одной записи: оба пишут в БД (разруливается транзакциями), оба кидают event, второй listener перезапишет Redis. Итог корректный — last-write-wins, source of truth всегда БД.

### SoftDeletes

Модели с `SoftDeletes` полностью поддерживаются. `find()` проверяет `deleted_at` из Redis-кеша через global scopes. `restored` event синхронизирует восстановленную запись обратно в Redis.

### Оптимизация score-пересчёта

При обновлении модели ZADD выполняется только если sort score реально изменился. Для моделей с `getRedisSortScore()` пакет сравнивает текущий и предыдущий score.

### Только Eloquent-операции

`DB::table()->insert()`, raw SQL и query builder без моделей **не перехватываются** — Redis не узнает об изменениях. Синхронизация работает только через Eloquent model events.

---

## Архитектура

Пакет состоит из 4 слоёв с чёткими границами ответственности:

```
┌─────────────────────────────────────────────────────────────┐
│  Слой 1: HasRedisCache trait                                │
│  Конфигурация модели, подмена Builder, подмена relations    │
├─────────────────────────────────────────────────────────────┤
│  Слой 2: RedisBuilder                                       │
│  Перехват find/findMany/with/first/paginate/exists          │
│  При WRITE — Builder не участвует. Eloquent model events    │
│  (bootHasRedisCache) кидают event → Listener → Redis        │
├─────────────────────────────────────────────────────────────┤
│  Слой 3: RedisRepository                                    │
│  Обёртка над Redis (GET/SET/ZADD/ZRANGE/ZCARD)              │
│  Batch-записи через MULTI/EXEC (атомарные транзакции)       │
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
- **RedisRepository::executeBatch()** использует `MULTI/EXEC` — атомарная транзакция
- **HasRedisCache** trait не содержит логики кеширования — только конфигурация

### Поток данных

#### READ — `Project::find(7)`

```
RedisBuilder::find(7)
  → Repository::get('project:7')
    → HIT:  json_decode → newFromBuilder() → вернуть модель
    → MISS: parent::find(7) → БД SELECT
            → Repository::set('project:7', attributes)
            → вернуть модель
```

#### READ — `Project::with('categories.tasks')->find(7)`

```
RedisBuilder::find(7)                                        // project из Redis
  → eagerLoadRelations()
    → Repository::getRelationIds('project:7:categories')     // ZRANGE → [1, 2, 3]
    → Repository::getMany(['category:1', 'category:2', ...]) // pipeline GET
      → Для каждой Category:
        → Repository::getRelationIds('category:1:tasks')     // ZRANGE → [10, 11]
        → Repository::getMany(['task:10', 'task:11'])        // pipeline GET
```

#### READ — BelongsToMany с pivot

```
Project::with('tags')->find(7)
  → Repository::getRelationIds('project:7:tags')                        // [5, 8]
  → Repository::getMany(['tag:5', 'tag:8'])                             // модели
  → Repository::getMany(['project_tag:7:5', 'project_tag:7:8'])        // pivot
  → Собрать $tag->pivot
```

#### WRITE — `$project->update([...])`

```
Eloquent save → БД UPDATE
  → bootHasRedisCache (updated hook) → event(RedisModelChanged)
    → SyncRedisHash::handle()
      → Repository::set('project:7', attributes)
```

#### WRITE — изменение FK

```
$task->update(['category_id' => 2])  // было 1
  → БД UPDATE → SyncRedisHash::handle()
    → Repository::set('task:15', attributes)
    → Repository::removeFromIndex('category:1:tasks', 15)
    → Repository::addToIndex('category:2:tasks', 15, $score)
```

#### WRITE — pivot

```
$project->tags()->attach([5, 8], ['role' => 'primary'])
  → БД INSERT в pivot
  → RedisBelongsToMany dispatch → SyncRedisPivot::handle()
    → Repository::addToIndex('project:7:tags', 5, $score)
    → Repository::addToIndex('tag:5:projects', 7, $score)  // обратный индекс
    → Repository::set('project_tag:7:5', {pivot attrs})
```

### Формат ключей Redis

| Тип | Формат | Пример |
|-----|--------|--------|
| Модель | `{snake_case}:{id}` | `project:7` |
| Индекс | `{snake_case}:{id}:{relation}` | `project:7:categories` |
| Warmed-флаг | `{index_key}:warmed` | `project:7:categories:warmed` |
| Pivot-запись | `{pivot_table}:{parent_id}:{related_id}` | `project_tag:7:5` |

Warmed-флаги имеют TTL 24 часа. При истечении — автоматический cold-start из БД (без потери данных).

### Структура пакета

```
src/
├── Traits/
│   └── HasRedisCache.php              # Trait для модели
├── Builder/
│   ├── RedisBuilder.php               # Кастомный Eloquent Builder
│   └── EagerLoad/
│       ├── EagerLoadStrategy.php      # Interface для стратегий
│       ├── FetchesRelatedModels.php   # Shared trait для загрузки моделей
│       ├── BelongsToLoader.php        # FK на parent, без sorted set
│       ├── HasManyLoader.php          # HasMany + HasOne (sorted set)
│       └── BelongsToManyLoader.php    # Pivot + sorted set
├── Repository/
│   └── RedisRepository.php            # Обёртка над Redis (MULTI/EXEC)
├── Relations/
│   └── RedisBelongsToMany.php         # BelongsToMany с event dispatch
├── Concerns/
│   ├── CustomRelationResolver.php     # Резолвер кастомных relation из Redis
│   ├── RedisRelationCache.php         # Global static cache (shared)
│   └── ResolvesRedisRelations.php     # Reverse relations, scoring
├── Contracts/
│   └── HasRedisCacheInterface.php     # Внутренний контракт
├── Events/
│   ├── RedisModelChanged.php          # Event: create/update/delete/restore
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

Покрытие: 324 теста — unit, integration, feature, regression.

---

## Лицензия

MIT
