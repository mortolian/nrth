/**
 * Format a trip calendar date (YYYY-MM-DD) with weekday, e.g. "Mon, 2026-08-03".
 * Uses noon local time to avoid timezone day-shift from midnight UTC parsing.
 */
export function formatTripDate(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '—';
    }

    const date = new Date(`${value}T12:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const weekday = date.toLocaleDateString(undefined, { weekday: 'short' });

    return `${weekday}, ${value}`;
}
