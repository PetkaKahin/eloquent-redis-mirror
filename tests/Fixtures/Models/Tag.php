<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Tag extends Model
{
    use HasRedisCache;

    protected $guarded = [];

    protected array $redisRelations = ['projects'];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_tag');
    }
}