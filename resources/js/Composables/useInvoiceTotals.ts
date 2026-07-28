/**
 * Mirror of App\Domain\Invoicing\Services\InvoiceTotalsCalculator for live form totals.
 */
export type DiscountType = 'percent' | 'fixed' | null | undefined;

export type TotalsLineInput = {
    quantity: number;
    unit_price: number | string;
    vat_rate?: number;
    discount_type?: DiscountType;
    discount_percent?: number | null;
    /** Major currency units for fixed line discount (form), converted to cents. */
    discount_amount?: number | string | null;
    /** Or cents directly. */
    discount_cents?: number | null;
};

export type TotalsResult = {
    lines: Array<{
        base_cents: number;
        line_discount_cents: number;
        line_exclusive_cents: number;
        taxed_base_cents: number;
        vat_amount_cents: number;
        total_cents: number;
    }>;
    subtotal_cents: number;
    vat_amount_cents: number;
    total_cents: number;
    discount_total_cents: number;
    document_discount_cents: number;
};

function discountAmount(
    baseCents: number,
    type: DiscountType,
    percent: number | null | undefined,
    fixedCents: number | null | undefined,
): number {
    if (baseCents <= 0 || type == null) {
        return 0;
    }
    if (type === 'percent') {
        const p = Math.max(0, Math.min(100, Number(percent ?? 0)));
        return Math.round((baseCents * p) / 100);
    }
    if (type === 'fixed') {
        const fixed = Math.max(0, Math.round(Number(fixedCents ?? 0)));
        return Math.min(fixed, baseCents);
    }
    return 0;
}

function lineFixedCents(line: TotalsLineInput): number {
    if (line.discount_cents != null) {
        return Math.round(Number(line.discount_cents));
    }
    if (line.discount_amount != null && line.discount_amount !== '') {
        return Math.round(Number(line.discount_amount) * 100);
    }
    return 0;
}

export function calculateInvoiceTotals(
    lines: TotalsLineInput[],
    documentDiscountType: DiscountType = null,
    documentDiscountPercent: number | null = null,
    documentDiscountCents: number | null = null,
): TotalsResult {
    const prepared: Array<{
        vat_rate: number;
        base_cents: number;
        line_discount_cents: number;
        line_exclusive_cents: number;
    }> = [];
    let sumExclusive = 0;
    let sumLineDiscount = 0;

    for (const line of lines) {
        const quantity = Number(line.quantity) || 0;
        const unitPriceCents = Math.round((Number(line.unit_price) || 0) * 100);
        const vatRate = Number(line.vat_rate) || 0;
        const base = Math.round(quantity * unitPriceCents);
        const lineDiscount = discountAmount(
            base,
            line.discount_type,
            line.discount_percent,
            lineFixedCents(line),
        );
        const lineExclusive = Math.max(0, base - lineDiscount);
        sumExclusive += lineExclusive;
        sumLineDiscount += lineDiscount;
        prepared.push({
            vat_rate: vatRate,
            base_cents: base,
            line_discount_cents: lineDiscount,
            line_exclusive_cents: lineExclusive,
        });
    }

    const documentDiscount = discountAmount(
        sumExclusive,
        documentDiscountType,
        documentDiscountPercent,
        documentDiscountCents,
    );
    const taxablePool = Math.max(0, sumExclusive - documentDiscount);

    const lastIndex = prepared.length - 1;
    let allocated = 0;
    let subtotalCents = 0;
    let vatAmountCents = 0;
    const resultLines: TotalsResult['lines'] = [];

    prepared.forEach((line, index) => {
        let taxedBase = 0;
        if (sumExclusive > 0) {
            if (index === lastIndex) {
                taxedBase = taxablePool - allocated;
            } else {
                taxedBase = Math.round((taxablePool * line.line_exclusive_cents) / sumExclusive);
                allocated += taxedBase;
            }
        }
        const lineVat = Math.round(taxedBase * line.vat_rate);
        const lineTotal = taxedBase + lineVat;
        subtotalCents += taxedBase;
        vatAmountCents += lineVat;
        resultLines.push({
            base_cents: line.base_cents,
            line_discount_cents: line.line_discount_cents,
            line_exclusive_cents: line.line_exclusive_cents,
            taxed_base_cents: taxedBase,
            vat_amount_cents: lineVat,
            total_cents: lineTotal,
        });
    });

    return {
        lines: resultLines,
        subtotal_cents: subtotalCents,
        vat_amount_cents: vatAmountCents,
        total_cents: subtotalCents + vatAmountCents,
        discount_total_cents: sumLineDiscount + documentDiscount,
        document_discount_cents: documentDiscount,
    };
}
