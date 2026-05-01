<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Money\Currency;

final class BudgetFx
{
    /**
     * Convert a minor-unit amount in line currency to budget currency minor units.
     * When currencies differ, $fxBudgetPerLineMajor is budget major units per one line major unit (e.g. 18.5 ZAR per 1 USD).
     */
    public static function monthlyLineMinorToBudgetMinor(
        int $lineMinor,
        string $lineCurrency,
        string $budgetCurrency,
        ?string $fxBudgetPerLineMajor,
    ): int {
        $lineCurrency = Iso4217Currencies::normalize($lineCurrency);
        $budgetCurrency = Iso4217Currencies::normalize($budgetCurrency);

        if (strcasecmp($lineCurrency, $budgetCurrency) === 0) {
            return max(0, $lineMinor);
        }

        if ($fxBudgetPerLineMajor === null || $fxBudgetPerLineMajor === '' || (float) $fxBudgetPerLineMajor <= 0) {
            return 0;
        }

        $linePlaces = Currency::of($lineCurrency)->getDefaultFractionDigits();
        $budgetPlaces = Currency::of($budgetCurrency)->getDefaultFractionDigits();

        $lineMajor = bcdiv((string) $lineMinor, bcpow('10', (string) $linePlaces, 0), 12);
        $budgetMajor = bcmul($lineMajor, $fxBudgetPerLineMajor, 12);
        $budgetMinor = bcmul($budgetMajor, bcpow('10', (string) $budgetPlaces, 0), 0);

        return max(0, (int) $budgetMinor);
    }
}
