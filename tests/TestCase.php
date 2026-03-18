<?php

namespace PetkaKahin\EloquentRedisMirror\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \PetkaKahin\EloquentRedisMirror\Providers\RedisMirrorServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'options' => [
                'prefix' => '',
            ],
            'default' => [
                'host' => env('REDIS_HOST', '127.0.1.21'),
                'port' => (int) env('REDIS_PORT', 6379),
                'database' => 15,
                'read_write_timeout' => -1,
                'timeout' => 2,
            ],
        ]);
    }
}
