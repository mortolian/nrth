<?php

namespace App\Modules\Wealth\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class WealthFinancialYear
{
    /**
     * @return array{0: Carbon, 1: Carbon} [start, end] inclusive calendar dates
     */
    public static function windowContaining(CarbonInterface $date, int $startMonth): array
    {
        $startMonth = max(1, min(12, $startMonth));
        $year = (int) $date->year;
        $month = (int) $date->month;

        if ($month >= $startMonth) {
            $start = Carbon::create($year, $startMonth, 1)->startOfDay();
            $end = Carbon::create($year + 1, $startMonth, 1)->subDay()->startOfDay();
        } else {
            $start = Carbon::create($year - 1, $startMonth, 1)->startOfDay();
            $end = Carbon::create($year, $startMonth, 1)->subDay()->startOfDay();
        }

        return [$start, $end];
    }

    public static function labelForWindow(CarbonInterface $start, CarbonInterface $end): string
    {
        if ((int) $start->year === (int) $end->year) {
            return (string) $start->year;
        }

        return $start->format('Y').'/'.$end->format('y');
    }

    /**
     * Map team financial_year_end_month to FY start month (end Feb → start March).
     */
    public static function startMonthFromEndMonth(int $endMonth): int
    {
        $endMonth = max(1, min(12, $endMonth));

        return $endMonth === 12 ? 1 : $endMonth + 1;
    }
}
