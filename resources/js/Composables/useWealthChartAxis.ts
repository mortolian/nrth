type AxisExtent = { min: number; max: number };

export type WealthChartRange = '1M' | '1Y' | '2Y' | '5Y' | '10Y';

export const WEALTH_CHART_RANGES: Array<{ value: WealthChartRange; label: string }> = [
    { value: '1M', label: '1M' },
    { value: '1Y', label: '1Y' },
    { value: '2Y', label: '2Y' },
    { value: '5Y', label: '5Y' },
    { value: '10Y', label: '10Y' },
];

const RANGE_MONTHS: Record<WealthChartRange, number> = {
    '1M': 1,
    '1Y': 12,
    '2Y': 24,
    '5Y': 60,
    '10Y': 120,
};

/** Keep points on/after now − range. If none match, return the full series. */
export function filterByChartRange<T extends { date: string }>(
    points: T[],
    range: WealthChartRange,
    asOf: Date = new Date(),
): T[] {
    if (points.length === 0) {
        return points;
    }

    const cutoff = new Date(asOf);
    cutoff.setHours(0, 0, 0, 0);
    cutoff.setMonth(cutoff.getMonth() - RANGE_MONTHS[range]);

    const filtered = points.filter((point) => {
        const date = new Date(`${point.date}T00:00:00`);
        return !Number.isNaN(date.getTime()) && date >= cutoff;
    });

    return filtered.length > 0 ? filtered : points;
}

/**
 * Wealth balances are large; small absolute moves look flat if the axis includes zero.
 * Scale to the series range with padding so change is readable.
 */
export function wealthValueAxis(currency: string) {
    return {
        type: 'value' as const,
        scale: true,
        min: (extent: AxisExtent) => paddedMin(extent),
        max: (extent: AxisExtent) => paddedMax(extent),
        axisLabel: {
            color: '#64748b',
            fontSize: 11,
            hideOverlap: true,
            formatter: (v: number) => formatAxisMoney(v / 100, currency),
        },
        splitLine: { lineStyle: { color: '#e2e8f0' } },
    };
}

/** Shared plot margins; containLabel keeps million-scale tick labels visible. */
export function wealthChartGrid(overrides: Record<string, number | boolean> = {}) {
    return {
        left: 8,
        right: 16,
        top: 24,
        bottom: 32,
        containLabel: true,
        ...overrides,
    };
}

/** Compact axis ticks (R1.2m / R850k). Tooltips keep full currency formatting. */
export function formatAxisMoney(amount: number, currency: string): string {
    const abs = Math.abs(amount);
    const sign = amount < 0 ? '-' : '';
    const symbol = currencySymbol(currency);

    if (abs >= 1_000_000) {
        const decimals = abs >= 10_000_000 ? 1 : 2;
        return `${sign}${symbol}${trimFixed(abs / 1_000_000, decimals)}m`;
    }

    if (abs >= 10_000) {
        const decimals = abs >= 100_000 ? 0 : 1;
        return `${sign}${symbol}${trimFixed(abs / 1_000, decimals)}k`;
    }

    return `${sign}${symbol}${Math.round(abs).toLocaleString('en-ZA')}`;
}

function currencySymbol(currency: string): string {
    try {
        const part = new Intl.NumberFormat('en-ZA', {
            style: 'currency',
            currency,
            currencyDisplay: 'symbol',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        })
            .formatToParts(0)
            .find((entry) => entry.type === 'currency');

        return part?.value ?? `${currency} `;
    } catch {
        return `${currency} `;
    }
}

function trimFixed(value: number, decimals: number): string {
    return value.toFixed(decimals).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
}

/** Indexed series (first point = 100). */
export function wealthIndexedAxis() {
    return {
        type: 'value' as const,
        scale: true,
        min: (extent: AxisExtent) => paddedMin(extent),
        max: (extent: AxisExtent) => paddedMax(extent),
        axisLabel: {
            color: '#64748b',
            fontSize: 11,
            formatter: (v: number) => `${v.toFixed(v % 1 === 0 ? 0 : 1)}`,
        },
        splitLine: { lineStyle: { color: '#e2e8f0' } },
    };
}

export function toIndexedSeries(valuesCents: number[]): number[] {
    const base = valuesCents[0];
    if (base == null || base === 0) {
        return valuesCents.map(() => 100);
    }

    return valuesCents.map((v) => Math.round((v / base) * 1000) / 10);
}

function paddedMin(extent: AxisExtent): number {
    const span = extent.max - extent.min;
    if (span === 0) {
        return extent.min === 0 ? -1 : extent.min - Math.abs(extent.min) * 0.01;
    }

    return extent.min - span * 0.12;
}

function paddedMax(extent: AxisExtent): number {
    const span = extent.max - extent.min;
    if (span === 0) {
        return extent.max === 0 ? 1 : extent.max + Math.abs(extent.max) * 0.01;
    }

    return extent.max + span * 0.12;
}
