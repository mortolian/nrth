<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Invoicing\Services\Support\ClampedMonthDate;
use Carbon\Carbon;

/**
 * Advances a recurring invoice's next_run_date from a known scheduled date
 * (never from now()) so repeated runs do not drift.
 */
class RecurringScheduleResolver
{
    public function nextRunDateAfter(RecurringInvoice $recurring, Carbon $from): Carbon
    {
        $base = $from->copy()->startOfDay();

        return match ($recurring->frequency) {
            RecurringFrequency::Weekly => $this->nextWeekday(
                $base->copy()->addDay(),
                (int) ($recurring->generate_on_weekday ?? $base->isoWeekday()),
            ),
            RecurringFrequency::Monthly => ClampedMonthDate::forYearMonth(
                (int) $base->year,
                (int) $base->month + 1,
                $recurring->generate_on_day,
                (bool) $recurring->generate_on_last_day,
            ),
            RecurringFrequency::Yearly => ClampedMonthDate::forYearMonth(
                (int) $base->year + 1,
                (int) ($recurring->generate_on_month ?? $base->month),
                $recurring->generate_on_day,
                (bool) $recurring->generate_on_last_day,
            ),
        };
    }

    private function nextWeekday(Carbon $from, int $isoWeekday): Carbon
    {
        $weekday = max(1, min(7, $isoWeekday));
        $cursor = $from->copy()->startOfDay();
        while ((int) $cursor->isoWeekday() !== $weekday) {
            $cursor->addDay();
        }

        return $cursor;
    }
}
