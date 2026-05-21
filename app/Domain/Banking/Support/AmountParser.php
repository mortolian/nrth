<?php

namespace App\Domain\Banking\Support;

final class AmountParser
{
    public function parse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.+)\)$/', $normalized, $matches)) {
            $negative = true;
            $normalized = $matches[1];
        }

        $normalized = str_replace(["\u{00A0}", ' '], '', $normalized);
        $normalized = preg_replace('/[^\d,.\-+]/', '', $normalized) ?? '';

        if ($normalized === '' || $normalized === '-' || $normalized === '+') {
            return null;
        }

        if (str_starts_with($normalized, '-')) {
            $negative = true;
            $normalized = ltrim($normalized, '-');
        } elseif (str_starts_with($normalized, '+')) {
            $normalized = ltrim($normalized, '+');
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $parts = explode(',', $normalized);
            $normalized = count($parts) === 2 && strlen($parts[1]) <= 2
                ? str_replace(',', '.', $normalized)
                : str_replace(',', '', $normalized);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        $amount = number_format((float) $normalized, 2, '.', '');

        if ($negative) {
            $amount = bcsub('0.00', $amount, 2);
        }

        return $amount;
    }
}
