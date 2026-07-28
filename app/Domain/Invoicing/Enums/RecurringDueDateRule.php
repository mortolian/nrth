<?php

namespace App\Domain\Invoicing\Enums;

enum RecurringDueDateRule: string
{
    case ClientTerms = 'client_terms';
    case DaysAfterIssue = 'days_after_issue';
    case DayOfMonth = 'day_of_month';
    case DayOfNextMonth = 'day_of_next_month';
    case LastDayOfMonth = 'last_day_of_month';
    case LastDayOfNextMonth = 'last_day_of_next_month';
}
