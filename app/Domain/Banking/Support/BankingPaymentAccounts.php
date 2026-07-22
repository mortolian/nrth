<?php

namespace App\Domain\Banking\Support;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class BankingPaymentAccounts
{
    /**
     * Active banking accounts with a GL link (expense paid-from: asset or liability).
     *
     * @return list<array{id: int, name: string, gl_account_id: int, gl_label: string}>
     */
    public static function forExpensePaidFrom(int $teamId): array
    {
        return self::serialize(
            self::linkedQuery($teamId)->get()
        );
    }

    /**
     * Active banking accounts whose GL is an asset (invoice deposits).
     *
     * @return list<array{id: int, name: string, gl_account_id: int, gl_label: string}>
     */
    public static function forInvoiceDeposit(int $teamId): array
    {
        return self::serialize(
            self::linkedQuery($teamId)
                ->whereHas('glAccount', fn ($q) => $q->where('type', AccountType::Asset->value))
                ->get()
        );
    }

    /**
     * GL accounts eligible to link to a banking account.
     *
     * @return list<array{id: int, code: string, name: string, label: string}>
     */
    public static function linkableGlOptions(int $teamId, ?int $currentGlAccountId = null): array
    {
        $linkedIds = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereNotNull('gl_account_id')
            ->when($currentGlAccountId !== null, fn ($q) => $q->where('gl_account_id', '!=', $currentGlAccountId))
            ->pluck('gl_account_id')
            ->all();

        return Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value])
            ->whereNotIn('id', $linkedIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'label' => trim($account->code.' - '.$account->name),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<BankingAccount>
     */
    private static function linkedQuery(int $teamId)
    {
        return BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->with('glAccount:id,code,name,type')
            ->orderBy('name');
    }

    /**
     * @param  Collection<int, BankingAccount>  $accounts
     * @return list<array{id: int, name: string, gl_account_id: int, gl_label: string}>
     */
    private static function serialize(Collection $accounts): array
    {
        return $accounts
            ->filter(fn (BankingAccount $account) => $account->glAccount !== null)
            ->map(fn (BankingAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'gl_account_id' => (int) $account->gl_account_id,
                'gl_label' => trim($account->glAccount->code.' - '.$account->glAccount->name),
            ])
            ->values()
            ->all();
    }
}
