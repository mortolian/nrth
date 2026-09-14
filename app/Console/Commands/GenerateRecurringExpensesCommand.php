<?php

namespace App\Console\Commands;

use App\Domain\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'expenses:generate-recurring';

    protected $description = 'Generate expenses from active recurring templates whose next run date is due';

    public function handle(GenerateRecurringExpenseAction $action): int
    {
        $today = Carbon::today()->toDateString();

        $due = RecurringExpense::queryWithoutTeamScope()
            ->where('status', RecurringExpenseStatus::Active->value)
            ->whereDate('next_run_date', '<=', $today)
            ->get();

        $generated = 0;

        foreach ($due as $recurring) {
            try {
                $expense = $action->execute($recurring, Carbon::parse((string) $recurring->getRawOriginal('next_run_date')));
                if ($expense !== null) {
                    $generated++;
                }
            } catch (Throwable $e) {
                Log::error('Failed to generate recurring expense', [
                    'recurring_expense_id' => $recurring->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Generated {$generated} recurring expense(s).");

        return self::SUCCESS;
    }
}
