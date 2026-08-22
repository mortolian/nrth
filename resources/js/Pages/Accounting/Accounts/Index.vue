<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useAccountingTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const accountingTabs = useAccountingTabs();

type AccountRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: string;
    normal_balance: 'debit' | 'credit';
    is_system: boolean;
    is_active: boolean;
    parent: { code: string; name: string } | null;
    balance_cents: number;
};

type AccountGroup = {
    type: string;
    accounts: AccountRow[];
};

const props = defineProps<{
    groups: AccountGroup[];
    account_count: number;
    can_manage: boolean;
}>();

const page = usePage<{ business_currency?: string }>();
const pageErrors = computed(() => page.props.errors as Record<string, string> | undefined);

const isTrulyEmpty = computed(() => props.account_count === 0);

const bookCurrency = computed(() =>
    typeof page.props.business_currency === 'string' && page.props.business_currency.trim() !== ''
        ? page.props.business_currency
        : 'ZAR',
);
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, bookCurrency.value);

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const showInactive = ref(false);
const search = ref('');

const filteredGroups = computed(() =>
    props.groups
        .map((g) => ({
            ...g,
            accounts: g.accounts.filter((a) => {
                if (!showInactive.value && !a.is_active) return false;
                if (search.value.trim()) {
                    const q = search.value.toLowerCase();
                    return (
                        a.code.toLowerCase().includes(q) ||
                        a.name.toLowerCase().includes(q) ||
                        (a.description ?? '').toLowerCase().includes(q)
                    );
                }
                return true;
            }),
        }))
        .filter((g) => g.accounts.length > 0),
);

const rowActionItems = (account: AccountRow) => {
    const actions: Array<{ id: string; label: string }> = [];
    if (account.is_active) {
        actions.push({ id: 'statement', label: 'View statement' });
    }
    if (props.can_manage) {
        actions.push({ id: 'edit', label: 'Edit' });
        if (!account.is_system && account.is_active) {
            actions.push({ id: 'archive', label: 'Archive' });
        }
        if (!account.is_system) {
            actions.push({ id: 'delete', label: 'Delete' });
        }
    }
    return actions;
};

const onRowAction = (account: AccountRow, actionId: string) => {
    if (actionId === 'statement') {
        router.get(route('accounting.accounts.statement', account.id), { source: 'accounts' });
        return;
    }
    if (actionId === 'edit') {
        router.get(route('accounting.accounts.edit', account.id));
        return;
    }
    if (actionId === 'archive') {
        if (!confirm('Archive this account? It will be hidden from new transactions unless you show inactive accounts.')) {
            return;
        }
        router.post(route('accounting.accounts.deactivate', account.id));
        return;
    }
    if (actionId === 'delete') {
        if (!confirm('Permanently delete this account? Only allowed when it has no ledger activity or sub-accounts.')) {
            return;
        }
        router.delete(route('accounting.accounts.destroy', account.id));
    }
};

const seedDefaultChart = () => {
    if (!confirm('Install the standard South African chart of accounts for this company? Existing codes will be updated if they match.')) {
        return;
    }
    router.post(route('accounting.accounts.seed-default'));
};

const openAccount = (account: AccountRow) => {
    if (account.is_active) {
        router.get(route('accounting.accounts.statement', account.id), { source: 'accounts' });
        return;
    }
    if (props.can_manage) {
        router.get(route('accounting.accounts.edit', account.id));
    }
};

const typeBadgeClass: Record<string, string> = {
    asset: 'bg-blue-100 text-blue-700',
    liability: 'bg-rose-100 text-rose-700',
    equity: 'bg-purple-100 text-purple-700',
    income: 'bg-brand-100 text-brand-700',
    expense: 'bg-amber-100 text-amber-700',
};

/** Shared across type-group tables so Code / Parent / Balance line up vertically. */
const accountColumns = [
    { key: 'code', label: 'Code', widthClass: 'w-[10%] whitespace-nowrap' },
    { key: 'name', label: 'Account', widthClass: 'w-[52%]' },
    { key: 'parent', label: 'Parent', widthClass: 'w-[12%] whitespace-nowrap' },
    { key: 'balance', label: 'Current balance', widthClass: 'w-[18%] whitespace-nowrap text-right tabular-nums' },
    { key: 'actions', label: '', widthClass: 'w-[8%] whitespace-nowrap text-right' },
];
</script>

