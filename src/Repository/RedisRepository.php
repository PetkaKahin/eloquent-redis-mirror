<?php

namespace PetkaKahin\EloquentRedisMirror\Repository;

use Illuminate\Support\Facades\Redis;

class RedisRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        /** @var string|false|null $value */
        $value = Redis::get($key);

        if ($value === null || $value === false) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Redis::del($key);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(string $key, array $attributes): void
    {
        Redis::set($key, json_encode($attributes, JSON_UNESCAPED_UNICODE));
    }

    public function delete(string $key): void
    {
        Redis::del($key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>|null>
     */
    public function getMany(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        /** @var list<string|false|null> $values */
        $values = Redis::pipeline(function ($pipe) use ($keys): void { // @phpstan-ignore-line
            foreach ($keys as $key) {
                $pipe->get($key);
            }
        });

        /** @var array<string, array<string, mixed>|null> $result */
        $result = [];
        foreach ($keys as $i => $key) {
            $val = $values[$i] ?? null;
            if ($val !== null && $val !== false) {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode((string) $val, true);
                $result[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            } else {
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     */
    public function setMany(array $items): void
    {
        if (empty($items)) {
            return;
        }

        Redis::pipeline(function ($pipe) use ($items): void { // @phpstan-ignore-line
            foreach ($items as $key => $attributes) {
                $pipe->set($key, json_encode($attributes, JSON_UNESCAPED_UNICODE));
            }
        });
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        Redis::del(...$keys);
    }

    public function addToIndex(string $indexKey, int|string $id, float $score): void
    {
        Redis::zadd($indexKey, $score, (string) $id);
    }

    public function removeFromIndex(string $indexKey, int|string $id): void
    {
        Redis::zrem($indexKey, (string) $id);
    }

    public function deleteIndex(string $indexKey): void
    {
        Redis::del($indexKey);
    }

    /**
     * @return list<string>
     */
    public function getRelationIds(string $indexKey, int $start = 0, int $stop = -1): array
    {
        /** @var list<string>|false $result */
        $result = Redis::zrange($indexKey, $start, $stop);

        return is_array($result) ? $result : [];
    }

    public function getRelationCount(string $indexKey): int
    {
        return (int) Redis::zcard($indexKey);
    }
}
