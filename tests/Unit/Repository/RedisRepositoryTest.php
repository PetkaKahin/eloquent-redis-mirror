<?php

use Illuminate\Support\Facades\Redis;
use PetkaKahin\EloquentRedisMirror\Repository\RedisRepository;

beforeEach(function () {
    Redis::flushdb();
    $this->repository = app(RedisRepository::class);
});

// ─── get() ───────────────────────────────────────────────────

it('get возвращает массив атрибутов по существующему ключу', function () {
    $this->repository->set('project:1', ['id' => 1, 'name' => 'Test']);

    $result = $this->repository->get('project:1');

    expect($result)->toBe(['id' => 1, 'name' => 'Test']);
});

it('get возвращает null для несуществующего ключа', function () {
    expect($this->repository->get('project:999'))->toBeNull();
});

it('get корректно декодирует JSON с юникодом', function () {
    $this->repository->set('project:1', ['name' => 'Канбан доска']);

    $result = $this->repository->get('project:1');

    expect($result['name'])->toBe('Канбан доска');
});

it('get корректно декодирует вложенные структуры', function () {
    $data = ['id' => 1, 'metadata' => ['priority' => 'high', 'tags' => [1, 2]]];
    $this->repository->set('task:1', $data);

    $result = $this->repository->get('task:1');

    expect($result['metadata'])->toBe(['priority' => 'high', 'tags' => [1, 2]]);
});

it('get корректно работает с пустыми значениями полей', function () {
    $this->repository->set('task:1', ['id' => 1, 'description' => null, 'title' => '']);

    $result = $this->repository->get('task:1');

    expect($result['description'])->toBeNull();
    expect($result['title'])->toBe('');
});

it('get корректно работает с числовыми типами', function () {
    $data = ['id' => 1, 'sort_order' => 0, 'price' => 99.99, 'is_active' => true];
    $this->repository->set('task:1', $data);

    $result = $this->repository->get('task:1');

    expect($result['id'])->toBe(1);
    expect($result['sort_order'])->toBe(0);
    expect($result['price'])->toBe(99.99);
    expect($result['is_active'])->toBe(true);
});

// ─── set() ───────────────────────────────────────────────────

it('set записывает данные и они читаются обратно через get', function () {
    $this->repository->set('project:1', ['id' => 1, 'name' => 'Test']);

    expect($this->repository->get('project:1'))->not->toBeNull();
});

it('set перезаписывает существующий ключ', function () {
    $this->repository->set('project:1', ['name' => 'Old']);
    $this->repository->set('project:1', ['name' => 'New']);

    expect($this->repository->get('project:1')['name'])->toBe('New');
});

it('set с пустым массивом атрибутов', function () {
    $this->repository->set('project:1', []);

    expect($this->repository->get('project:1'))->toBe([]);
});

it('set с очень большим payload', function () {
    $data = [];
    for ($i = 0; $i < 100; $i++) {
        $data["field_{$i}"] = str_repeat('x', 1000);
    }

    $this->repository->set('project:1', $data);
    $result = $this->repository->get('project:1');

    expect($result)->toBe($data);
});

// ─── delete() ────────────────────────────────────────────────

it('delete удаляет существующий ключ', function () {
    $this->repository->set('project:1', ['id' => 1, 'name' => 'Test']);

    $this->repository->delete('project:1');

    expect($this->repository->get('project:1'))->toBeNull();
});

it('delete несуществующего ключа не вызывает ошибку', function () {
    $this->repository->delete('project:999');
})->throwsNoExceptions();

it('delete не влияет на другие ключи', function () {
    $this->repository->set('project:1', ['id' => 1]);
    $this->repository->set('project:2', ['id' => 2]);

    $this->repository->delete('project:1');

    expect($this->repository->get('project:2'))->not->toBeNull();
});

// ─── getMany() ───────────────────────────────────────────────

it('getMany возвращает все записи по существующим ключам', function () {
    $this->repository->set('project:1', ['id' => 1]);
    $this->repository->set('project:2', ['id' => 2]);
    $this->repository->set('project:3', ['id' => 3]);

    $result = $this->repository->getMany(['project:1', 'project:2', 'project:3']);

    expect($result)->toHaveCount(3);
    expect($result['project:1'])->not->toBeNull();
    expect($result['project:2'])->not->toBeNull();
    expect($result['project:3'])->not->toBeNull();
});

