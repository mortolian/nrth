<?php

namespace App\Domain\Invoicing\Services;

use Carbon\Carbon;

/**
 * Resolves {{token}} placeholders in recurring invoice text at generate time.
 * Unknown tokens are left unchanged.
 */
class RecurringPlaceholderResolver
{
    public static function replace(
        ?string $text,
        Carbon $issueDate,
        Carbon $dueDate,
        int $periodOffsetMonths = 0,
    ): ?string {
        if ($text === null || $text === '') {
            return $text;
        }

        $billingPeriod = $issueDate->copy()->addMonthsNoOverflow($periodOffsetMonths);

        $replacements = [
            '{{month}}' => $billingPeriod->format('F'),
            '{{month_short}}' => $billingPeriod->format('M'),
            '{{year}}' => $billingPeriod->format('Y'),
            '{{month_year}}' => $billingPeriod->format('F Y'),
            '{{issue_date}}' => $issueDate->toDateString(),
            '{{due_date}}' => $dueDate->toDateString(),
            '{{day}}' => $issueDate->format('d'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
