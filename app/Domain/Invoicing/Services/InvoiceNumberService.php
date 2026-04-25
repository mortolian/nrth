<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    public function generate(int $teamId, ?CarbonInterface $forDate = null): string
    {
        $date = $forDate ?? now();
        $year = (int) $date->format('Y');

        $sequence = DB::transaction(function () use ($teamId, $year): int {
            /** @var InvoiceNumberSequence $row */
            $row = InvoiceNumberSequence::query()
                ->where('team_id', $teamId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first() ?? InvoiceNumberSequence::query()->create([
                    'team_id' => $teamId,
                    'year' => $year,
                    'next_number' => 1,
                ]);

            $current = $row->next_number;
            $row->update(['next_number' => $current + 1]);

            return $current;
        });

        return sprintf('INV-%d-%04d', $year, $sequence);
    }
}