it('getMany возвращает null для промахов', function () {
    $this->repository->set('project:1', ['id' => 1]);
    $this->repository->set('project:3', ['id' => 3]);

    $result = $this->repository->getMany(['project:1', 'project:2', 'project:3']);

    expect($result['project:1'])->not->toBeNull();
    expect($result['project:2'])->toBeNull();
    expect($result['project:3'])->not->toBeNull();
});

it('getMany с полностью пустым Redis', function () {
    $result = $this->repository->getMany(['project:1', 'project:2']);

    expect($result['project:1'])->toBeNull();
    expect($result['project:2'])->toBeNull();
});

it('getMany с пустым массивом ключей', function () {
    expect($this->repository->getMany([]))->toBe([]);
});

it('getMany с одним ключом работает как get', function () {
    $this->repository->set('project:1', ['id' => 1, 'name' => 'Test']);

    $result = $this->repository->getMany(['project:1']);

    expect($result)->toHaveCount(1);
    expect($result['project:1'])->toBe(['id' => 1, 'name' => 'Test']);
});

it('getMany с большим количеством ключей', function () {
    $keys = [];
    for ($i = 1; $i <= 100; $i++) {
        $this->repository->set("project:{$i}", ['id' => $i]);
        $keys[] = "project:{$i}";
    }

    $result = $this->repository->getMany($keys);

    expect($result)->toHaveCount(100);
    foreach ($result as $key => $value) {
        expect($value)->not->toBeNull();
    }
});

// ─── setMany() ───────────────────────────────────────────────

it('setMany записывает несколько ключей за один вызов', function () {
    $this->repository->setMany([
        'project:1' => ['id' => 1, 'name' => 'First'],
        'project:2' => ['id' => 2, 'name' => 'Second'],
    ]);

    expect($this->repository->get('project:1'))->not->toBeNull();
    expect($this->repository->get('project:2'))->not->toBeNull();
});

it('setMany с пустым массивом не вызывает ошибку', function () {
    $this->repository->setMany([]);
})->throwsNoExceptions();

it('setMany перезаписывает существующие ключи', function () {
    $this->repository->set('project:1', ['name' => 'Old']);

    $this->repository->setMany([
        'project:1' => ['name' => 'New'],
    ]);

    expect($this->repository->get('project:1')['name'])->toBe('New');
});

// ─── deleteMany() ────────────────────────────────────────────

it('deleteMany удаляет несколько ключей', function () {
    $this->repository->set('project:1', ['id' => 1]);
    $this->repository->set('project:2', ['id' => 2]);
    $this->repository->set('project:3', ['id' => 3]);

    $this->repository->deleteMany(['project:1', 'project:2']);

    expect($this->repository->get('project:1'))->toBeNull();
    expect($this->repository->get('project:2'))->toBeNull();
    expect($this->repository->get('project:3'))->not->toBeNull();
});

it('deleteMany с пустым массивом не вызывает ошибку', function () {
    $this->repository->deleteMany([]);
})->throwsNoExceptions();

it('deleteMany с несуществующими ключами не вызывает ошибку', function () {
    $this->repository->deleteMany(['nonexistent:1', 'nonexistent:2']);
})->throwsNoExceptions();

// ─── addToIndex() ────────────────────────────────────────────

it('addToIndex добавляет ID в Sorted Set', function () {
    $this->repository->addToIndex('project:7:categories', 1, 1704067200);

    expect($this->repository->getRelationIds('project:7:categories'))->toBe(['1']);
});

it('addToIndex добавляет несколько ID с разным score', function () {
    $this->repository->addToIndex('project:7:categories', 1, 1704067200);
    $this->repository->addToIndex('project:7:categories', 2, 1704153600);
    $this->repository->addToIndex('project:7:categories', 3, 1704240000);

    $ids = $this->repository->getRelationIds('project:7:categories');

    expect($ids)->toBe(['1', '2', '3']);
});

it('addToIndex с одинаковым ID перезаписывает score', function () {
    $this->repository->addToIndex('project:7:categories', 1, 1000);
    $this->repository->addToIndex('project:7:categories', 1, 2000);

    expect($this->repository->getRelationCount('project:7:categories'))->toBe(1);
});

it('addToIndex корректно сортирует по score', function () {
    $this->repository->addToIndex('project:7:categories', 3, 300);
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:categories', 2, 200);

    expect($this->repository->getRelationIds('project:7:categories'))->toBe(['1', '2', '3']);
});

