<?php

namespace App\Domain\Tax\Enums;

enum TaxPeriodType: string
{
    case VAT = 'vat';
    case Provisional = 'provisional';
}
