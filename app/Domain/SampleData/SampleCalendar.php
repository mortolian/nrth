<?php

namespace App\Domain\SampleData;

use App\Support\FinancialYear;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The financial year the sample books belong to.
 *
 * Posted activity stops on {@see $asOf}. Months after that stay as drafts and schedules.
 */
final class SampleCalendar
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $asOf,
        public readonly bool $previousYear,
    ) {}

    public static function make(bool $previousYear, ?CarbonInterface $today = null): self
    {
        $today = Carbon::parse($today ?? Carbon::today())->startOfDay();
        $startMonth = FinancialYear::startMonthFromEndMonth(SampleData::FINANCIAL_YEAR_END_MONTH);
        [$start, $end] = FinancialYear::windowContaining($today, $startMonth);

        if ($previousYear) {
            $start = $start->copy()->subYear()->startOfDay();
            $end = $end->copy()->subYear()->startOfDay();

            return new self($start, $end, $end->copy(), true);
        }

        $asOf = $today->greaterThan($end) ? $end->copy() : $today->copy();
        if ($asOf->lessThan($start)) {
            $asOf = $start->copy();
        }

        return new self($start, $end, $asOf, false);
    }

    public function label(): string
    {
        return FinancialYear::labelForWindow($this->start, $this->end);
    }

    /**
     * First day of each month from the year start through the last posted day.
     *
     * @return list<Carbon>
     */
    public function postedMonths(): array
    {
        return $this->monthsUntil($this->asOf);
    }

    /**
     * First day of each month after the last posted day, through the year end.
     *
     * @return list<Carbon>
     */
    public function futureMonths(): array
    {
        $cursor = $this->asOf->copy()->startOfMonth()->addMonth();
        $months = [];

        while ($cursor->lessThanOrEqualTo($this->end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    public function contains(CarbonInterface $date): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        return $day->greaterThanOrEqualTo($this->start) && $day->lessThanOrEqualTo($this->asOf);
    }

    /**
     * @return list<Carbon>
     */
    private function monthsUntil(CarbonInterface $until): array
    {
        $cursor = $this->start->copy()->startOfMonth();
        $months = [];

        while ($cursor->lessThanOrEqualTo($until) && $cursor->lessThanOrEqualTo($this->end)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }
}
