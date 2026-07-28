<?php

namespace App\Domain\Invoicing\Enums;

enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
