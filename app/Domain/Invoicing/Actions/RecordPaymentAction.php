<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPaymentAction
{
    public function __construct(
        private readonly PostTransactionAction $postTransactionAction,
    ) {}

    public function execute(RecordPaymentDTO $dto): Payment
    {
        return DB::transaction(function () use ($dto): Payment {
            $invoice = Invoice::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->findOrFail($dto->invoiceId);

            if ($invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages([
                    'invoice_id' => __('Cannot record payments against a void invoice.'),
                ]);
            }

            $bankAccount = $this->getRequiredAccount($dto->teamId, '1010', 'Bank');
            $receivableAccount = $this->getRequiredAccount($dto->teamId, '1100', 'Accounts Receivable');
            $vatOutputAccount = Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', '2100')
                ->first();

            $transaction = Transaction::query()->create([
                'team_id' => $dto->teamId,
                'type' => TransactionType::Payment,
                'status' => TransactionStatus::Draft,
                'reference' => $dto->reference,
                'description' => 'Invoice payment '.$invoice->number,
                'transaction_date' => $dto->paymentDate,
                'created_by' => $dto->createdBy,
            ]);

            $amountCents = $dto->amountCents;
            $vatPart = $this->calculateVatPart($invoice, $amountCents);
            $receivablePart = max(0, $amountCents - $vatPart);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $bankAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => $amountCents,
                'currency' => $dto->currency,
                'description' => 'Payment received for '.$invoice->number,
            ]);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $receivableAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $receivablePart,
                'currency' => $dto->currency,
                'description' => 'Reduce accounts receivable for '.$invoice->number,
            ]);

            if ($vatPart > 0 && $vatOutputAccount !== null) {
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $vatOutputAccount->id,
                    'type' => EntryType::Credit,
                    'amount_cents' => $vatPart,
                    'currency' => $dto->currency,
                    'description' => 'VAT output on payment for '.$invoice->number,
                ]);
            } elseif ($vatPart > 0) {
                // No output VAT account configured: keep entries balanced by collapsing to A/R.
                $receivableOnly = JournalEntry::query()
                    ->where('transaction_id', $transaction->id)
                    ->where('account_id', $receivableAccount->id)
                    ->firstOrFail();

                $receivableOnly->amount_cents = $amountCents;
                $receivableOnly->save();
            }

            $this->postTransactionAction->execute($transaction->fresh());

            $payment = Payment::queryWithoutTeamScope()->create([
                'team_id' => $dto->teamId,
                'invoice_id' => $invoice->id,
                'amount_cents' => $dto->amountCents,
                'currency' => $dto->currency,
                'payment_date' => $dto->paymentDate,
                'method' => $dto->method,
                'reference' => $dto->reference,
                'notes' => $dto->notes,
                'transaction_id' => $transaction->id,
            ]);

            $newPaid = (int) $invoice->getRawOriginal('amount_paid_cents') + $dto->amountCents;
            $invoiceTotal = (int) $invoice->getRawOriginal('total_cents');
            $newPaidClamped = min($newPaid, $invoiceTotal);

            $invoice->amount_paid_cents = $newPaidClamped;
            $invoice->transaction_id = $invoice->transaction_id ?? $transaction->id;
            $invoice->status = $newPaidClamped >= $invoiceTotal
                ? InvoiceStatus::Paid
                : InvoiceStatus::Partial;
            $invoice->paid_at = $invoice->status === InvoiceStatus::Paid ? now() : null;
            $invoice->save();

            return $payment->refresh();
        });
    }

    private function getRequiredAccount(int $teamId, string $code, string $label): Account
    {
        $account = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', $code)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __("Missing required chart account: {$label} ({$code})."),
            ]);
        }

        return $account;
    }

    private function calculateVatPart(Invoice $invoice, int $paymentAmountCents): int
    {
        $total = (int) $invoice->getRawOriginal('total_cents');
        $vat = (int) $invoice->getRawOriginal('vat_amount_cents');

        if ($total <= 0 || $vat <= 0) {
            return 0;
        }

        return (int) round(($paymentAmountCents * $vat) / $total);
    }
}