<template>
    <FeatureShell
        title="Accounting"
        section="accounts"
        :tabs="accountingTabs"
        document-title="Chart of Accounts"
        subtitle="Account setup — codes, hierarchy, and what you can post to"
    >
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Chart of Accounts</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                Manage the accounts used for posting. Balances here are lifetime totals — for opening,
                period movement, and closing, open
                <Link
                    :href="route('accounting.journal.index')"
                    class="font-medium text-brand-700 underline-offset-2 hover:underline"
                >
                    General Ledger
                </Link>.
            </p>
        </div>

        <div
            v-if="pageErrors?.account"
            class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
        >
            {{ pageErrors.account }}
        </div>

        <div v-if="can_manage" class="mt-4 flex flex-wrap gap-3">
            <AppButton
                v-if="isTrulyEmpty"
                variant="primary"
                @click="seedDefaultChart"
            >
                Install default chart
            </AppButton>
            <AppButton variant="primary" @click="router.visit(route('accounting.accounts.create'))">
                Add account
            </AppButton>
        </div>

        <AppCard class="mt-5">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Find accounts</p>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-48">
                    <AppInput v-model="search" placeholder="Search by code or name…" />
                </div>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 select-none">
                    <input v-model="showInactive" type="checkbox" class="rounded border-slate-300" />
                    Show inactive accounts
                </label>
            </div>
        </AppCard>

        <div v-if="isTrulyEmpty" class="mt-5">
            <AppCard>
                <EmptyState
                    title="No accounts yet"
                    description="Install the standard South African chart (bank, debtors, VAT, revenue, expenses, etc.) or add your own accounts. Only the team owner can change the chart."
                />
            </AppCard>
        </div>
        <div v-else-if="filteredGroups.length === 0" class="mt-5">
            <AppCard>
                <EmptyState
                    title="No accounts match"
                    description="Try adjusting your search or enabling inactive accounts."
                />
            </AppCard>
        </div>

        <div v-for="group in filteredGroups" :key="group.type" class="mt-5">
            <div class="mb-2 flex items-center gap-2">
                <span
                    class="rounded-md px-2.5 py-1 text-xs font-semibold uppercase tracking-wide"
                    :class="typeBadgeClass[group.type] ?? 'bg-slate-100 text-slate-600'"
                >
                    {{ typeLabels[group.type] ?? group.type }}
                </span>
                <span class="text-xs text-slate-400">{{ group.accounts.length }} accounts</span>
            </div>

            <AppCard>
                <AppTable
                    table-class="text-sm table-fixed"
                    :show-pagination="false"
                    :columns="accountColumns"
                >
                    <tr
                        v-for="account in group.accounts"
                        :key="account.id"
                        class="cursor-pointer hover:bg-slate-50"
                        :class="{ 'opacity-50': !account.is_active }"
                        role="link"
                        tabindex="0"
                        :aria-label="`${account.code} ${account.name}`"
                        @click="openAccount(account)"
                        @keydown.enter.prevent="openAccount(account)"
                    >
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-slate-600">{{ account.code }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium text-slate-800">{{ account.name }}</span>
                                <AppBadge v-if="account.is_system" variant="neutral" class="text-xs">System</AppBadge>
                                <AppBadge v-if="!account.is_active" variant="neutral" class="text-xs">Inactive</AppBadge>
                            </div>
                            <div v-if="account.description" class="mt-0.5 text-xs text-slate-400">{{ account.description }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-500">
                            <span v-if="account.parent">{{ account.parent.code }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            <span
                                :class="account.balance_cents < 0 ? 'text-rose-600' : 'text-slate-700'"
                            >
                                {{ formatCents(Math.abs(account.balance_cents)) }}
                                <span v-if="account.balance_cents < 0" class="ml-1 text-xs text-rose-400">Cr</span>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right" @click.stop>
                            <div
                                v-if="rowActionItems(account).length"
                                class="inline-flex justify-end"
                            >
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(account)"
                                    :aria-label="`Actions for ${account.code} ${account.name}`"
                                    @select="(id) => onRowAction(account, id)"
                                />
                            </div>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </FeatureShell>
</template>
