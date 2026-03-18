<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class PlainModel extends Model
{
    protected $guarded = [];

    protected $table = 'plain_models';
}