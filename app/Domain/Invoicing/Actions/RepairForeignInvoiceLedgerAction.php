<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rebuilds accrual + payment journals for foreign-currency invoices whose ledger
 * was posted in invoice currency (or mixed) instead of the team book currency.
 */
class RepairForeignInvoiceLedgerAction
{
    public function __construct(
        private readonly VoidTransactionAction $voidTransactionAction,
        private readonly PostInvoiceAccrualAction $postInvoiceAccrualAction,
        private readonly RecordPaymentAction $recordPaymentAction,
    ) {}

    /**
     * @return list<array{invoice_id: int, number: string, status: string, detail: string}>
     */
    public function preview(Team $team): array
    {
        return $this->candidates($team)->map(function (Invoice $invoice) use ($team): array {
            $book = $this->bookCurrency($team, $invoice);
            $accrualCurrency = $this->accrualCurrency($invoice);
            $reason = $this->needsRepairReason($invoice, $book, $accrualCurrency);

            return [
                'invoice_id' => (int) $invoice->id,
                'number' => (string) $invoice->number,
                'status' => $invoice->status->value,
                'detail' => $reason ?? 'ok',
            ];
        })->all();
    }

    /**
     * @return list<array{invoice_id: int, number: string, status: string, detail: string}>
     */
    public function execute(Team $team, bool $dryRun = true): array
    {
        $report = [];

        foreach ($this->candidates($team) as $invoice) {
            $book = $this->bookCurrency($team, $invoice);
            $accrualCurrency = $this->accrualCurrency($invoice);
            $reason = $this->needsRepairReason($invoice, $book, $accrualCurrency);

            if ($reason === null) {
                continue;
            }

            if (str_starts_with($reason, 'skip:')) {
                $report[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'status' => 'skipped',
                    'detail' => $reason,
                ];

                continue;
            }

            if ($dryRun) {
                $report[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'status' => 'would_repair',
                    'detail' => $reason,
                ];

                continue;
            }

            try {
                $this->repairInvoice($invoice->fresh(['payments', 'accrualTransaction', 'team']));
                $report[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'status' => 'repaired',
                    'detail' => $reason,
                ];
            } catch (ValidationException $e) {
                $report[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'status' => 'failed',
                    'detail' => collect($e->errors())->flatten()->implode('; '),
                ];
            } catch (\Throwable $e) {
                $report[] = [
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'status' => 'failed',
                    'detail' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    private function repairInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::queryWithoutTeamScope()
                ->with(['payments.transaction', 'accrualTransaction', 'team'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Draft], true)) {
                return;
            }

            foreach ($invoice->payments as $payment) {
                $txn = $payment->transaction;
                if ($txn !== null && $txn->status === TransactionStatus::Posted) {
                    $this->voidTransactionAction->execute(
                        $txn,
                        'Repair foreign invoice ledger for '.$invoice->number
                    );
                }
                $payment->forceFill(['transaction_id' => null])->save();
            }

            $accrual = $invoice->accrualTransaction;
            if ($accrual !== null && $accrual->status === TransactionStatus::Posted) {
                $this->voidTransactionAction->execute(
                    $accrual,
                    'Repair foreign invoice ledger for '.$invoice->number
                );
            }

            $invoice->forceFill([
                'accrual_transaction_id' => null,
                'transaction_id' => null,
            ])->save();

            $this->postInvoiceAccrualAction->execute($invoice->fresh(['lineItems', 'team']));

            $invoice = $invoice->fresh(['payments', 'team']);
            $team = $invoice->team ?? Team::query()->findOrFail($invoice->team_id);
            $defaultBankingId = (int) (new EnsureDefaultBankingAccount)->execute($team)->id;

            foreach ($invoice->payments->sortBy('id') as $payment) {
                /** @var Payment $payment */
                $bankingId = (int) ($payment->banking_account_id ?: $defaultBankingId);
                $bankBusiness = $payment->getRawOriginal('bank_amount_business_cents');
                $bankBusinessCents = $bankBusiness !== null ? (int) $bankBusiness : null;

                $totalInvoice = max(1, (int) $invoice->getRawOriginal('total_cents'));
                $totalBusiness = (int) $invoice->getRawOriginal('total_business_currency_cents');
                $payInvoice = (int) $payment->getRawOriginal('amount_cents');
                $bookClearing = (int) round(($payInvoice * $totalBusiness) / $totalInvoice);
                $effectiveBank = $bankBusinessCents ?? $bookClearing;
                $needsFxLossConfirm = $effectiveBank < $bookClearing;

                $dto = new RecordPaymentDTO(
                    invoiceId: (int) $invoice->id,
                    teamId: (int) $invoice->team_id,
                    amountCents: $payInvoice,
                    paymentDate: optional($payment->payment_date)?->toDateString() ?? now()->toDateString(),
                    bankingAccountId: $bankingId,
                    method: $payment->method,
                    currency: Iso4217Currencies::normalize((string) ($payment->currency ?? $invoice->currency ?? 'ZAR')),
                    reference: $payment->reference,
                    notes: $payment->notes,
                    createdBy: null,
                    bankAmountBusinessCents: $bankBusinessCents,
                    bookFxLossToExpense: $needsFxLossConfirm,
                );

                $this->recordPaymentAction->rebuildJournal($payment, $dto);
            }

            $latestPaymentTxn = Payment::queryWithoutTeamScope()
                ->where('invoice_id', $invoice->id)
                ->whereNotNull('transaction_id')
                ->orderByDesc('id')
                ->value('transaction_id');

            if ($latestPaymentTxn !== null) {
                $invoice->forceFill(['transaction_id' => $latestPaymentTxn])->save();
            }
        });
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function candidates(Team $team): Collection
    {
        $book = Iso4217Currencies::normalize(
            (string) ($team->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
        );

        return Invoice::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where(function ($q) use ($book): void {
                $q->whereRaw('UPPER(currency) <> ?', [$book])
                    ->orWhere(function ($q2) use ($book): void {
                        $q2->whereNotNull('business_currency_code')
                            ->whereRaw('UPPER(business_currency_code) <> UPPER(currency)');
                    });
            })
            ->with(['payments', 'accrualTransaction.journalEntries'])
            ->orderBy('id')
            ->get()
            ->filter(function (Invoice $invoice) use ($team): bool {
                $bookCurrency = $this->bookCurrency($team, $invoice);
                $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));

                return $invoiceCurrency !== $bookCurrency;
            })
            ->values();
    }

