<?php

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Models\Team;
use Brick\Money\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class ProvisionalTaxService
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function getCurrentTaxYear(Team $team): array
    {
        $today = CarbonImmutable::now();
        $yearStart = $today->month >= 3
            ? CarbonImmutable::create($today->year, 3, 1, 0, 0, 0, $today->timezone)
            : CarbonImmutable::create($today->year - 1, 3, 1, 0, 0, 0, $today->timezone);

        $yearEnd = $yearStart->addYear()->subDay();

        return [
            'start' => $yearStart,
            'end' => $yearEnd,
        ];
    }

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function getProvisionalPeriods(int $taxYear): array
    {
        $firstStart = CarbonImmutable::create($taxYear, 3, 1);
        $firstEnd = CarbonImmutable::create($taxYear, 8, 31);
        $secondStart = CarbonImmutable::create($taxYear, 9, 1);
        $secondEnd = CarbonImmutable::create($taxYear + 1, 2, 1)->endOfMonth();

        return [
            ['start' => $firstStart, 'end' => $firstEnd],
            ['start' => $secondStart, 'end' => $secondEnd],
        ];
    }

    public function estimateAnnualIncome(Team $team, int $taxYear): Money
    {
        $start = Carbon::create($taxYear, 3, 1)->startOfDay();
        $end = Carbon::create($taxYear + 1, 2, 1)->endOfMonth()->endOfDay();

        $credits = (int) JournalEntry::query()
            ->where('type', EntryType::Credit)
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('type', AccountType::Income->value))
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()]))
            ->sum('amount_cents');

        $debits = (int) JournalEntry::query()
            ->where('type', EntryType::Debit)
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('type', AccountType::Income->value))
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()]))
            ->sum('amount_cents');

        return Money::ofMinor(max(0, $credits - $debits), 'ZAR');
    }
}
