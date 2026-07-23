<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;

class SuggestAccountCode
{
    /**
     * Suggest the next chart code for a team, optionally under a parent account.
     * Uses numeric codes in steps of 10 (matching the default SA chart).
     */
    public function for(int $teamId, AccountType $type, ?int $parentId = null): string
    {
        $base = match ($type) {
            AccountType::Asset => 1000,
            AccountType::Liability => 2000,
            AccountType::Equity => 3000,
            AccountType::Income => 4000,
            AccountType::Expense => 5000,
        };

        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->get(['id', 'code', 'type', 'parent_id']);

        $used = $accounts
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->flip();

        if ($parentId !== null) {
            $parent = $accounts->firstWhere('id', $parentId);
            $siblingNumbers = $accounts
                ->where('parent_id', $parentId)
                ->map(fn (Account $account) => $this->numericCode($account->code))
                ->filter(fn (?int $n) => $n !== null)
                ->values();

            $parentNumber = $parent ? $this->numericCode($parent->code) : null;
            $max = $siblingNumbers->max();

            if ($max !== null) {
                $next = $max + 10;
            } elseif ($parentNumber !== null) {
                $next = $parentNumber + 10;
            } else {
                $next = $base;
            }
        } else {
            $typeNumbers = $accounts
                ->filter(fn (Account $account) => $account->type === $type)
                ->map(fn (Account $account) => $this->numericCode($account->code))
                ->filter(fn (?int $n) => $n !== null)
                ->values();

            $max = $typeNumbers->max();
            $next = $max !== null ? $max + 10 : $base;
            if ($next < $base) {
                $next = $base;
            }
        }

        while ($used->has((string) $next)) {
            $next += 10;
        }

        return (string) $next;
    }

    private function numericCode(string $code): ?int
    {
        $trimmed = trim($code);

        return ctype_digit($trimmed) ? (int) $trimmed : null;
    }
}
