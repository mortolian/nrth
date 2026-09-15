<?php

namespace App\Domain\Expenses\Enums;

enum RecurringExpenseStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
}
