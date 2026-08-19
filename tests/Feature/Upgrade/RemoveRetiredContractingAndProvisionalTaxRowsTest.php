<?php

namespace Tests\Feature\Upgrade;

use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoveRetiredContractingAndProvisionalTaxRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_deletes_orphan_rows_and_stale_permission_keys(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'preferences' => [
                'notify_vat_due' => true,
                'notify_provisional_tax' => true,
            ],
        ]);
        $team = $user->currentTeam;

        DB::table('tax_periods')->insert([
            'team_id' => $team->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-08-31',
            'type' => 'provisional',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_modules')->insert([
            'team_id' => $team->id,
            'name' => 'contracting',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => 'legacy-ops',
            'name' => 'Legacy ops',
            'permissions' => ['invoices.view', 'contracts.view', 'contracts.manage'],
            'is_system' => false,
        ]);

        $migration = require database_path('migrations/2026_08_19_120000_remove_retired_contracting_and_provisional_tax_rows.php');
        $migration->up();

        $this->assertDatabaseMissing('tax_periods', [
            'team_id' => $team->id,
            'type' => 'provisional',
        ]);
        $this->assertDatabaseMissing('team_modules', [
            'team_id' => $team->id,
            'name' => 'contracting',
        ]);
        $this->assertSame(
            ['invoices.view'],
            $role->fresh()->permissions
        );
        $this->assertArrayNotHasKey(
            'notify_provisional_tax',
            $user->fresh()->preferences ?? []
        );
        $this->assertTrue((bool) ($user->fresh()->preferences['notify_vat_due'] ?? false));
    }

    public function test_contracts_table_is_dropped_after_migrate(): void
    {
        $this->assertFalse(Schema::hasTable('contracts'));
    }
}
