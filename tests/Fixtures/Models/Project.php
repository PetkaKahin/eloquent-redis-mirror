<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

class Project extends Model
{
    use HasRedisCache;

    protected $guarded = [];

    protected array $redisRelations = ['categories', 'tags', 'firstCategory'];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function firstCategory(): HasOne
    {
        return $this->hasOne(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'project_tag');
    }
}