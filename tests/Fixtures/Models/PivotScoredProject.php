<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Project that sorts its tags by pivot.position instead of tag model attributes.
 */
class PivotScoredProject extends Project
{
    protected $table = 'projects';

    /** @var array<string, string> */
    protected array $redisPivotScore = [
        'tags' => 'position',
    ];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'project_tag', 'project_id', 'tag_id');
    }
}
