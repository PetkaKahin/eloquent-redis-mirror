<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use PetkaKahin\EloquentRedisMirror\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
uses(RefreshDatabase::class)->in('Integration', 'Feature');
