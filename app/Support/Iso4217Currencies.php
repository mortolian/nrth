<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Money\CurrencyType;
use Brick\Money\ISOCurrencyProvider;

final class Iso4217Currencies
{
    /** @var list<string>|null */
    private static ?array $codes = null;

    /** @var list<array{value: string, label: string}>|null */
    private static ?array $selectOptions = null;

    /**
     * Active ISO 4217 alphabetic codes (current currencies only).
     *
     * @return list<string>
     */
    public static function allowedCodes(): array
    {
        if (self::$codes !== null) {
            return self::$codes;
        }

        $codes = [];
        foreach (ISOCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            if ($currency->getCurrencyType() === CurrencyType::IsoCurrent) {
                $codes[] = $currency->getCurrencyCode();
            }
        }

        sort($codes);

        return self::$codes = $codes;
    }

    /**
     * Options for select controls: code and English name (e.g. "USD — US Dollar").
     *
     * @return list<array{value: string, label: string}>
     */
    public static function selectOptions(): array
    {
        if (self::$selectOptions !== null) {
            return self::$selectOptions;
        }

        $options = [];
        foreach (ISOCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            if ($currency->getCurrencyType() !== CurrencyType::IsoCurrent) {
                continue;
            }
            $code = $currency->getCurrencyCode();
            $options[] = [
                'value' => $code,
                'label' => $code.' — '.$currency->getName(),
            ];
        }

        usort($options, fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        return self::$selectOptions = $options;
    }
}
