<?php

namespace Tests\Feature\Upgrade;

use App\Support\Upgrade\SchemaUpgradeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_status_reports_version_and_no_pending_migrations(): void
    {
        $this->artisan('nrth:upgrade-status')
            ->assertSuccessful()
            ->expectsOutputToContain('Application version:')
            ->expectsOutputToContain('version.txt:')
            ->expectsOutputToContain('Pending migrations: none');
    }

    public function test_schema_upgrade_status_reads_version_file(): void
    {
        $status = app(SchemaUpgradeStatus::class);

        $this->assertNotSame('', $status->applicationVersion());
        $this->assertNotSame('unknown', $status->versionFile());
        $this->assertSame([], $status->pendingMigrationNames());
    }
}
