<?php

namespace App\Domain\Tax\Enums;

enum TaxPeriodType: string
{
    case VAT = 'vat';

    /** Leftover for existing tax_periods rows. No UI creates or lists these. */
    case Provisional = 'provisional';
}