it('addToIndex со string ID (UUID)', function () {
    $this->repository->addToIndex('project:abc:categories', 'uuid-1', 1704067200);

    expect($this->repository->getRelationIds('project:abc:categories'))->toContain('uuid-1');
});

// ─── removeFromIndex() ──────────────────────────────────────

it('removeFromIndex удаляет ID из Sorted Set', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:categories', 2, 200);
    $this->repository->addToIndex('project:7:categories', 3, 300);

    $this->repository->removeFromIndex('project:7:categories', 2);

    expect($this->repository->getRelationIds('project:7:categories'))->toBe(['1', '3']);
});

it('removeFromIndex несуществующего ID не вызывает ошибку', function () {
    $this->repository->removeFromIndex('project:7:categories', 999);
})->throwsNoExceptions();

it('removeFromIndex из несуществующего индекса не вызывает ошибку', function () {
    $this->repository->removeFromIndex('nonexistent:index', 1);
})->throwsNoExceptions();

it('removeFromIndex последнего элемента оставляет пустой Set', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);

    $this->repository->removeFromIndex('project:7:categories', 1);

    expect($this->repository->getRelationIds('project:7:categories'))->toBe([]);
    expect($this->repository->getRelationCount('project:7:categories'))->toBe(0);
});

// ─── deleteIndex() ──────────────────────────────────────────

it('deleteIndex удаляет весь Sorted Set', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:categories', 2, 200);
    $this->repository->addToIndex('project:7:categories', 3, 300);

    $this->repository->deleteIndex('project:7:categories');

    expect($this->repository->getRelationIds('project:7:categories'))->toBe([]);
    expect($this->repository->getRelationCount('project:7:categories'))->toBe(0);
});

it('deleteIndex несуществующего индекса не вызывает ошибку', function () {
    $this->repository->deleteIndex('nonexistent:index');
})->throwsNoExceptions();

it('deleteIndex не влияет на другие индексы', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:tags', 1, 100);

    $this->repository->deleteIndex('project:7:categories');

    expect($this->repository->getRelationIds('project:7:tags'))->not->toBeEmpty();
});

// ─── getRelationIds() ───────────────────────────────────────

it('getRelationIds возвращает все ID', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->repository->addToIndex('project:7:categories', $i, $i * 100);
    }

    $ids = $this->repository->getRelationIds('project:7:categories', 0, -1);

    expect($ids)->toHaveCount(5);
});

it('getRelationIds с LIMIT для пагинации', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->repository->addToIndex('project:7:categories', $i, $i * 100);
    }

    $page1 = $this->repository->getRelationIds('project:7:categories', 0, 2);
    $page2 = $this->repository->getRelationIds('project:7:categories', 3, 5);
    $lastPage = $this->repository->getRelationIds('project:7:categories', 9, 11);

    expect($page1)->toHaveCount(3);
    expect($page2)->toHaveCount(3);
    expect($lastPage)->toHaveCount(1);
});

it('getRelationIds start=0 stop=0 — первый элемент', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->repository->addToIndex('project:7:categories', $i, $i * 100);
    }

    $result = $this->repository->getRelationIds('project:7:categories', 0, 0);

    expect($result)->toHaveCount(1);
});

it('getRelationIds из пустого индекса', function () {
    expect($this->repository->getRelationIds('empty:index'))->toBe([]);
});

it('getRelationIds из несуществующего ключа', function () {
    expect($this->repository->getRelationIds('nonexistent:key'))->toBe([]);
});

it('getRelationIds возвращает ID как строки', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:categories', 2, 200);

    $ids = $this->repository->getRelationIds('project:7:categories');

    foreach ($ids as $id) {
        expect($id)->toBeString();
    }
});

// ─── getRelationCount() ─────────────────────────────────────

it('getRelationCount возвращает количество элементов', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->repository->addToIndex('project:7:categories', $i, $i * 100);
    }

    expect($this->repository->getRelationCount('project:7:categories'))->toBe(5);
});

it('getRelationCount для пустого индекса === 0', function () {
    expect($this->repository->getRelationCount('empty:index'))->toBe(0);
});

it('getRelationCount для несуществующего ключа === 0', function () {
    expect($this->repository->getRelationCount('nonexistent:key'))->toBe(0);
});

