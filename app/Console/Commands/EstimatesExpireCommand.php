<?php

namespace App\Console\Commands;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Estimate;
use Illuminate\Console\Command;

class EstimatesExpireCommand extends Command
{
    protected $signature = 'estimates:expire';

    protected $description = 'Mark past-due sent/draft estimates as expired';

    public function handle(): int
    {
        $today = now()->toDateString();

        $updated = Estimate::queryWithoutTeamScope()
            ->whereIn('status', [EstimateStatus::Draft->value, EstimateStatus::Sent->value])
            ->whereDate('expiry_date', '<', $today)
            ->update(['status' => EstimateStatus::Expired->value]);

        $this->info("Expired {$updated} estimate(s).");

        return self::SUCCESS;
    }
}
