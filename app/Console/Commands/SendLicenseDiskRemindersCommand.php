<?php

namespace App\Console\Commands;

use App\Domain\Vehicles\Actions\SendLicenseDiskRemindersAction;
use Illuminate\Console\Command;

class SendLicenseDiskRemindersCommand extends Command
{
    protected $signature = 'vehicles:send-license-disk-reminders';

    protected $description = 'Email team members about vehicle licence discs expiring within 30 days';

    public function handle(SendLicenseDiskRemindersAction $action): int
    {
        $result = $action->execute();

        $this->info(sprintf(
            'Sent licence disc reminders for %d vehicle(s) to %d recipient(s).',
            $result['reminded'],
            $result['recipients'],
        ));

        return self::SUCCESS;
    }
}
