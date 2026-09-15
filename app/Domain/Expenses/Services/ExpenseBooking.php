<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Tax\Models\TaxRate;
use Illuminate\Validation\ValidationException;

class ExpenseBooking
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveCategoryAccount(int $teamId, array $payload): Account
    {
        return Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Expense->value)
            ->findOrFail((int) $payload['category_account_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertCategoryRules(Account $categoryAccount, array $payload): void
    {
        $name = strtolower($categoryAccount->name);
        $isTravel = str_contains($name, 'travel');
        if ($isTravel) {
            $km = (float) ($payload['distance_km'] ?? 0);
            if ($km <= 0) {
                throw ValidationException::withMessages([
                    'distance_km' => __('Enter distance in kilometres for travel expenses.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int|null}
     */
    public function resolveSupplier(array $payload, int $teamId): array
    {
        $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : 0;
        if ($supplierId > 0) {
            $supplierRow = Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereKey($supplierId)
                ->firstOrFail();

            return [$supplierRow->name, $supplierRow->id];
        }

        return [trim((string) ($payload['supplier'] ?? '')), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int, 2: string, 3: bool}
     */
    public function normalizedExpenseAmounts(Account $categoryAccount, array $payload): array
    {
        $name = strtolower($categoryAccount->name);
        $isHomeOffice = str_contains($name, 'home office');
        $isTravel = str_contains($name, 'travel');

        $excl = (int) $payload['amount_excl_vat_cents'];
        $vat = (int) $payload['vat_amount_cents'];
        $vatRate = (string) $payload['vat_rate'];

        if ($isTravel) {
            $km = (float) ($payload['distance_km'] ?? 0);
            $rate = (float) ($payload['rate_per_km'] ?? 0);
            $excl = (int) round($km * $rate * 100);
            $vat = 0;
            $vatRate = 'no_vat';
        } elseif ($isHomeOffice) {
            $pct = (float) ($payload['office_percentage'] ?? 0);
            $factor = max(0.0, min(100.0, $pct)) / 100.0;
            $excl = (int) round($excl * $factor);
            $vat = (int) round($vat * $factor);
        }

        $isVatClaimable = in_array($vatRate, ['vat15', 'vat0'], true) && $vat > 0;

        return [$excl, $vat, $vatRate, $isVatClaimable];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function expenseDescriptionFromPayload(array $payload): ?string
    {
        $d = trim((string) ($payload['description'] ?? ''));
        $n = trim((string) ($payload['notes'] ?? ''));

        if ($d !== '') {
            return $d;
        }
        if ($n !== '') {
            return $n;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function buildExpenseMeta(
        Account $categoryAccount,
        array $payload,
        BankingAccount $bankingAccount,
        Account $paidFromGlAccount,
    ): ?array {
        $name = strtolower($categoryAccount->name);
        $meta = [
            'paid_from_banking_account_id' => $bankingAccount->id,
            'paid_from_banking_account_name' => $bankingAccount->name,
            'paid_from_account_id' => $paidFromGlAccount->id,
            'paid_from_account_name' => trim($paidFromGlAccount->code.' - '.$paidFromGlAccount->name),
            'external_reference' => trim((string) ($payload['reference'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];
        if (str_contains($name, 'home office')) {
            $meta['office_percentage'] = (float) ($payload['office_percentage'] ?? 0);
            $meta['entered_amount_excl_vat_cents'] = (int) $payload['amount_excl_vat_cents'];
            $meta['entered_vat_amount_cents'] = (int) $payload['vat_amount_cents'];
        }
        if (str_contains($name, 'travel')) {
            $meta['distance_km'] = (float) ($payload['distance_km'] ?? 0);
            $meta['rate_per_km'] = (float) ($payload['rate_per_km'] ?? 0);
        }

        return $meta;
    }

    public function resolvePaidFromBankingAccount(int $teamId, int $bankingAccountId): BankingAccount
    {
        $account = BankingAccount::queryWithoutTeamScope()
            ->with('glAccount')
            ->where('team_id', $teamId)
            ->whereKey($bankingAccountId)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'paid_from_banking_account_id' => __('Select a valid banking account for this business.'),
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'paid_from_banking_account_id' => __('That banking account is inactive.'),
            ]);
        }

        if ($account->gl_account_id === null || $account->glAccount === null) {
            throw ValidationException::withMessages([
                'paid_from_banking_account_id' => __('Link that banking account to a ledger account first.'),
            ]);
        }

        if (! $account->glAccount->is_active) {
            throw ValidationException::withMessages([
                'paid_from_banking_account_id' => __('The linked ledger account is inactive.'),
            ]);
        }

        if (! in_array($account->glAccount->type, [AccountType::Asset, AccountType::Liability], true)) {
            throw ValidationException::withMessages([
                'paid_from_banking_account_id' => __('Paid from must link to an asset or liability ledger account.'),
            ]);
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeExpenseJournalAndTax(
        Transaction $transaction,
        int $teamId,
        array $payload,
        Account $categoryAccount,
        Account $creditAccount,
        ?Account $vatInputAccount,
        bool $isVatClaimable,
        int $amountExclCents,
        int $vatAmountCents,
        string $vatRate,
        string $reference,
    ): void {
        $totalCents = $amountExclCents + $vatAmountCents;

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $categoryAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => $amountExclCents,
            'currency' => 'ZAR',
            'description' => 'Expense: '.($payload['description'] ?? $reference),
        ]);

        $creditAmount = $totalCents;
        if ($isVatClaimable) {
            if ($vatInputAccount === null) {
                throw ValidationException::withMessages([
                    'vat_rate' => __('VAT input account (1200) is missing. Restore it from the chart of accounts.'),
                ]);
            }

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $vatInputAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => $vatAmountCents,
                'currency' => 'ZAR',
                'description' => 'VAT input claimable',
            ]);
        } else {
            $creditAmount = $amountExclCents;
        }

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $creditAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => $creditAmount,
            'currency' => 'ZAR',
            'description' => 'Expense payment',
        ]);

        if ($vatAmountCents > 0 && $isVatClaimable) {
            $taxRate = $this->resolveExpenseTaxRate($teamId, $vatRate);
            if ($taxRate === null) {
                throw ValidationException::withMessages([
                    'vat_rate' => __('Add a VAT rate in Tax settings before recording claimable VAT on expenses.'),
                ]);
            }

            TaxLine::query()->create([
                'transaction_id' => $transaction->id,
                'tax_rate_id' => $taxRate->id,
                'taxable_amount_cents' => $amountExclCents,
                'tax_amount_cents' => $vatAmountCents,
                'type' => TaxLineType::Input,
            ]);
        }
    }

    public function resolveExpenseTaxRate(int $teamId, string $vatRate): ?TaxRate
    {
        $code = match ($vatRate) {
            'vat15' => 'VAT15',
            'vat0' => 'VAT0',
            'exempt' => 'EXEMPT',
            default => null,
        };

        if ($code !== null) {
            $matched = TaxRate::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('code', $code)
                ->where('is_active', true)
                ->first();
            if ($matched !== null) {
                return $matched;
            }
        }

        return TaxRate::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }
}
