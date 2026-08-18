import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { AppTabItem } from '@/Components/AppTabs.vue';

function useCanTeam() {
    const page = usePage();
    const teamPermissions = computed(() => {
        const perms = page.props.team_permissions;
        return Array.isArray(perms) ? (perms as string[]) : [];
    });
    return (permission: string) => teamPermissions.value.includes(permission);
}

export function useMoneyInTabs() {
    const canTeam = useCanTeam();
    return computed((): AppTabItem[] =>
        [
            canTeam('invoices.view')
                ? { id: 'invoices', label: 'Invoices', href: route('invoicing.invoices.index') }
                : null,
            canTeam('estimates.view')
                ? { id: 'estimates', label: 'Estimates', href: route('invoicing.estimates.index') }
                : null,
            canTeam('clients.view')
                ? { id: 'clients', label: 'Clients', href: route('invoicing.clients.index') }
                : null,
            canTeam('items.view')
                ? { id: 'items', label: 'Items', href: route('invoicing.items.index') }
                : null,
            canTeam('invoices.view')
                ? { id: 'recurring', label: 'Recurring', href: route('invoicing.recurring.index') }
                : null,
        ].filter(Boolean) as AppTabItem[],
    );
}

export function useMoneyOutTabs() {
    const canTeam = useCanTeam();
    return computed((): AppTabItem[] =>
        [
            canTeam('expenses.view')
                ? { id: 'expenses', label: 'Expenses', href: route('expenses.index') }
                : null,
            canTeam('suppliers.view')
                ? { id: 'suppliers', label: 'Suppliers', href: route('suppliers.index') }
                : null,
        ].filter(Boolean) as AppTabItem[],
    );
}

export function useBankingTabs() {
    const canTeam = useCanTeam();
    return computed((): AppTabItem[] =>
        [
            { id: 'transactions', label: 'Transactions', href: route('banking.transactions.index') },
            { id: 'reconciliation', label: 'Reconciliation', href: route('banking.reconciliation.index') },
            canTeam('banking.manage')
                ? { id: 'import', label: 'Import statement', href: route('banking.import.create') }
                : null,
            { id: 'accounts', label: 'Accounts', href: route('banking.accounts.index') },
        ].filter(Boolean) as AppTabItem[],
    );
}

export function useTravelTabs() {
    const page = usePage();
    const canTeam = useCanTeam();
    const aiEnabled = computed(() => Boolean(page.props.ai_enabled));

    return computed((): AppTabItem[] =>
        [
            { id: 'trips', label: 'Log book', href: route('vehicles.trips.index') },
            { id: 'vehicles', label: 'Vehicles', href: route('vehicles.index') },
            aiEnabled.value && canTeam('vehicles.manage')
                ? { id: 'import', label: 'Smart AI import', href: route('vehicles.trips.import.create') }
                : null,
            canTeam('vehicles.manage')
                ? { id: 'import-history', label: 'Import history', href: route('vehicles.trips.imports.index') }
                : null,
        ].filter(Boolean) as AppTabItem[],
    );
}

export function useAccountingTabs() {
    return computed((): AppTabItem[] => [
        { id: 'transactions', label: 'Transactions', href: route('accounting.transactions.index') },
        { id: 'journal', label: 'Ledger (period)', href: route('accounting.journal.index') },
        { id: 'accounts', label: 'Accounts (setup)', href: route('accounting.accounts.index') },
    ]);
}

export function useTaxTabs() {
    const canTeam = useCanTeam();
    return computed((): AppTabItem[] =>
        [
            { id: 'vat', label: 'VAT Returns', href: route('tax.vat.index') },
            canTeam('tax.manage')
                ? { id: 'vat-rates', label: 'VAT rates', href: route('tax.vat-rates.index') }
                : null,
            { id: 'provisional', label: 'Tax Periods', href: route('tax.provisional.index') },
        ].filter(Boolean) as AppTabItem[],
    );
}

export function useReportsTabs() {
    return computed((): AppTabItem[] => [
        { id: 'profit-loss', label: 'Profit And Loss', href: route('reports.profit-loss') },
        { id: 'balance-sheet', label: 'Balance Sheet', href: route('reports.balance-sheet') },
        { id: 'cash-flow', label: 'Cash Flow', href: route('reports.cash-flow') },
        { id: 'trial-balance', label: 'Trial Balance', href: route('reports.trial-balance') },
    ]);
}
