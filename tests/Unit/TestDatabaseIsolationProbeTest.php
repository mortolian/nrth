<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseIsolationProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_must_use_sqlite_memory_not_app_postgres(): void
    {
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");
        $driver = (string) config("database.connections.{$default}.driver");

        $this->assertSame('sqlite', $default, 'tests must not use the app DB connection');
        $this->assertSame('sqlite', $driver);
        $this->assertSame(':memory:', $database);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }
}
