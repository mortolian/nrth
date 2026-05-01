<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UndoInvoicePaymentAction
{
    public function __construct(
        private readonly VoidTransactionAction $voidTransactionAction,
    ) {}

    public function execute(Payment $payment, int $teamId, ?string $reason = null): void
    {
        DB::transaction(function () use ($payment, $teamId, $reason): void {
            if ($payment->team_id !== $teamId) {
                throw ValidationException::withMessages([
                    'payment' => __('This payment does not belong to your team.'),
                ]);
            }

            $invoice = Invoice::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->lockForUpdate()
                ->findOrFail($payment->invoice_id);

            if ($invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages([
                    'payment' => __('Cannot undo payments on a void invoice.'),
                ]);
            }

            $paymentAmount = (int) $payment->getRawOriginal('amount_cents');
            $currentPaid = (int) $invoice->getRawOriginal('amount_paid_cents');
            $applyAmount = min($paymentAmount, $currentPaid);

            $transaction = $payment->transaction_id !== null
                ? Transaction::queryWithoutTeamScope()->lockForUpdate()->find($payment->transaction_id)
                : null;

            if ($transaction !== null) {
                if ($transaction->team_id !== $teamId) {
                    throw ValidationException::withMessages([
                        'payment' => __('Invalid payment transaction.'),
                    ]);
                }

                if ($transaction->status === TransactionStatus::Posted) {
                    $this->voidTransactionAction->execute(
                        $transaction->fresh(),
                        $reason ?? __('Invoice payment undone.')
                    );
                } elseif ($transaction->status === TransactionStatus::Draft) {
                    $transaction->clearMediaCollection('attachments');
                    $transaction->delete();
                } elseif ($transaction->status === TransactionStatus::Void) {
                    throw ValidationException::withMessages([
                        'payment' => __('This payment was already reversed in the ledger.'),
                    ]);
                }
            }

            $newPaid = max(0, $currentPaid - $applyAmount);
            $invoice->amount_paid_cents = $newPaid;

            $total = (int) $invoice->getRawOriginal('total_cents');
            if ($newPaid >= $total && $total > 0) {
                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = now();
            } elseif ($newPaid > 0) {
                $invoice->status = InvoiceStatus::Partial;
                $invoice->paid_at = null;
            } else {
                $invoice->status = $this->statusAfterZeroPayments($invoice);
                $invoice->paid_at = null;
            }

            $nextTransactionId = Payment::queryWithoutTeamScope()
                ->where('invoice_id', $invoice->id)
                ->where('id', '!=', $payment->id)
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->value('transaction_id');

            $invoice->transaction_id = $nextTransactionId;
            $invoice->save();

            $payment->delete();
        });
    }

    private function statusAfterZeroPayments(Invoice $invoice): InvoiceStatus
    {
        $due = $invoice->due_date;
        if ($due !== null && $due->isPast()) {
            return InvoiceStatus::Overdue;
        }
        if ($invoice->viewed_at !== null) {
            return InvoiceStatus::Viewed;
        }
        if ($invoice->sent_at !== null) {
            return InvoiceStatus::Sent;
        }

        return InvoiceStatus::Draft;
    }
}
