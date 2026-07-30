<?php

namespace Tests\Unit;

use App\Support\DestructiveDatabaseResetGuard;
use PHPUnit\Framework\TestCase;

class DestructiveDatabaseResetGuardTest extends TestCase
{
    public function test_blocks_destructive_commands_against_app_database(): void
    {
        $this->assertTrue(DestructiveDatabaseResetGuard::shouldBlockCommand(
            'migrate:fresh',
            false,
            'pgsql',
            'nrthapp',
        ));

        $this->assertTrue(DestructiveDatabaseResetGuard::shouldBlockCommand(
            'db:wipe',
            false,
            'pgsql',
            'nrthapp',
        ));

        // Even APP_ENV=testing must not wipe Postgres (misconfigured PHPUnit).
        $this->assertTrue(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'migrate:fresh'],
            'testing',
            false,
            'pgsql',
            'nrthapp',
        ));
    }

    public function test_allows_destructive_commands_only_for_sqlite_memory_or_override(): void
    {
        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlockCommand(
            'migrate:fresh',
            false,
            'sqlite',
            ':memory:',
        ));

        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlockCommand(
            'migrate:refresh',
            true,
            'pgsql',
            'nrthapp',
        ));
    }

    public function test_ignores_non_destructive_commands(): void
    {
        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlockCommand(
            'migrate',
            false,
            'pgsql',
            'nrthapp',
        ));

        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlock(
            ['vendor/bin/phpunit'],
            'local',
            false,
            'pgsql',
            'nrthapp',
        ));
    }

    public function test_formats_clear_error_message(): void
    {
        $message = DestructiveDatabaseResetGuard::message('migrate:fresh', 'pgsql', 'nrthapp');

        $this->assertStringContainsString('migrate:fresh', $message);
        $this->assertStringContainsString('[pgsql]', $message);
        $this->assertStringContainsString('[nrthapp]', $message);
        $this->assertStringContainsString('sqlite :memory:', $message);
        $this->assertStringContainsString('NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1', $message);
    }
}
