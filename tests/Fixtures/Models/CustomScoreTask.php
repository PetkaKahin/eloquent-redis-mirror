<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

/**
 * Task with custom getRedisSortScore() — score derived from sort_order.
 * Used to test that scoreDirty detection compares actual scores
 * instead of always returning true when getRedisSortScore() exists.
 */
class CustomScoreTask extends Task
{
    protected $table = 'tasks';

    public function getRedisSortScore(): float
    {
        return (float) ($this->sort_order ?? 0);
    }
}
