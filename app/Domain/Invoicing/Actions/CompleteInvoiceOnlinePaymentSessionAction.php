<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\OnlinePaymentSessionStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Support\Iso4217Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteInvoiceOnlinePaymentSessionAction
{
    public function __construct(
        private readonly RecordPaymentAction $recordPaymentAction,
    ) {}

    public function execute(
        InvoiceOnlinePaymentSession $session,
        int $paidAmountCents,
        string $gatewayReference,
        ?string $notes = null,
    ): void {
        DB::transaction(function () use ($session, $paidAmountCents, $gatewayReference, $notes): void {
            $locked = InvoiceOnlinePaymentSession::queryWithoutTeamScope()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === OnlinePaymentSessionStatus::Completed) {
                return;
            }

            if ($locked->status !== OnlinePaymentSessionStatus::Pending) {
                throw ValidationException::withMessages([
                    'session' => __('This payment session is no longer active.'),
                ]);
            }

            if ($paidAmountCents !== (int) $locked->amount_cents) {
                throw ValidationException::withMessages([
                    'amount' => __('Paid amount does not match the checkout session.'),
                ]);
            }

            $locked->loadMissing('invoice');
            $invoice = $locked->invoice;
            if ($invoice === null) {
                throw ValidationException::withMessages([
                    'invoice' => __('Invoice not found for this payment session.'),
                ]);
            }

            if ($invoice->team_id !== $locked->team_id) {
                throw ValidationException::withMessages([
                    'invoice' => __('Invoice does not belong to this team.'),
                ]);
            }

            if ($invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages([
                    'invoice' => __('Cannot record payments against a void invoice.'),
                ]);
            }

            $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
            $sessionCurrency = Iso4217Currencies::normalize((string) $locked->currency);
            if ($currency !== $sessionCurrency) {
                throw ValidationException::withMessages([
                    'currency' => __('Invoice currency does not match the payment session.'),
                ]);
            }

            $total = (int) $invoice->getRawOriginal('total_cents');
            $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
            $due = max(0, $total - $paid);
            if ($paidAmountCents < 1 || $paidAmountCents > $due) {
                throw ValidationException::withMessages([
                    'amount' => __('Payment amount is not valid for the current invoice balance.'),
                ]);
            }

            $payment = $this->recordPaymentAction->execute(new RecordPaymentDTO(
                invoiceId: $invoice->id,
                teamId: $locked->team_id,
                amountCents: $paidAmountCents,
                paymentDate: now()->toDateString(),
                method: PaymentMethod::Card,
                currency: $currency,
                reference: $gatewayReference,
                notes: $notes,
                createdBy: null,
                bankAmountCompanyCents: null,
                bookFxLossToExpense: false,
            ));

            $locked->forceFill([
                'status' => OnlinePaymentSessionStatus::Completed,
                'payment_id' => $payment->id,
                'completed_at' => now(),
            ])->save();
        });
    }
}