it('getRelationCount после добавления и удаления', function () {
    $this->repository->addToIndex('project:7:categories', 1, 100);
    $this->repository->addToIndex('project:7:categories', 2, 200);
    $this->repository->addToIndex('project:7:categories', 3, 300);

    $this->repository->removeFromIndex('project:7:categories', 2);

    expect($this->repository->getRelationCount('project:7:categories'))->toBe(2);
});

// ─── markIndicesWarmed() ─────────────────────────────────────

it('markIndicesWarmed устанавливает warmed флаги с TTL', function () {
    $this->repository->markIndicesWarmed(['idx:a', 'idx:b']);

    expect(Redis::get('idx:a:warmed'))->toBe('1');
    expect(Redis::get('idx:b:warmed'))->toBe('1');

    // TTL should be set (> 0), not persistent (-1)
    expect(Redis::ttl('idx:a:warmed'))->toBeGreaterThan(0);
    expect(Redis::ttl('idx:b:warmed'))->toBeGreaterThan(0);
});

it('markIndicesWarmed с пустым массивом не вызывает ошибку', function () {
    $this->repository->markIndicesWarmed([]);
})->throwsNoExceptions();

// ─── executeBatch() ─────────────────────────────────────────

it('executeBatch выполняет все операции атомарно', function () {
    $this->repository->set('to_delete:1', ['id' => 1]);
    $this->repository->addToIndex('to_clean:idx', 42, 100.0);

    $this->repository->executeBatch(
        setItems: ['new:1' => ['id' => 1]],
        deleteKeys: ['to_delete:1'],
        addToIndices: ['new:idx' => [10 => 50.0]],
        removeFromIndices: ['to_clean:idx' => [42]],
        markWarmed: ['new:idx'],
    );

    expect($this->repository->get('new:1'))->toBe(['id' => 1]);
    expect($this->repository->get('to_delete:1'))->toBeNull();
    expect($this->repository->getRelationIds('new:idx'))->toBe(['10']);
    expect($this->repository->getRelationIds('to_clean:idx'))->toBeEmpty();
    expect(Redis::exists('new:idx:warmed'))->toBeTruthy();
});

it('executeBatch warmed флаги получают TTL', function () {
    $this->repository->executeBatch(
        markWarmed: ['batch:idx:1', 'batch:idx:2'],
    );

    expect(Redis::ttl('batch:idx:1:warmed'))->toBeGreaterThan(0);
    expect(Redis::ttl('batch:idx:2:warmed'))->toBeGreaterThan(0);
});

it('executeBatch с пустыми аргументами — ранний возврат', function () {
    $this->repository->executeBatch();
})->throwsNoExceptions();

it('executeBatch pre-encode ошибка не оставляет partial writes', function () {
    $this->repository->set('survivor:1', ['name' => 'alive']);

    $resource = fopen('php://memory', 'r');
    try {
        $this->repository->executeBatch(
            setItems: ['bad:1' => ['res' => $resource]],
            deleteKeys: ['survivor:1'],
        );
    } catch (\JsonException) {
        // expected
    } finally {
        fclose($resource);
    }

    // survivor should NOT be deleted (json_encode failed before transaction)
    expect($this->repository->get('survivor:1'))->not->toBeNull();
    expect($this->repository->get('bad:1'))->toBeNull();
});

// ─── getRelationIdsChecked() ────────────────────────────────

it('getRelationIdsChecked возвращает null для непрогретого индекса', function () {
    expect($this->repository->getRelationIdsChecked('cold:index'))->toBeNull();
});

it('getRelationIdsChecked возвращает пустой массив для прогретого пустого индекса', function () {
    $this->repository->markIndicesWarmed(['empty:index']);

    expect($this->repository->getRelationIdsChecked('empty:index'))->toBe([]);
});

it('getRelationIdsChecked возвращает ID для прогретого индекса', function () {
    $this->repository->addToIndex('warm:index', 1, 100.0);
    $this->repository->addToIndex('warm:index', 2, 200.0);
    $this->repository->markIndicesWarmed(['warm:index']);

    $result = $this->repository->getRelationIdsChecked('warm:index');
    expect($result)->toBe(['1', '2']);
});

// ─── getRelationCountChecked() ──────────────────────────────

it('getRelationCountChecked возвращает null для непрогретого индекса', function () {
    expect($this->repository->getRelationCountChecked('cold:index'))->toBeNull();
});

it('getRelationCountChecked возвращает 0 для прогретого пустого индекса', function () {
    $this->repository->markIndicesWarmed(['empty:index']);

    expect($this->repository->getRelationCountChecked('empty:index'))->toBe(0);
});

