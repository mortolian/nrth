export type ExpenseTaxRateOption = {
    value: string;
    label: string;
    rate: number;
    claimable: boolean;
};

/** Used for prop defaults and UI fallback (cannot live inline in `defineProps` — hoisting). */
export const FALLBACK_EXPENSE_TAX_RATES: ExpenseTaxRateOption[] = [
    { value: 'vat15', label: 'VAT 15%', rate: 0.15, claimable: true },
    { value: 'vat0', label: 'VAT 0%', rate: 0.0, claimable: true },
    { value: 'exempt', label: 'Exempt', rate: 0.0, claimable: false },
    { value: 'no_vat', label: 'No VAT', rate: 0.0, claimable: false },
];
