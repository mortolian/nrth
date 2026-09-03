<?php

namespace Tests\Unit\Support\Upgrade;

use App\Support\Upgrade\AdditiveMigrationPolicy;
use Tests\TestCase;

class AdditiveMigrationPolicyTest extends TestCase
{
    public function test_cutoff_migrations_do_not_drop_or_rename_in_up(): void
    {
        $policy = new AdditiveMigrationPolicy;
        $migrations = $policy->migrationsAtOrAfterCutoff();

        $this->assertNotEmpty($migrations, 'Expected at least the banking reconciliation migration after the cutoff.');

        $allowlist = AdditiveMigrationPolicy::destructiveUpAllowlist();

        foreach ($migrations as $migration) {
            if (in_array($migration['basename'], $allowlist, true)) {
                continue;
            }

            $contents = (string) file_get_contents($migration['path']);
            $violations = $policy->violationsIn($contents);

            $this->assertSame(
                [],
                $violations,
                $migration['basename'].' up() must be additive (no drop/rename). Violations: '.implode(', ', $violations)
            );
        }
    }

    public function test_destructive_up_allowlist_is_explicit_and_exists(): void
    {
        $allowlist = AdditiveMigrationPolicy::destructiveUpAllowlist();
        $this->assertSame(
            [
                '2026_08_19_121000_drop_contracts_table.php',
                '2026_08_22_201200_drop_envelope_cents_from_budget_categories.php',
            ],
            $allowlist,
            'Do not grow this list silently. Additive up() is the default after 2026-08-18.'
        );

        $policy = new AdditiveMigrationPolicy;

        foreach ($allowlist as $basename) {
            $path = database_path('migrations/'.$basename);
            $this->assertFileExists($path);
            $this->assertContains(
                $basename,
                array_column($policy->migrationsAtOrAfterCutoff(), 'basename')
            );
            $this->assertNotSame(
                [],
                $policy->violationsIn((string) file_get_contents($path)),
                $basename.' is allowlisted but has no destructive up() fragment.'
            );
        }
    }

    public function test_detects_destructive_up_fragments(): void
    {
        $policy = new AdditiveMigrationPolicy;
        $contents = <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        Schema::table('invoices', function ($table) {
            $table->dropColumn('notes');
            $table->renameColumn('old', 'new');
        });
        Schema::dropIfExists('legacy_payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
PHP;

        $this->assertSame(
            [
                '->dropColumn(',
                '->renameColumn(',
                'Schema::dropIfExists(',
            ],
            $policy->violationsIn($contents)
        );
    }

    public function test_ignores_destructive_fragments_in_down(): void
    {
        $policy = new AdditiveMigrationPolicy;
        $path = database_path('migrations/2026_08_18_120000_add_banking_reconciliation_tables.php');
        $this->assertFileExists($path);

        $this->assertSame([], $policy->violationsIn((string) file_get_contents($path)));
    }
}
