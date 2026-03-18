<?php

namespace PetkaKahin\EloquentRedisMirror\Events;

use Illuminate\Database\Eloquent\Model;

class RedisPivotChanged
{
    /**
     * @param list<int|string> $ids
     * @param array<int|string, array<string, mixed>> $pivotAttributes
     */
    public function __construct(
        public Model $parent,
        public string $relationName,
        public string $action,
        public array $ids = [],
        public array $pivotAttributes = [],
    ) {}
}
