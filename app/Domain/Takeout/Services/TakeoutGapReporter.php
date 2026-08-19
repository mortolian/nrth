<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Takeout\Models\TakeoutRun;

final class TakeoutGapReporter
{
    public function __construct(
        private readonly TakeoutDataCollector $collector,
    ) {}

    /**
     * @return list<string>
     */
    public function collect(TakeoutRun $run): array
    {
        $gaps = [];

        $expenses = $this->collector->expenses($run);
        $missingReceipts = $expenses->filter(fn ($txn) => (int) $txn->media_count === 0)->count();
        if ($missingReceipts > 0) {
            $gaps[] = "{$missingReceipts} expense(s) without receipt attachments";
        }

        if ($this->collector->bankTransactions($run)->isEmpty()) {
            $gaps[] = 'No bank transactions in this period';
        }

        return $gaps;
    }
}
