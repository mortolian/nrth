<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertUsingDisposableTestDatabase();
        $this->withoutVite();
    }

    /**
     * Fail loudly before any test body runs if RefreshDatabase would hit app Postgres.
     */
    protected function assertUsingDisposableTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database", '');

        if ($connection === 'sqlite' && ($database === ':memory:' || $database === '')) {
            return;
        }

        throw new RuntimeException(
            "Refusing to run tests against [{$connection}] database [{$database}]. "
            .'PHPUnit must use sqlite :memory: (phpunit.xml + tests/bootstrap.php). '
            .'This protects your local app database from RefreshDatabase / migrate:fresh.'
        );
    }
}