    private function bookCurrency(Team $team, Invoice $invoice): string
    {
        return Iso4217Currencies::normalize((string) (
            $invoice->business_currency_code
            ?? $team->mergedBusinessSettings()['invoice_default_currency']
            ?? 'ZAR'
        ));
    }

    private function accrualCurrency(Invoice $invoice): ?string
    {
        $accrual = $invoice->accrualTransaction;
        if ($accrual === null) {
            return null;
        }

        $line = $accrual->journalEntries->first(
            fn (JournalEntry $entry): bool => $entry->type === EntryType::Debit
        ) ?? $accrual->journalEntries->first();

        if ($line === null) {
            return null;
        }

        return Iso4217Currencies::normalize((string) $line->getRawOriginal('currency'));
    }

    private function needsRepairReason(Invoice $invoice, string $bookCurrency, ?string $accrualCurrency): ?string
    {
        $totalBusiness = $invoice->getRawOriginal('total_business_currency_cents');
        $rate = $invoice->fx_rate_invoice_to_business;

        if ($totalBusiness === null || $rate === null || (float) $rate <= 0) {
            return 'skip: missing business-currency FX snapshot — open and save the invoice first';
        }

        if ($invoice->accrual_transaction_id === null && $invoice->payments->isEmpty()) {
            return null;
        }

        if ($accrualCurrency !== null && $accrualCurrency !== $bookCurrency) {
            return sprintf(
                'accrual currency %s ≠ book %s (invoice total %s → book %s cents)',
                $accrualCurrency,
                $bookCurrency,
                (string) $invoice->getRawOriginal('total_cents'),
                (string) $totalBusiness,
            );
        }

        if ($invoice->accrual_transaction_id !== null && $accrualCurrency === $bookCurrency) {
            $arLine = $invoice->accrualTransaction?->journalEntries->first(
                fn (JournalEntry $entry): bool => $entry->type === EntryType::Debit
            );
            if ($arLine !== null && (int) $arLine->getRawOriginal('amount_cents') !== (int) $totalBusiness) {
                return sprintf(
                    'accrual AR amount %s ≠ book total %s',
                    (string) $arLine->getRawOriginal('amount_cents'),
                    (string) $totalBusiness,
                );
            }
        }

        foreach ($invoice->payments as $payment) {
            $txn = $payment->transaction;
            if ($txn === null || $txn->status !== TransactionStatus::Posted) {
                if ($payment->transaction_id === null) {
                    return 'payment #'.$payment->id.' missing ledger transaction';
                }

                continue;
            }

            $txn->loadMissing('journalEntries');
            foreach ($txn->journalEntries as $line) {
                $lineCurrency = Iso4217Currencies::normalize((string) $line->getRawOriginal('currency'));
                if ($lineCurrency !== $bookCurrency) {
                    return sprintf(
                        'payment #%d journal currency %s ≠ book %s',
                        $payment->id,
                        $lineCurrency,
                        $bookCurrency,
                    );
                }
            }
        }

        if ($invoice->accrual_transaction_id === null && $invoice->payments->isNotEmpty()) {
            return 'payments exist but accrual missing — will rebuild accrual then payment journals';
        }

        return null;
    }
}
