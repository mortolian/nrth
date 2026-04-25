<?php

namespace App\Domain\Tax\Enums;

enum TaxPeriodStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Closed = 'closed';
}
