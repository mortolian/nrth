export function useFormatCurrency(amount: number, currency = 'ZAR'): string {
    return new Intl.NumberFormat('en-ZA', {
        style: 'currency',
        currency,
        currencyDisplay: 'symbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}
