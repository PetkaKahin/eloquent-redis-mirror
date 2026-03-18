<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Task extends Model
{
    use HasRedisCache;

    protected $guarded = [];

    protected array $redisRelations = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}