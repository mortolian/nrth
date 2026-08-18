<?php

namespace App\Console\Commands;

use App\Support\Upgrade\SchemaUpgradeStatus;
use Illuminate\Console\Command;

class UpgradeStatusCommand extends Command
{
    protected $signature = 'nrth:upgrade-status';

    protected $description = 'Show application version and pending database migrations (safe to run before ./scripts/update).';

    public function handle(SchemaUpgradeStatus $status): int
    {
        $this->components->info(config('app.name').' upgrade status');
        $this->line('  Application version: '.$status->applicationVersion());
        $this->line('  version.txt: '.$status->versionFile());

        $pending = $status->pendingMigrationNames();
        if ($pending === []) {
            $this->line('  Pending migrations: none');

            return self::SUCCESS;
        }

        $this->line('  Pending migrations ('.count($pending).'):');
        foreach ($pending as $name) {
            $this->line('    - '.$name);
        }

        return self::SUCCESS;
    }
}
