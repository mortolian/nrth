<?php

namespace App\Console\Commands;

use App\Domain\Instance\Services\InstanceOperatorService;
use Illuminate\Console\Command;

class PromoteFirstOperatorCommand extends Command
{
    protected $signature = 'nrth:promote-first-operator';

    protected $description = 'Promote the oldest user to instance operator if none exist yet (safe for upgrades).';

    public function handle(InstanceOperatorService $operators): int
    {
        if ($operators->databaseOperatorCount() > 0) {
            $this->components->info('Database operators already exist; nothing to do.');

            return self::SUCCESS;
        }

        $user = $operators->promoteFirstUserIfNoOperators();

        if ($user === null) {
            $this->components->warn('No users found to promote.');

            return self::FAILURE;
        }

        $this->components->info("Promoted {$user->email} to instance operator.");

        return self::SUCCESS;
    }
}
