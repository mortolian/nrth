<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostInvoiceAccrualAction
{
    public function __construct(
        private readonly PostTransactionAction $postTransactionAction,
    ) {}

    public function execute(Invoice $invoice): ?Transaction
    {
        return DB::transaction(function () use ($invoice): ?Transaction {
            $invoice = Invoice::queryWithoutTeamScope()
                ->with(['lineItems', 'team'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($invoice->accrual_transaction_id !== null) {
                return Transaction::queryWithoutTeamScope()->find($invoice->accrual_transaction_id);
            }

            $team = $invoice->team ?? Team::query()->findOrFail($invoice->team_id);
            (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

            $receivable = $this->accountByCode((int) $invoice->team_id, '1100', 'Accounts Receivable');
            $vatOutput = Account::queryWithoutTeamScope()
                ->where('team_id', $invoice->team_id)
                ->where('code', '2100')
                ->first();
            $defaultIncome = $this->resolveDefaultIncomeAccount($invoice);
            $totalInvoiceCents = (int) $invoice->getRawOriginal('total_cents');

            $incomeBucketsInvoice = [];
            $vatTotalInvoice = 0;

            if ($invoice->lineItems->isEmpty()) {
                $vatTotalInvoice = (int) $invoice->getRawOriginal('vat_amount_cents');
                $exclusiveTotal = max(0, $totalInvoiceCents - $vatTotalInvoice);
                $incomeBucketsInvoice[$defaultIncome->id] = $exclusiveTotal;
            } else {
                foreach ($invoice->lineItems as $line) {
                    /** @var InvoiceLineItem $line */
                    $exclusive = max(0, (int) $line->getRawOriginal('total_cents') - (int) $line->getRawOriginal('vat_amount_cents'));
                    $vatTotalInvoice += (int) $line->getRawOriginal('vat_amount_cents');
                    $accountId = $line->income_account_id ?? $invoice->income_account_id ?? $defaultIncome->id;
                    $incomeBucketsInvoice[$accountId] = ($incomeBucketsInvoice[$accountId] ?? 0) + $exclusive;
                }
            }

            if ($totalInvoiceCents <= 0) {
                return null;
            }

            [$bookCurrency, $totalBookCents, $incomeBucketsBook, $vatTotalBook] = $this->amountsInBookCurrency(
                $invoice,
                $team,
                $totalInvoiceCents,
                $incomeBucketsInvoice,
                $vatTotalInvoice,
            );

            $transaction = Transaction::query()->create([
                'team_id' => $invoice->team_id,
                'type' => TransactionType::JournalAdjustment,
                'status' => TransactionStatus::Draft,
                'transaction_date' => optional($invoice->issue_date)?->toDateString() ?? now()->toDateString(),
                'description' => 'Invoice accrual '.$invoice->number,
                'reference' => $invoice->number,
            ]);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $receivable->id,
                'type' => EntryType::Debit,
                'amount_cents' => $totalBookCents,
                'currency' => $bookCurrency,
                'description' => 'Accounts receivable for '.$invoice->number,
            ]);

            foreach ($incomeBucketsBook as $accountId => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $accountId,
                    'type' => EntryType::Credit,
                    'amount_cents' => $amount,
                    'currency' => $bookCurrency,
                    'description' => 'Revenue for '.$invoice->number,
                ]);
            }

            if ($vatTotalBook > 0 && $vatOutput !== null) {
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $vatOutput->id,
                    'type' => EntryType::Credit,
                    'amount_cents' => $vatTotalBook,
                    'currency' => $bookCurrency,
                    'description' => 'VAT output for '.$invoice->number,
                ]);
            }

            $posted = $this->postTransactionAction->execute($transaction->fresh(['journalEntries']));
            $invoice->forceFill(['accrual_transaction_id' => $posted->id])->save();

            return $posted;
        });
    }

    /**
     * @param  array<int, int>  $incomeBucketsInvoice
     * @return array{0: string, 1: int, 2: array<int, int>, 3: int}
     */
    private function amountsInBookCurrency(
        Invoice $invoice,
        Team $team,
        int $totalInvoiceCents,
        array $incomeBucketsInvoice,
        int $vatTotalInvoice,
    ): array {
        $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $bookCurrency = Iso4217Currencies::normalize((string) (
            $invoice->business_currency_code
            ?? $team->mergedBusinessSettings()['invoice_default_currency']
            ?? 'ZAR'
        ));

        if ($invoiceCurrency === $bookCurrency) {
            return [$bookCurrency, $totalInvoiceCents, $incomeBucketsInvoice, $vatTotalInvoice];
        }

        $totalBookCents = $invoice->getRawOriginal('total_business_currency_cents');
        $rate = $invoice->fx_rate_invoice_to_business;

        if ($totalBookCents === null || $rate === null || (float) $rate <= 0) {
            throw ValidationException::withMessages([
                'currency' => __('This invoice is in :invoice but the business books use :book. Save the invoice again so an exchange rate can be stored, then mark it sent.', [
                    'invoice' => $invoiceCurrency,
                    'book' => $bookCurrency,
                ]),
            ]);
        }

        $totalBookCents = (int) $totalBookCents;
        $toBook = static fn (int $invoiceCents): int => (int) round(($invoiceCents * $totalBookCents) / max(1, $totalInvoiceCents));

        $incomeBucketsBook = [];
        foreach ($incomeBucketsInvoice as $accountId => $amount) {
            $incomeBucketsBook[$accountId] = $toBook((int) $amount);
        }
        $vatTotalBook = $toBook($vatTotalInvoice);

        $creditsSum = array_sum($incomeBucketsBook) + $vatTotalBook;
        $diff = $totalBookCents - $creditsSum;
        if ($diff !== 0 && $incomeBucketsBook !== []) {
            $largestAccountId = array_keys($incomeBucketsBook, max($incomeBucketsBook), true)[0];
            $incomeBucketsBook[$largestAccountId] += $diff;
        } elseif ($diff !== 0) {
            // No income lines — push rounding onto VAT if present, else leave unbalanced (should not happen).
            $vatTotalBook += $diff;
        }

        return [$bookCurrency, $totalBookCents, $incomeBucketsBook, $vatTotalBook];
    }

    private function resolveDefaultIncomeAccount(Invoice $invoice): Account
    {
        if ($invoice->income_account_id) {
            $account = Account::queryWithoutTeamScope()
                ->where('team_id', $invoice->team_id)
                ->whereKey($invoice->income_account_id)
                ->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->accountByCode((int) $invoice->team_id, '4000', 'Service Revenue');
    }

    private function accountByCode(int $teamId, string $code, string $label): Account
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
}
