<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Category extends Model
{
    use HasRedisCache;

    protected $guarded = [];

    protected array $redisRelations = ['tasks'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}