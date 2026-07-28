<?php

namespace App\Domain\Invoicing\Enums;

enum RecurringInvoiceStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
}
