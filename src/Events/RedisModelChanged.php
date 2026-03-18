<?php

namespace PetkaKahin\EloquentRedisMirror\Events;

use Illuminate\Database\Eloquent\Model;

class RedisModelChanged
{
    /**
     * @param list<string> $dirty
     */
    public function __construct(
        public Model $model,
        public string $action,
        public array $dirty = [],
    ) {}
}
