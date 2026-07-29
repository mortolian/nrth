export type InvoiceStatusBadgeVariant =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'accent'
    | 'neutral'
    | 'default';

/**
 * Colour map for invoice status badges (shared across Money In / dashboard).
 *
 * draft   — slate (not issued)
 * sent    — sky (awaiting client)
 * viewed  — indigo (client opened it)
 * partial — amber (part paid)
 * paid    — green
 * overdue — rose
 * void    — slate
 */
export function invoiceStatusBadgeVariant(
    status: string,
    options?: { isOverdue?: boolean },
): InvoiceStatusBadgeVariant {
    const normalized = String(status || '').toLowerCase();

    if (normalized === 'paid') return 'success';
    if (normalized === 'void') return 'neutral';
    if (normalized === 'overdue' || options?.isOverdue) return 'danger';
    if (normalized === 'partial') return 'warning';
    if (normalized === 'draft') return 'neutral';
    if (normalized === 'viewed') return 'accent';
    if (normalized === 'sent') return 'info';

    return 'default';
}

/** Prefer overdue label when past due and not paid/void. */
export function invoiceStatusLabel(status: string, options?: { isOverdue?: boolean }): string {
    const normalized = String(status || '').toLowerCase();
    if (options?.isOverdue && normalized !== 'paid' && normalized !== 'void') {
        return 'overdue';
    }
    return normalized.replaceAll('_', ' ');
}
