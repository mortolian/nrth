<?php

namespace App\Console\Commands;

use App\Domain\Invoicing\Actions\GenerateRecurringInvoiceAction;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Models\RecurringInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringInvoicesCommand extends Command
{
    protected $signature = 'invoices:generate-recurring';

    protected $description = 'Generate invoices from active recurring templates whose next run date is due';

    public function handle(GenerateRecurringInvoiceAction $action): int
    {
        $today = Carbon::today()->toDateString();

        $due = RecurringInvoice::queryWithoutTeamScope()
            ->where('status', RecurringInvoiceStatus::Active->value)
            ->whereDate('next_run_date', '<=', $today)
            ->get();

        $generated = 0;

        foreach ($due as $recurring) {
            try {
                $invoice = $action->execute($recurring, Carbon::parse((string) $recurring->getRawOriginal('next_run_date')));
                if ($invoice !== null) {
                    $generated++;
                }
            } catch (Throwable $e) {
                Log::error('Failed to generate recurring invoice', [
                    'recurring_invoice_id' => $recurring->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Generated {$generated} recurring invoice(s).");

        return self::SUCCESS;
    }
}
