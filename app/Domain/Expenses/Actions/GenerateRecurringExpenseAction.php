<?php

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Expenses\DTOs\CreateExpenseDTO;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Services\RecurringPlaceholderResolver;
use App\Domain\Invoicing\Services\RecurringScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringExpenseAction
{
    public function __construct(
        private readonly CreateExpenseAction $createExpenseAction,
        private readonly RecurringScheduleResolver $scheduleResolver,
    ) {}

    public function execute(RecurringExpense $recurring, ?Carbon $runDate = null, ?int $createdBy = null): ?Transaction
    {
        return DB::transaction(function () use ($recurring, $runDate, $createdBy): ?Transaction {
            /** @var RecurringExpense $recurring */
            $recurring = RecurringExpense::queryWithoutTeamScope()
                ->lockForUpdate()
                ->findOrFail($recurring->id);

            if ($recurring->status !== RecurringExpenseStatus::Active) {
                return null;
            }

            $issueDate = ($runDate ?? Carbon::today())->copy()->startOfDay();

            if ($recurring->supplier_id !== null) {
                $supplier = Supplier::queryWithoutTeamScope()
                    ->where('team_id', $recurring->team_id)
                    ->find($recurring->supplier_id);

                if ($supplier === null || ! $supplier->is_active) {
                    return null;
                }
            }

            $category = Account::queryWithoutTeamScope()
                ->where('team_id', $recurring->team_id)
                ->find($recurring->category_account_id);

            if ($category === null || ! $category->is_active) {
                return null;
            }

            $banking = BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $recurring->team_id)
                ->find($recurring->paid_from_banking_account_id);

            if ($banking === null || ! $banking->is_active) {
                return null;
            }

            $resolveText = fn (?string $text): ?string => RecurringPlaceholderResolver::replace(
                $text,
                $issueDate,
                $issueDate,
                (int) $recurring->period_offset_months,
            );

            try {
                $transaction = $this->createExpenseAction->execute(new CreateExpenseDTO(
                    teamId: (int) $recurring->team_id,
                    createdBy: $createdBy,
                    date: $issueDate->toDateString(),
                    supplierId: $recurring->supplier_id !== null ? (int) $recurring->supplier_id : null,
                    supplierName: $recurring->supplier_id === null ? $recurring->supplier_name : null,
                    categoryAccountId: (int) $recurring->category_account_id,
                    description: $resolveText($recurring->description),
                    amountExclVatCents: (int) $recurring->amount_excl_vat_cents,
                    vatRate: (string) $recurring->vat_rate,
                    vatAmountCents: (int) $recurring->vat_amount_cents,
                    paidFromBankingAccountId: (int) $recurring->paid_from_banking_account_id,
                    reference: $resolveText($recurring->reference),
                    notes: $resolveText($recurring->notes),
                    recurringExpenseId: (int) $recurring->id,
                ));
            } catch (Throwable $e) {
                Log::warning('Recurring expense generation failed', [
                    'recurring_expense_id' => $recurring->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $recurring->generated_count = (int) $recurring->generated_count + 1;
            $recurring->last_generated_at = now();
            $nextRunDate = $this->scheduleResolver->advance(
                $recurring->frequency,
                $issueDate,
                $recurring->generate_on_weekday !== null ? (int) $recurring->generate_on_weekday : null,
                $recurring->generate_on_day !== null ? (int) $recurring->generate_on_day : null,
                (bool) $recurring->generate_on_last_day,
                $recurring->generate_on_month !== null ? (int) $recurring->generate_on_month : null,
            );
            $recurring->next_run_date = $nextRunDate->toDateString();

            if (
                $recurring->limit_type === RecurringLimitType::Count
                && $recurring->limit_count !== null
                && $recurring->generated_count >= (int) $recurring->limit_count
            ) {
                $recurring->status = RecurringExpenseStatus::Completed;
            } elseif (
                $recurring->limit_type === RecurringLimitType::EndDate
                && $recurring->limit_end_date !== null
                && $nextRunDate->gt($recurring->limit_end_date)
            ) {
                $recurring->status = RecurringExpenseStatus::Completed;
            }

            $recurring->save();

            return $transaction->fresh();
        });
    }
}
