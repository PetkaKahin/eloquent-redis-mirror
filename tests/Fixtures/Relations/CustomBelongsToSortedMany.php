<?php

namespace PetkaKahin\EloquentRedisMirror\Tests\Fixtures\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Minimal custom relation extending BelongsToMany.
 * Simulates third-party packages like belongsToSortedMany().
 *
 * @extends BelongsToMany<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>
 */
class CustomBelongsToSortedMany extends BelongsToMany
{
    // No overrides — just a distinct class that is NOT RedisBelongsToMany
}
