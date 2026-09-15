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
        return $this->advance(
            $recurring->frequency,
            $from,
            $recurring->generate_on_weekday !== null ? (int) $recurring->generate_on_weekday : null,
            $recurring->generate_on_day !== null ? (int) $recurring->generate_on_day : null,
            (bool) $recurring->generate_on_last_day,
            $recurring->generate_on_month !== null ? (int) $recurring->generate_on_month : null,
        );
    }

    public function advance(
        RecurringFrequency $frequency,
        Carbon $from,
        ?int $generateOnWeekday,
        ?int $generateOnDay,
        bool $generateOnLastDay,
        ?int $generateOnMonth,
    ): Carbon {
        $base = $from->copy()->startOfDay();

        return match ($frequency) {
            RecurringFrequency::Weekly => $this->nextWeekday(
                $base->copy()->addDay(),
                (int) ($generateOnWeekday ?? $base->isoWeekday()),
            ),
            RecurringFrequency::Monthly => ClampedMonthDate::forYearMonth(
                (int) $base->year,
                (int) $base->month + 1,
                $generateOnDay,
                $generateOnLastDay,
            ),
            RecurringFrequency::Yearly => ClampedMonthDate::forYearMonth(
                (int) $base->year + 1,
                (int) ($generateOnMonth ?? $base->month),
                $generateOnDay,
                $generateOnLastDay,
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
