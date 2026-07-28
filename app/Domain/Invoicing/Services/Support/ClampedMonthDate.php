<?php

namespace App\Domain\Invoicing\Services\Support;

use Carbon\Carbon;

/**
 * Resolves a calendar date within a given year/month, clamping the requested
 * day to the number of days actually in that month (e.g. day 31 in February
 * clamps to the 28th/29th), or the last day of the month when requested.
 */
final class ClampedMonthDate
{
    public static function forYearMonth(int $year, int $month, ?int $day, bool $lastDay): Carbon
    {
        $normalizedYear = $year;
        $normalizedMonth = $month;

        while ($normalizedMonth > 12) {
            $normalizedMonth -= 12;
            $normalizedYear++;
        }
        while ($normalizedMonth < 1) {
            $normalizedMonth += 12;
            $normalizedYear--;
        }

        $date = Carbon::create($normalizedYear, $normalizedMonth, 1)->startOfDay();

        if ($lastDay) {
            return $date->copy()->endOfMonth()->startOfDay();
        }

        $daysInMonth = $date->daysInMonth;
        $clampedDay = max(1, min($day ?? 1, $daysInMonth));

        return $date->copy()->day($clampedDay);
    }
}
