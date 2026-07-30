<?php

namespace Tests\Unit;

use App\Support\DestructiveDatabaseResetGuard;
use PHPUnit\Framework\TestCase;

class DestructiveDatabaseResetGuardTest extends TestCase
{
    public function test_blocks_destructive_commands_outside_testing_without_override(): void
    {
        $this->assertTrue(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'migrate:fresh'],
            'local',
            false,
        ));

        $this->assertTrue(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'db:wipe'],
            'production',
            false,
        ));
    }

    public function test_allows_destructive_commands_in_testing_or_with_override(): void
    {
        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'migrate:fresh'],
            'testing',
            false,
        ));

        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'migrate:refresh'],
            'local',
            true,
        ));
    }

    public function test_ignores_non_destructive_commands(): void
    {
        $this->assertFalse(DestructiveDatabaseResetGuard::shouldBlock(
            ['artisan', 'migrate'],
            'local',
            false,
        ));
    }

    public function test_formats_clear_error_message(): void
    {
        $message = DestructiveDatabaseResetGuard::message('migrate:fresh', 'pgsql', 'nrthapp');

        $this->assertStringContainsString('migrate:fresh', $message);
        $this->assertStringContainsString('[pgsql]', $message);
        $this->assertStringContainsString('[nrthapp]', $message);
        $this->assertStringContainsString('NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1', $message);
    }
}
