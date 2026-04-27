<?php

namespace App\Console\Commands;

use App\Domain\Invoicing\Enums\QuoteStatus;
use App\Domain\Invoicing\Models\Quote;
use Illuminate\Console\Command;

class QuotesExpireCommand extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark past-due sent/draft quotes as expired';

    public function handle(): int
    {
        $today = now()->toDateString();

        $updated = Quote::queryWithoutTeamScope()
            ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
            ->whereDate('expiry_date', '<', $today)
            ->update(['status' => QuoteStatus::Expired->value]);

        $this->info("Expired {$updated} quote(s).");

        return self::SUCCESS;
    }
}

