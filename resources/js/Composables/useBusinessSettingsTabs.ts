import type { AppTabItem } from '@/Components/AppTabs.vue';

export type BusinessSettingsTabId =
    | 'profile'
    | 'contact'
    | 'invoice'
    | 'estimate'
    | 'tax'
    | 'banking'
    | 'items'
    | 'payment_pages'
    | 'ai'
    | 'note_templates';

/** In-page Business settings tabs (excludes Note templates, which is its own page). */
export const BUSINESS_PAGE_TAB_IDS: Exclude<BusinessSettingsTabId, 'note_templates'>[] = [
    'profile',
    'contact',
    'invoice',
    'estimate',
    'tax',
    'banking',
    'items',
    'payment_pages',
    'ai',
];

const TAB_DEFS: Array<{ id: BusinessSettingsTabId; label: string }> = [
    { id: 'profile', label: 'Business profile' },
    { id: 'contact', label: 'Contact' },
    { id: 'invoice', label: 'Invoices' },
    { id: 'estimate', label: 'Estimates' },
    { id: 'note_templates', label: 'Note templates' },
    { id: 'tax', label: 'VAT' },
    { id: 'banking', label: 'Banking' },
    { id: 'items', label: 'Units' },
    { id: 'payment_pages', label: 'Online payments' },
    { id: 'ai', label: 'AI' },
];

/**
 * Business settings sub-tabs.
 * Note templates is always a link. Pass `linkAll` on the note-templates page so
 * the other tabs navigate back into Settings → Business.
 */
export function businessSettingsTabs(options: { linkAll?: boolean } = {}): AppTabItem[] {
    return TAB_DEFS.map((tab) => {
        if (tab.id === 'note_templates') {
            return {
                id: tab.id,
                label: tab.label,
                href: route('settings.note-templates.index'),
            };
        }

        if (options.linkAll) {
            return {
                id: tab.id,
                label: tab.label,
                href: route('settings.business', { tab: tab.id }),
            };
        }

        return { id: tab.id, label: tab.label };
    });
}
