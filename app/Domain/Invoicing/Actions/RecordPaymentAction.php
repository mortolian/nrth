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
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Database\Seeders\DefaultChartOfAccountsSeeder;
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
                ->with('team')
                ->findOrFail($dto->invoiceId);

            if ($invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages([
                    'invoice_id' => __('Cannot record payments against a void invoice.'),
                ]);
            }

            $team = $invoice->team ?? Team::query()->findOrFail($dto->teamId);
            (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

            if ($this->shouldPostInCompanyFunctionalCurrency($invoice)) {
                if ($dto->bankAmountCompanyCents !== null && $dto->bankAmountCompanyCents < 0) {
                    throw ValidationException::withMessages([
                        'bank_amount_company_cents' => __('Bank amount cannot be negative.'),
                    ]);
                }

                return $this->executeFunctionalCurrencyPayment($dto, $invoice);
            }

            if ($dto->bankAmountCompanyCents !== null) {
                throw ValidationException::withMessages([
                    'bank_amount_company_cents' => __('Bank amount in company currency only applies when the invoice currency differs from the company book currency and a company-currency snapshot exists.'),
                ]);
            }

            if ($dto->bookFxLossToExpense) {
                throw ValidationException::withMessages([
                    'book_fx_loss_to_expense' => __('Foreign exchange loss posting is only used for foreign-currency invoices with a company snapshot.'),
                ]);
            }

            return $this->executeInvoiceCurrencyPayment($dto, $invoice);
        });
    }

    private function shouldPostInCompanyFunctionalCurrency(Invoice $invoice): bool
    {
        $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $bookCurrency = Iso4217Currencies::normalize((string) (
            $invoice->company_currency_code
            ?? $invoice->team?->mergedCompanySettings()['invoice_default_currency']
            ?? 'ZAR'
        ));
        if ($invoiceCurrency === $bookCurrency) {
            return false;
        }

        $rawTotalCompany = $invoice->getRawOriginal('total_company_currency_cents');

        return $rawTotalCompany !== null;
    }

    private function executeInvoiceCurrencyPayment(RecordPaymentDTO $dto, Invoice $invoice): Payment
    {
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
            $receivableOnly = JournalEntry::query()
                ->where('transaction_id', $transaction->id)
                ->where('account_id', $receivableAccount->id)
                ->firstOrFail();

            $receivableOnly->amount_cents = $amountCents;
            $receivableOnly->save();
        }

        $this->postTransactionAction->execute($transaction->fresh());

        return $this->finalizePayment($dto, $invoice, $transaction->id, null);
    }

    private function executeFunctionalCurrencyPayment(RecordPaymentDTO $dto, Invoice $invoice): Payment
    {
        $bookCurrency = Iso4217Currencies::normalize((string) (
            $invoice->company_currency_code
            ?? $invoice->team?->mergedCompanySettings()['invoice_default_currency']
            ?? 'ZAR'
        ));

        $bankAccount = $this->getRequiredAccount($dto->teamId, '1010', 'Bank');
        $receivableAccount = $this->getRequiredAccount($dto->teamId, '1100', 'Accounts Receivable');
        $vatOutputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $dto->teamId)
            ->where('code', '2100')
            ->first();

        $paymentInvoiceCents = $dto->amountCents;
        $totalInvoiceCents = max(1, (int) $invoice->getRawOriginal('total_cents'));
        $totalCompanyCents = (int) $invoice->getRawOriginal('total_company_currency_cents');

        $bookClearingCompany = (int) round(($paymentInvoiceCents * $totalCompanyCents) / $totalInvoiceCents);
        $bankCompany = $dto->bankAmountCompanyCents ?? $bookClearingCompany;
        $fxDiff = $bankCompany - $bookClearingCompany;

        if ($fxDiff < 0 && ! $dto->bookFxLossToExpense) {
            throw ValidationException::withMessages([
                'book_fx_loss_to_expense' => __('The bank amount is below the book value of this payment. Confirm “Record foreign exchange loss to expenses” or adjust the bank amount.'),
            ]);
        }

        $vatPartInvoice = $this->calculateVatPart($invoice, $paymentInvoiceCents);

        if ($vatPartInvoice > 0 && $vatOutputAccount !== null) {
            $vatPartCompany = $paymentInvoiceCents > 0
                ? (int) round(($vatPartInvoice * $bookClearingCompany) / $paymentInvoiceCents)
                : 0;
            $arPartCompany = max(0, $bookClearingCompany - $vatPartCompany);
        } elseif ($vatPartInvoice > 0) {
            $vatPartCompany = 0;
            $arPartCompany = $bookClearingCompany;
        } else {
            $vatPartCompany = 0;
            $arPartCompany = $bookClearingCompany;
        }

        $transaction = Transaction::query()->create([
            'team_id' => $dto->teamId,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Draft,
            'reference' => $dto->reference,
            'description' => 'Invoice payment '.$invoice->number,
            'transaction_date' => $dto->paymentDate,
            'created_by' => $dto->createdBy,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $bankAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => $bankCompany,
            'currency' => $bookCurrency,
            'description' => 'Payment received for '.$invoice->number,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $receivableAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => $arPartCompany,
            'currency' => $bookCurrency,
            'description' => 'Reduce accounts receivable for '.$invoice->number,
        ]);

        if ($vatPartCompany > 0 && $vatOutputAccount !== null) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $vatOutputAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $vatPartCompany,
                'currency' => $bookCurrency,
                'description' => 'VAT output on payment for '.$invoice->number,
            ]);
        }

        if ($fxDiff > 0) {
            $gainAccount = Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', '4950')
                ->first();
            if ($gainAccount === null) {
                throw ValidationException::withMessages([
                    'bank_amount_company_cents' => __('Missing chart account Foreign Exchange Gain (4950). Run chart setup or contact support.'),
                ]);
            }
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $gainAccount->id,
                'type' => EntryType::Credit,
                'amount_cents' => $fxDiff,
                'currency' => $bookCurrency,
                'description' => 'Foreign exchange gain on '.$invoice->number,
            ]);
        } elseif ($fxDiff < 0) {
            $lossAccount = Account::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->where('code', '5900')
                ->first();
            if ($lossAccount === null) {
                throw ValidationException::withMessages([
                    'book_fx_loss_to_expense' => __('Missing chart account Foreign Exchange Loss (5900). Run chart setup or contact support.'),
                ]);
            }
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $lossAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => abs($fxDiff),
                'currency' => $bookCurrency,
                'description' => 'Foreign exchange loss on '.$invoice->number,
            ]);
        }

        $this->postTransactionAction->execute($transaction->fresh());

        return $this->finalizePayment($dto, $invoice, $transaction->id, $bankCompany);
    }

    private function finalizePayment(RecordPaymentDTO $dto, Invoice $invoice, int $transactionId, ?int $bankCompanyCents): Payment
    {
        $payment = Payment::queryWithoutTeamScope()->create([
            'team_id' => $dto->teamId,
            'invoice_id' => $invoice->id,
            'amount_cents' => $dto->amountCents,
            'currency' => $dto->currency,
            'bank_amount_company_cents' => $bankCompanyCents,
            'payment_date' => $dto->paymentDate,
            'method' => $dto->method,
            'reference' => $dto->reference,
            'notes' => $dto->notes,
            'transaction_id' => $transactionId,
        ]);

        $newPaid = (int) $invoice->getRawOriginal('amount_paid_cents') + $dto->amountCents;
        $invoiceTotal = (int) $invoice->getRawOriginal('total_cents');
        $newPaidClamped = min($newPaid, $invoiceTotal);

        $invoice->amount_paid_cents = $newPaidClamped;
        $invoice->transaction_id = $invoice->transaction_id ?? $transactionId;
        $invoice->status = $newPaidClamped >= $invoiceTotal
            ? InvoiceStatus::Paid
            : InvoiceStatus::Partial;
        $invoice->paid_at = $invoice->status === InvoiceStatus::Paid ? now() : null;
        $invoice->save();

        return $payment->refresh();
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
