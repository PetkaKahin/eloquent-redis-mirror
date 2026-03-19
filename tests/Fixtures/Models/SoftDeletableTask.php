<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletableTask extends Task
{
    use SoftDeletes;

    protected $table = 'tasks';
}