it('getRelationCountChecked возвращает count для прогретого индекса', function () {
    $this->repository->addToIndex('warm:index', 1, 100.0);
    $this->repository->addToIndex('warm:index', 2, 200.0);
    $this->repository->markIndicesWarmed(['warm:index']);

    expect($this->repository->getRelationCountChecked('warm:index'))->toBe(2);
});

// ─── getManyRelationIds() ───────────────────────────────────

it('getManyRelationIds возвращает null для непрогретых индексов', function () {
    $result = $this->repository->getManyRelationIds(['cold:a', 'cold:b']);

    expect($result['cold:a'])->toBeNull();
    expect($result['cold:b'])->toBeNull();
});

it('getManyRelationIds различает прогретые пустые и непрогретые', function () {
    $this->repository->markIndicesWarmed(['warmed:a']);
    // cold:b not warmed

    $result = $this->repository->getManyRelationIds(['warmed:a', 'cold:b']);

    expect($result['warmed:a'])->toBe([]);  // warmed but empty
    expect($result['cold:b'])->toBeNull();   // not warmed → null
});

it('getManyRelationIds с пустым массивом', function () {
    expect($this->repository->getManyRelationIds([]))->toBe([]);
});

// ─── deleteIndices() ────────────────────────────────────────

it('deleteIndices удаляет индексы вместе с warmed флагами', function () {
    $this->repository->addToIndex('idx:a', 1, 100.0);
    $this->repository->addToIndex('idx:b', 2, 200.0);
    $this->repository->markIndicesWarmed(['idx:a', 'idx:b']);

    $this->repository->deleteIndices(['idx:a', 'idx:b']);

    expect($this->repository->getRelationIds('idx:a'))->toBeEmpty();
    expect($this->repository->getRelationIds('idx:b'))->toBeEmpty();
    expect(Redis::exists('idx:a:warmed'))->toBeFalsy();
    expect(Redis::exists('idx:b:warmed'))->toBeFalsy();
});

it('deleteIndices с пустым массивом не вызывает ошибку', function () {
    $this->repository->deleteIndices([]);
})->throwsNoExceptions();

// ─── addToIndicesBatch() / removeFromIndicesBatch() ─────────

it('addToIndicesBatch добавляет в несколько индексов за один pipeline', function () {
    $this->repository->addToIndicesBatch([
        'idx:a' => [1 => 100.0, 2 => 200.0],
        'idx:b' => [3 => 300.0],
    ]);

    expect($this->repository->getRelationIds('idx:a'))->toBe(['1', '2']);
    expect($this->repository->getRelationIds('idx:b'))->toBe(['3']);
});

it('removeFromIndicesBatch удаляет из нескольких индексов', function () {
    $this->repository->addToIndicesBatch([
        'idx:a' => [1 => 100.0, 2 => 200.0],
        'idx:b' => [3 => 300.0, 4 => 400.0],
    ]);

    $this->repository->removeFromIndicesBatch([
        'idx:a' => [1],
        'idx:b' => [3],
    ]);

    expect($this->repository->getRelationIds('idx:a'))->toBe(['2']);
    expect($this->repository->getRelationIds('idx:b'))->toBe(['4']);
});

// ─── Отказоустойчивость ─────────────────────────────────────

it('методы чтения корректно обрабатывают ConnectionException при недоступном Redis', function () {
    // RedisManager caches its config — rebind with broken config and clear facade
    $brokenManager = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => [
            'host' => 'localhost',
            'port' => 63790,
            'database' => 15,
            'read_write_timeout' => 1,
        ],
    ]);
    app()->instance('redis', $brokenManager);
    Redis::clearResolvedInstances();

    expect(fn () => $this->repository->get('project:1'))
        ->toThrow(Exception::class);
});

it('методы записи корректно обрабатывают ConnectionException при недоступном Redis', function () {
    $brokenManager = new \Illuminate\Redis\RedisManager(app(), 'phpredis', [
        'default' => [
            'host' => 'localhost',
            'port' => 63790,
            'database' => 15,
            'read_write_timeout' => 1,
        ],
    ]);
    app()->instance('redis', $brokenManager);
    Redis::clearResolvedInstances();

    expect(fn () => $this->repository->set('project:1', ['id' => 1]))
        ->toThrow(Exception::class);
});
