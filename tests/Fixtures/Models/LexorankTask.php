<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

class LexorankTask extends Task
{
    protected $table = 'tasks';

    public function getRedisSortField(): string
    {
        return 'lexorank';
    }
}