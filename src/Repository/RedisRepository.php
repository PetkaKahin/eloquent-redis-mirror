<?php

namespace PetkaKahin\EloquentRedisMirror\Repository;

use Illuminate\Support\Facades\Redis;
use JsonException;

class RedisRepository
{
    /**
     * TTL for warmed flags in seconds (24 hours).
     * Prevents orphaned flags after manual Redis cleanup (e.g. selective DEL).
     * Expiry only causes a cold-start re-population from DB — no data loss.
     */
    protected const WARMED_TTL = 86400;
    /**
     * @return array<string, mixed>|null
     * @throws JsonException
     */
    public function get(string $key): ?array
    {
        /** @var string|false|null $value */
        $value = Redis::get($key);

        if ($value === null || $value === false) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $attributes
     * @throws JsonException
     */
    public function set(string $key, array $attributes): void
    {
        Redis::set($key, json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function delete(string $key): void
    {
        Redis::del($key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>|null>
     * @throws JsonException
     */
    public function getMany(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        /** @var list<string|false|null> $values */
        $values = Redis::pipeline(static function ($pipe) use ($keys): void { // @phpstan-ignore-line
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
                $decoded    = json_decode((string) $val, true, 512, JSON_THROW_ON_ERROR);
                $result[$key] = $decoded;
            } else {
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @throws JsonException
     */
    public function setMany(array $items): void
    {
        if (empty($items)) {
            return;
        }

        // Pre-encode all values before entering the pipeline to avoid
        // partial writes if json_encode throws mid-pipeline.
        /** @var array<string, string> $encoded */
        $encoded = [];
        foreach ($items as $key => $attributes) {
            $encoded[$key] = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        Redis::pipeline(static function ($pipe) use ($encoded): void { // @phpstan-ignore-line
            foreach ($encoded as $key => $json) {
                $pipe->set($key, $json);
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
        Redis::del($indexKey, $indexKey . ':warmed');
    }

    /**
     * Delete multiple indices along with their warmed flags in a single DEL call.
     *
     * @param list<string> $indexKeys
     */
    public function deleteIndices(array $indexKeys): void
    {
        if (empty($indexKeys)) {
            return;
        }

        $allKeys = [];
        foreach ($indexKeys as $key) {
            $allKeys[] = $key;
            $allKeys[] = $key . ':warmed';
        }

        Redis::del(...$allKeys);
    }

    /**
     * Mark index keys as warmed so that empty indices can be distinguished
     * from indices that have never been populated (cold-start needed).
     *
     * @param list<string> $indexKeys
     */
    public function markIndicesWarmed(array $indexKeys): void
    {
        if (empty($indexKeys)) {
            return;
        }

        Redis::pipeline(static function ($pipe) use ($indexKeys): void { // @phpstan-ignore-line
            foreach ($indexKeys as $indexKey) {
                $pipe->setex($indexKey . ':warmed', self::WARMED_TTL, '1');
            }
        });
    }

    /**
     * Add multiple members to multiple Sorted Set indices in a single pipeline.
     *
     * @param array<string, array<int|string, float>> $entries [indexKey => [id => score, ...], ...]
     */
    public function addToIndicesBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        Redis::pipeline(static function ($pipe) use ($entries): void { // @phpstan-ignore-line
            foreach ($entries as $indexKey => $membersWithScores) {
                foreach ($membersWithScores as $id => $score) {
                    $pipe->zadd($indexKey, $score, (string) $id);
                }
            }
        });
    }

    /**
     * Remove multiple members from multiple Sorted Set indices in a single pipeline.
     *
     * @param array<string, list<int|string>> $entries [indexKey => [id1, id2, ...], ...]
     */
    public function removeFromIndicesBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        Redis::pipeline(static function ($pipe) use ($entries): void { // @phpstan-ignore-line
            foreach ($entries as $indexKey => $ids) {
                foreach ($ids as $id) {
                    $pipe->zrem($indexKey, (string) $id);
                }
            }
        });
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

    /**
     * Like getRelationIds but returns null when the index was never warmed (cold-start).
     * Uses a single pipeline for ZRANGE + EXISTS.
     *
     * @return list<string>|null  null = cold-start needed, [] = warmed but empty
     */
    public function getRelationIdsChecked(string $indexKey, int $start = 0, int $stop = -1): ?array
    {
        /** @var list<mixed> $results */
        $results = Redis::pipeline(static function ($pipe) use ($indexKey, $start, $stop): void { // @phpstan-ignore-line
            $pipe->zrange($indexKey, $start, $stop);
            $pipe->exists($indexKey . ':warmed');
        });

        /** @var list<string> $ids */
        $ids = is_array($results[0] ?? false) ? $results[0] : [];
        $warmed = !empty($results[1] ?? 0);

        if (empty($ids) && !$warmed) {
            return null;
        }

        return $ids;
    }

    /**
     * Like getRelationCount but returns null when the index was never warmed (cold-start).
     * Uses a single pipeline for ZCARD + EXISTS.
     */
    public function getRelationCountChecked(string $indexKey): ?int
    {
        /** @var list<mixed> $results */
        $results = Redis::pipeline(static function ($pipe) use ($indexKey): void { // @phpstan-ignore-line
            $pipe->zcard($indexKey);
            $pipe->exists($indexKey . ':warmed');
        });

        $count = (int) ($results[0] ?? 0); // @phpstan-ignore cast.int
        $warmed = !empty($results[1] ?? 0);

        if ($count === 0 && !$warmed) {
            return null;
        }

        return $count;
    }

    /**
     * Execute multiple heterogeneous write operations in a single Redis pipeline.
     *
     * @param array<string, array<string, mixed>>      $setItems           key => attributes to SET
     * @param list<string>                              $deleteKeys         keys to DEL
     * @param array<string, array<int|string, float>>   $addToIndices       indexKey => [id => score, ...]
     * @param array<string, list<int|string>>           $removeFromIndices  indexKey => [id, ...]
     * @param list<string>                              $markWarmed         index keys to mark as warmed
     * @throws JsonException
     */
    public function executeBatch(
        array $setItems = [],
        array $deleteKeys = [],
        array $addToIndices = [],
        array $removeFromIndices = [],
        array $markWarmed = [],
    ): void {
        if (empty($setItems) && empty($deleteKeys) && empty($addToIndices) && empty($removeFromIndices) && empty($markWarmed)) {
            return;
        }

        // Pre-encode all JSON values before entering the pipeline to avoid
        // partial writes if json_encode throws mid-pipeline.
        /** @var array<string, string> $encodedItems */
        $encodedItems = [];
        foreach ($setItems as $key => $attributes) {
            $encodedItems[$key] = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        Redis::transaction(static function ($pipe) use ($encodedItems, $deleteKeys, $addToIndices, $removeFromIndices, $markWarmed): void { // @phpstan-ignore-line
            foreach ($encodedItems as $key => $json) {
                $pipe->set($key, $json);
            }

            if (!empty($deleteKeys)) {
                $pipe->del(...$deleteKeys);
            }

            foreach ($removeFromIndices as $indexKey => $ids) {
                foreach ($ids as $id) {
                    $pipe->zrem($indexKey, (string) $id);
                }
            }

            foreach ($addToIndices as $indexKey => $membersWithScores) {
                foreach ($membersWithScores as $id => $score) {
                    $pipe->zadd($indexKey, $score, (string) $id);
                }
            }

            foreach ($markWarmed as $indexKey) {
                $pipe->setex($indexKey . ':warmed', self::WARMED_TTL, '1');
            }
        });
    }

    /**
     * Get relation IDs for multiple index keys in a single pipeline.
     *
     * Returns null for keys that have never been warmed (cold-start needed),
     * [] for keys that are warmed but have no members, and [id, ...] otherwise.
     *
     * @param list<string> $indexKeys
     * @return array<string, list<string>|null>
     */
    public function getManyRelationIds(array $indexKeys): array
    {
        if (empty($indexKeys)) {
            return [];
        }

        // Ensure sequential 0-based keys for pipeline result offset mapping
        $indexKeys = array_values($indexKeys);

        /** @var list<mixed> $results */
        $results = Redis::pipeline(static function ($pipe) use ($indexKeys): void { // @phpstan-ignore-line
            foreach ($indexKeys as $key) {
                $pipe->zrange($key, 0, -1);
                $pipe->exists($key . ':warmed');
            }
        });

        /** @var array<string, list<string>|null> $result */
        $result = [];
        foreach ($indexKeys as $i => $key) {
            $zrangeRaw = $results[$i * 2] ?? false;
            $warmedRaw = $results[$i * 2 + 1] ?? 0;

            if (!is_array($zrangeRaw)) {
                $result[$key] = null;
            } elseif (empty($zrangeRaw) && !$warmedRaw) {
                // Empty ZRANGE + no warmed flag → index was never populated
                $result[$key] = null;
            } else {
                // Has IDs, or is warmed-empty (ZRANGE empty but warmed flag set)
                /** @var list<string> $zrangeRaw */
                $result[$key] = $zrangeRaw;
            }
        }

        return $result;
    }
}
