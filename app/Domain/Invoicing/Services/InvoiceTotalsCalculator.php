<?php

namespace App\Domain\Invoicing\Services;

/**
 * Single source of truth for invoice/estimate line + document discount math.
 *
 * VAT-exclusive model: discounts reduce the taxable base before VAT is calculated.
 * Both a per-line discount and a document-level discount can apply at once; the
 * document discount is allocated back across lines (weighted by each line's
 * post-line-discount exclusive amount) so VAT keeps being computed per line.
 */
class InvoiceTotalsCalculator
{
    /**
     * @param  array<int, array{quantity: float|int|string, unit_price_cents: int|string, vat_rate?: float|int|string|null, discount_type?: string|null, discount_percent?: float|int|string|null, discount_cents?: int|string|null}>  $lines
     * @return array{
     *     lines: list<array{base_cents: int, line_discount_cents: int, line_exclusive_cents: int, taxed_base_cents: int, vat_amount_cents: int, total_cents: int}>,
     *     subtotal_cents: int,
     *     vat_amount_cents: int,
     *     total_cents: int,
     *     discount_total_cents: int,
     * }
     */
    public function calculate(
        array $lines,
        ?string $documentDiscountType = null,
        float|int|string|null $documentDiscountPercent = null,
        int|string|null $documentDiscountCents = null,
    ): array {
        $prepared = [];
        $sumExclusive = 0;
        $sumLineDiscount = 0;

        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $unitPriceCents = (int) ($line['unit_price_cents'] ?? 0);
            $vatRate = (float) ($line['vat_rate'] ?? 0);

            $base = (int) round($quantity * $unitPriceCents);
            $lineDiscount = $this->discountAmount(
                $base,
                $line['discount_type'] ?? null,
                $line['discount_percent'] ?? null,
                $line['discount_cents'] ?? null,
            );
            $lineExclusive = max(0, $base - $lineDiscount);

            $sumExclusive += $lineExclusive;
            $sumLineDiscount += $lineDiscount;

            $prepared[] = [
                'vat_rate' => $vatRate,
                'base_cents' => $base,
                'line_discount_cents' => $lineDiscount,
                'line_exclusive_cents' => $lineExclusive,
            ];
        }

        $documentDiscount = $this->discountAmount(
            $sumExclusive,
            $documentDiscountType,
            $documentDiscountPercent,
            $documentDiscountCents,
        );
        $taxablePool = max(0, $sumExclusive - $documentDiscount);

        $lastIndex = count($prepared) - 1;
        $allocated = 0;
        $subtotalCents = 0;
        $vatAmountCents = 0;
        $resultLines = [];

        foreach ($prepared as $index => $line) {
            if ($sumExclusive <= 0) {
                $taxedBase = 0;
            } elseif ($index === $lastIndex) {
                $taxedBase = $taxablePool - $allocated;
            } else {
                $taxedBase = (int) round(($taxablePool * $line['line_exclusive_cents']) / $sumExclusive);
                $allocated += $taxedBase;
            }

            $lineVat = (int) round($taxedBase * $line['vat_rate']);
            $lineTotal = $taxedBase + $lineVat;

            $subtotalCents += $taxedBase;
            $vatAmountCents += $lineVat;

            $resultLines[] = [
                'base_cents' => $line['base_cents'],
                'line_discount_cents' => $line['line_discount_cents'],
                'line_exclusive_cents' => $line['line_exclusive_cents'],
                'taxed_base_cents' => $taxedBase,
                'vat_amount_cents' => $lineVat,
                'total_cents' => $lineTotal,
            ];
        }

        return [
            'lines' => $resultLines,
            'subtotal_cents' => $subtotalCents,
            'vat_amount_cents' => $vatAmountCents,
            'total_cents' => $subtotalCents + $vatAmountCents,
            'discount_total_cents' => $sumLineDiscount + $documentDiscount,
        ];
    }

    private function discountAmount(
        int $baseCents,
        ?string $type,
        float|int|string|null $percent,
        int|string|null $fixedCents,
    ): int {
        if ($baseCents <= 0 || $type === null) {
            return 0;
        }

        if ($type === 'percent') {
            $p = max(0.0, min(100.0, (float) ($percent ?? 0)));

            return (int) round($baseCents * $p / 100);
        }

        if ($type === 'fixed') {
            $fixed = max(0, (int) ($fixedCents ?? 0));

            return min($fixed, $baseCents);
        }

        return 0;
    }
}
