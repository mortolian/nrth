<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Takeout\Models\TakeoutRun;

final class TakeoutPreviewService
{
    public function __construct(
        private readonly TakeoutDataCollector $collector,
        private readonly TakeoutGapReporter $gapReporter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(TakeoutRun $run): array
    {
        $invoices = $this->collector->invoices($run);
        $expenses = $this->collector->expenses($run);
        $contracts = $this->collector->contracts($run);

        $expenseTotalCents = (int) $expenses->sum(
            fn ($transaction) => $this->collector->expenseTotalCents($transaction)
        );

        return [
            'invoices_count' => $invoices->count(),
            'invoices_total_cents' => (int) $invoices->sum(
                fn ($invoice) => (int) $invoice->getRawOriginal('total_cents')
            ),
            'expenses_count' => $expenses->count(),
            'expense_receipts_count' => $expenses->filter(fn ($t) => (int) $t->media_count > 0)->count(),
            'expenses_missing_receipts' => $expenses->filter(fn ($t) => (int) $t->media_count === 0)->count(),
            'expenses_total_cents' => $expenseTotalCents,
            'bank_statement_files' => $this->collector->bankStatementImports($run)->count(),
            'vat_periods_count' => $this->collector->vatPeriods($run)->count(),
            'contracts_count' => $contracts->count(),
            'contracts_missing_signed_file' => $contracts
                ->filter(fn ($contract) => $contract->getFirstMedia('signed-contract') === null)
                ->count(),
            'gaps' => $this->gapReporter->collect($run),
        ];
    }
}
