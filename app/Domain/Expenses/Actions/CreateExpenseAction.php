<?php

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Expenses\DTOs\CreateExpenseDTO;
use App\Domain\Expenses\Services\ExpenseBooking;
use Illuminate\Support\Facades\DB;

class CreateExpenseAction
{
    public function __construct(
        private readonly ExpenseBooking $booking,
        private readonly PostTransactionAction $postTransactionAction,
    ) {}

    public function execute(CreateExpenseDTO $dto): Transaction
    {
        $payload = $dto->toPayload();
        $categoryAccount = $this->booking->resolveCategoryAccount($dto->teamId, $payload);
        $this->booking->assertCategoryRules($categoryAccount, $payload);

        [$reference, $supplierIdToSave] = $this->booking->resolveSupplier($payload, $dto->teamId);
        $normalized = $this->booking->normalizedExpenseAmounts($categoryAccount, $payload);
        $amountExclCents = $normalized[0];
        $vatAmountCents = $normalized[1];
        $vatRate = $normalized[2];
        $isVatClaimable = $normalized[3];

        $bankingAccount = $this->booking->resolvePaidFromBankingAccount($dto->teamId, $dto->paidFromBankingAccountId);
        $creditAccount = $bankingAccount->glAccount;
        abort_if($creditAccount === null, 422);

        $vatInputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $dto->teamId)
            ->where('code', '1200')
            ->first();

        $expenseMeta = $this->booking->buildExpenseMeta($categoryAccount, $payload, $bankingAccount, $creditAccount);

        return DB::transaction(function () use (
            $dto,
            $payload,
            $categoryAccount,
            $creditAccount,
            $vatInputAccount,
            $isVatClaimable,
            $amountExclCents,
            $vatAmountCents,
            $vatRate,
            $supplierIdToSave,
            $reference,
            $expenseMeta,
        ): Transaction {
            $transaction = Transaction::queryWithoutTeamScope()->create([
                'team_id' => $dto->teamId,
                'supplier_id' => $supplierIdToSave,
                'recurring_expense_id' => $dto->recurringExpenseId,
                'type' => TransactionType::Expense,
                'status' => TransactionStatus::Draft,
                'reference' => $reference,
                'description' => $this->booking->expenseDescriptionFromPayload($payload),
                'expense_meta' => $expenseMeta,
                'receipt_not_required' => $dto->receiptNotRequired,
                'transaction_date' => $dto->date,
                'created_by' => $dto->createdBy,
            ]);

            $this->booking->writeExpenseJournalAndTax(
                $transaction,
                $dto->teamId,
                $payload,
                $categoryAccount,
                $creditAccount,
                $vatInputAccount,
                $isVatClaimable,
                $amountExclCents,
                $vatAmountCents,
                $vatRate,
                $reference
            );

            return $this->postTransactionAction->execute($transaction->fresh());
        });
    }
}
