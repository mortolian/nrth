import { useFormatCurrency } from '@/Composables/useFormatCurrency';

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
            formatter: (v: number) => useFormatCurrency(v / 100, currency),
        },
        splitLine: { lineStyle: { color: '#e2e8f0' } },
    };
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
