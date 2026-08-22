<?php

namespace App\Console\Commands;

use App\Domain\Invoicing\Actions\RepairForeignInvoiceLedgerAction;
use App\Models\Team;
use Illuminate\Console\Command;

class RepairForeignInvoiceLedgerCommand extends Command
{
    protected $signature = 'invoicing:repair-foreign-ledger
                            {--team= : Team ID to repair}
                            {--dry-run : Report what would change without writing (default when --apply is omitted)}
                            {--apply : Apply repairs (voids bad journals and rebuilds from invoice + payment rows)}';

    protected $description = 'Rebuild invoice accruals and payment journals in the team book currency for foreign-currency invoices';

    public function handle(RepairForeignInvoiceLedgerAction $action): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId < 1) {
            $this->error('Pass --team=ID (required).');

            return self::FAILURE;
        }

        $team = Team::query()->find($teamId);
        if ($team === null) {
            $this->error("Team {$teamId} not found.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');

        if ($apply && (bool) $this->option('dry-run')) {
            $this->warn('Both --apply and --dry-run were passed; running dry-run only.');
            $dryRun = true;
        }

        $book = (string) ($team->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR');
        $this->info(sprintf(
            'Team %d (%s) — book currency %s — %s',
            $team->id,
            $team->name,
            $book,
            $dryRun ? 'DRY RUN' : 'APPLYING REPAIRS'
        ));

        $report = $action->execute($team, $dryRun);

        if ($report === []) {
            $this->info('No foreign-currency invoices needed attention.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice ID', 'Number', 'Status', 'Detail'],
            array_map(static fn (array $row): array => [
                $row['invoice_id'],
                $row['number'],
                $row['status'],
                $row['detail'],
            ], $report)
        );

        $counts = collect($report)->countBy('status');
        foreach ($counts as $status => $count) {
            $this->line("{$status}: {$count}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Re-run with --apply to void mismatched journals and rebuild them in book currency.');
        }

        return collect($report)->contains(fn (array $row): bool => $row['status'] === 'failed')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
