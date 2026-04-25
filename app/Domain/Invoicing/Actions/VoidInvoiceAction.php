<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidInvoiceAction
{
    public function __construct(
        private readonly VoidTransactionAction $voidTransactionAction,
    ) {}

    public function execute(Invoice $invoice, string $reason = 'Invoice voided'): Invoice
    {
        if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Sent], true)) {
            throw ValidationException::withMessages([
                'status' => __('Only draft or sent invoices can be voided.'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $invoice->loadMissing('payments');

            foreach ($invoice->payments as $payment) {
                $transaction = $payment->transaction;

                if ($transaction !== null && $transaction->status === TransactionStatus::Posted) {
                    $this->voidTransactionAction->execute(
                        $transaction,
                        'Invoice '.$invoice->number.' voided: '.$reason
                    );
                }
            }

            $invoice->status = InvoiceStatus::Void;
            $invoice->voided_at = now();
            $invoice->save();

            return $invoice->refresh();
        });
    }
}
