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
    | 'note_templates'
    | 'team_members';

/** In-page Business settings tabs (excludes Note templates and Team members — separate pages). */
export const BUSINESS_PAGE_TAB_IDS: Exclude<BusinessSettingsTabId, 'note_templates' | 'team_members'>[] = [
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

const TAB_DEFS: Array<{ id: BusinessSettingsTabId; label: string; permission?: 'settings.team' }> = [
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
    { id: 'team_members', label: 'Team members', permission: 'settings.team' },
];

/**
 * Business settings sub-tabs.
 * Note templates and Team members are always links. Pass `linkAll` on those pages so
 * the other tabs navigate back into Settings → Business.
 */
export function businessSettingsTabs(
    options: { linkAll?: boolean; teamPermissions?: string[] } = {},
): AppTabItem[] {
    const perms = options.teamPermissions ?? [];

    return TAB_DEFS.filter((tab) => {
        if (tab.permission === undefined) {
            return true;
        }

        return perms.includes(tab.permission);
    }).map((tab) => {
        if (tab.id === 'note_templates') {
            return {
                id: tab.id,
                label: tab.label,
                href: route('settings.note-templates.index'),
            };
        }

        if (tab.id === 'team_members') {
            return {
                id: tab.id,
                label: tab.label,
                href: route('settings.team'),
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
