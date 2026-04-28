<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

final class FormatMoney
{
    /** Format integer minor units (e.g. cents) using ISO currency. */
    public static function minorUnits(int $minor, ?string $currencyCode = null): string
    {
        $code = Iso4217Currencies::normalize($currencyCode);
        $fmt = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $fmt->setAttribute(NumberFormatter::FRACTION_DIGITS, 2);

        return $fmt->formatCurrency($minor / 100, $code);
    }
}
