<?php

namespace App\Domain\Accounting\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<Money, Money|int|string>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        if ($value === null) {
            return Money::zero($attributes['currency'] ?? 'ZAR');
        }

        $currency = (string) ($attributes['currency'] ?? 'ZAR');

        return Money::ofMinor((int) $value, $currency);
    }

    /**
     * @param  Money|int|string  $value
     * @return array<string, int|string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value instanceof Money) {
            return [
                $key => $value->getMinorAmount()->toInt(),
                'currency' => $value->getCurrency()->getCurrencyCode(),
            ];
        }

        if (is_int($value)) {
            return [
                $key => $value,
                'currency' => $attributes['currency'] ?? 'ZAR',
            ];
        }

        $currency = (string) ($attributes['currency'] ?? 'ZAR');
        $money = Money::of((string) $value, $currency);

        return [
            $key => $money->getMinorAmount()->toInt(),
            'currency' => $money->getCurrency()->getCurrencyCode(),
        ];
    }
}
