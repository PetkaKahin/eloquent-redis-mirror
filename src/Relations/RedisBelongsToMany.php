<?php

namespace PetkaKahin\EloquentRedisMirror\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PetkaKahin\EloquentRedisMirror\Events\RedisPivotChanged;

/**
 * @extends BelongsToMany<Model, Model>
 */
class RedisBelongsToMany extends BelongsToMany
{
    /**
     * @param mixed $id
     * @param array<string, mixed> $attributes
     */
    public function attach($id, array $attributes = [], $touch = true): void
    {
        parent::attach($id, $attributes, $touch);

        $ids = $this->extractIds($id);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($ids as $relatedId) {
            // Per-ID attributes from associative array like [$id => ['role' => 'x']]
            if (is_array($id) && isset($id[$relatedId]) && is_array($id[$relatedId])) {
                $pivotAttributes[$relatedId] = array_merge($attributes, $id[$relatedId]);
            } else {
                $pivotAttributes[$relatedId] = $attributes;
            }
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'attached',
            $ids,
            $pivotAttributes,
        ));
    }

    /**
     * @param mixed $ids
     */
    public function detach($ids = null, $touch = true): int
    {
        if ($ids === null) {
            /** @var list<int|string> $detachIds */
            $detachIds = $this->allRelatedIds()->toArray();
        } else {
            /** @var list<int|string> $detachIds */
            $detachIds = array_values((array) $ids);
        }

        $result = parent::detach($ids, $touch);

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'detached',
            $detachIds,
        ));

        return $result;
    }

    /**
     * @param \Illuminate\Contracts\Support\Arrayable<array-key, mixed>|array<array-key, mixed> $ids
     * @return array{attached: list<mixed>, detached: list<mixed>, updated: list<mixed>}
     */
    public function sync($ids, $detaching = true)
    {
        $result = parent::sync($ids, $detaching);

        /** @var list<int|string> $attached */
        $attached = array_values($result['attached']);
        /** @var list<int|string> $detached */
        $detached = array_values($result['detached']);

        /** @var list<int|string> $allIds */
        $allIds = array_merge($attached, $detached);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        // For newly attached IDs, extract pivot attributes from the input
        if (is_array($ids)) {
            foreach ($attached as $attachedId) {
                if (isset($ids[$attachedId]) && is_array($ids[$attachedId])) {
                    $pivotAttributes[$attachedId] = $ids[$attachedId];
                } else {
                    $pivotAttributes[$attachedId] = [];
                }
            }
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'synced',
            $allIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @param \Illuminate\Contracts\Support\Arrayable<array-key, mixed>|array<array-key, mixed> $ids
     * @return array{attached: list<mixed>, detached: list<mixed>}
     */
    public function toggle($ids, $touch = true)
    {
        $result = parent::toggle($ids, $touch);

        /** @var list<int|string> $attached */
        $attached = array_values($result['attached']);
        /** @var list<int|string> $detached */
        $detached = array_values($result['detached']);

        /** @var list<int|string> $allIds */
        $allIds = array_merge($attached, $detached);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($attached as $attachedId) {
            $pivotAttributes[$attachedId] = [];
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'toggled',
            $allIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);

        /** @var list<int|string> $eventIds */
        $eventIds = array_values((array) $id);

        /** @var array<int|string, array<string, mixed>> $pivotAttributes */
        $pivotAttributes = [];
        foreach ($eventIds as $eid) {
            $pivotAttributes[$eid] = $attributes;
        }

        event(new RedisPivotChanged(
            $this->getParent(),
            $this->getRelationName(),
            'updated',
            $eventIds,
            $pivotAttributes,
        ));

        return $result;
    }

    /**
     * @return list<int|string>
     */
    protected function extractIds(mixed $id): array
    {
        if ($id instanceof Model) {
            /** @var int|string $key */
            $key = $id->getKey();
            return [$key];
        }

        if (is_array($id)) {
            /** @var list<int|string> $ids */
            $ids = [];
            /**
             * @var int|string $key
             * @var mixed $value
             */
            foreach ($id as $key => $value) {
                if (is_array($value)) {
                    $ids[] = $key;
                } elseif (is_numeric($key)) {
                    /** @var int|string $value */
                    $ids[] = $value;
                } else {
                    $ids[] = $key;
                }
            }
            return $ids;
        }

        /** @var int|string $id */
        return [$id];
    }
}
