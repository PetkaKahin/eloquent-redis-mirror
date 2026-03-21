<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Relations\CustomBelongsToSortedMany;
use PetkaKahin\EloquentRedisMirror\Traits\HasRedisCache;

/**
 * Fixture model that uses a custom BelongsToMany relation (simulating a third-party package).
 * Uses $redisCustomRelations to map the custom relation to its Redis treatment type.
 */
class CustomRelationProject extends Model
{
    use HasRedisCache;

    protected $table = 'projects';

    protected $guarded = [];

    protected array $redisRelations = ['categories'];

    /** @var array<string, string> */
    protected array $redisCustomRelations = [
        'tags' => 'belongsToMany',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'project_id');
    }

    /**
     * Custom relation method — returns CustomBelongsToSortedMany instead of standard BelongsToMany.
     * This simulates a third-party package like belongsToSortedMany().
     * Uses withRedisContext() so that exists()/get()/count() can be served from Redis.
     */
    public function tags(): CustomBelongsToSortedMany
    {
        $instance = $this->newRelatedInstance(Tag::class);
        $table = 'project_tag';

        /** @var CustomBelongsToSortedMany */
        return $this->withRedisContext('tags', new CustomBelongsToSortedMany(
            $instance->newQuery(),
            $this,
            $table,
            'project_id',
            'tag_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'tags',
        ));
    }
}
