<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Services\Support\ClampedMonthDate;
use Carbon\Carbon;

class RecurringDueDateResolver
{
    public function resolve(
        Carbon $issueDate,
        RecurringDueDateRule $rule,
        ?int $dueDays,
        ?int $dueOnDay,
        int $clientPaymentTermsDays,
    ): Carbon {
        $issue = $issueDate->copy()->startOfDay();

        return match ($rule) {
            RecurringDueDateRule::DaysAfterIssue => $issue->copy()->addDays(max(0, $dueDays ?? 0)),
            RecurringDueDateRule::DayOfMonth => $this->dayOfMonth($issue, $dueOnDay ?? 1),
            RecurringDueDateRule::DayOfNextMonth => ClampedMonthDate::forYearMonth(
                (int) $issue->year,
                (int) $issue->month + 1,
                $dueOnDay ?? 1,
                false,
            ),
            RecurringDueDateRule::LastDayOfMonth => $issue->copy()->endOfMonth()->startOfDay(),
            RecurringDueDateRule::LastDayOfNextMonth => ClampedMonthDate::forYearMonth(
                (int) $issue->year,
                (int) $issue->month + 1,
                null,
                true,
            ),
            RecurringDueDateRule::ClientTerms => $issue->copy()->addDays(max(0, $clientPaymentTermsDays)),
        };
    }

    private function dayOfMonth(Carbon $issue, int $day): Carbon
    {
        $candidate = ClampedMonthDate::forYearMonth((int) $issue->year, (int) $issue->month, $day, false);

        if ($candidate->lt($issue)) {
            return ClampedMonthDate::forYearMonth((int) $issue->year, (int) $issue->month + 1, $day, false);
        }

        return $candidate;
    }
}
