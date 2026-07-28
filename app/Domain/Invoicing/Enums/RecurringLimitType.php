<?php

namespace App\Domain\Invoicing\Enums;

enum RecurringLimitType: string
{
    case None = 'none';
    case Count = 'count';
    case EndDate = 'end_date';
}
