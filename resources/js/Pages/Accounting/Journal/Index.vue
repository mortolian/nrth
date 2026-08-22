<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useAccountingTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const accountingTabs = useAccountingTabs();

type LedgerAccount = {
    id: number;
    code: string;
    name: string;
    normal_balance: 'debit' | 'credit';
    opening_balance_cents: number;
    period_debits_cents: number;
    period_credits_cents: number;
    closing_balance_cents: number;
    statement_url: string;
};

type AccountGroup = {
    type: string;
    accounts: LedgerAccount[];
};

const props = defineProps<{
    groups: AccountGroup[];
    period: { from: string; to: string };
}>();

const page = usePage<{ business_currency?: string }>();
const bookCurrency = computed(() =>
    typeof page.props.business_currency === 'string' && page.props.business_currency.trim() !== ''
        ? page.props.business_currency
        : 'ZAR',
);
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, bookCurrency.value);

const period = ref({ from: props.period.from, to: props.period.to });

const applyPeriod = () => {
    router.get(
        route('accounting.journal.index'),
        { from: period.value.from, to: period.value.to },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const presetThisYear = () => {
    const now = new Date();
    period.value.from = `${now.getFullYear()}-01-01`;
    period.value.to = `${now.getFullYear()}-12-31`;
    applyPeriod();
};

const presetThisMonth = () => {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    period.value.from = first.toISOString().slice(0, 10);
    period.value.to = last.toISOString().slice(0, 10);
    applyPeriod();
};

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const typeHeaderClass: Record<string, string> = {
    asset: 'border-blue-100 bg-blue-50 text-blue-900',
    liability: 'border-rose-100 bg-rose-50 text-rose-900',
    equity: 'border-purple-100 bg-purple-50 text-purple-900',
    income: 'border-brand-100 bg-brand-50 text-brand-900',
    expense: 'border-amber-100 bg-amber-50 text-amber-900',
};

const groupTotals = computed(() =>
    props.groups.map((g) => ({
        type: g.type,
        totalClosing: g.accounts.reduce((sum, a) => sum + a.closing_balance_cents, 0),
    })),
);

const navigateTo = (url: string) =>
    router.get(url, { from: period.value.from, to: period.value.to, source: 'ledger' });
</script>

<template>
    <FeatureShell
        title="Accounting"
        section="journal"
        :tabs="accountingTabs"
        document-title="General Ledger"
        subtitle="Period report — opening, movement, and closing by account"
    >
        <div>
            <h2 class="text-lg font-semibold text-slate-900">General Ledger</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                Read-only activity for the date range below. To add, edit, or archive accounts, use
                <Link
                    :href="route('accounting.accounts.index')"
                    class="font-medium text-brand-700 underline-offset-2 hover:underline"
                >
                    Chart of Accounts
                </Link>.
            </p>
        </div>

        <AppCard class="mt-5">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Reporting period</p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="period.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="period.to" type="date" />
                </div>
                <AppButton variant="secondary" @click="applyPeriod">Apply</AppButton>
                <AppButton variant="ghost" @click="presetThisMonth">This month</AppButton>
                <AppButton variant="ghost" @click="presetThisYear">This year</AppButton>
            </div>
        </AppCard>

        <div v-if="groups.length === 0" class="mt-5">
            <AppCard>
                <EmptyState
                    title="No accounts found"
                    description="Set up your chart of accounts first — this page only reports period balances for existing accounts."
                />
            </AppCard>
        </div>

        <div v-for="group in groups" :key="group.type" class="mt-5">
            <AppCard class="overflow-hidden p-0">
                <div
                    class="flex items-center justify-between gap-3 border-b px-5 py-3"
                    :class="typeHeaderClass[group.type] ?? 'border-slate-200 bg-slate-50 text-slate-900'"
                >
                    <h3 class="text-sm font-semibold">
                        {{ typeLabels[group.type] ?? group.type }}
                    </h3>
                    <span class="text-xs opacity-70">{{ group.accounts.length }} accounts</span>
                </div>

                <AppTable
                    embedded
                    dense
                    table-class="text-sm"
                    :show-pagination="false"
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Account' },
                        { key: 'opening', label: 'Opening', align: 'right' },
                        { key: 'debits', label: 'Debits (period)', align: 'right' },
                        { key: 'credits', label: 'Credits (period)', align: 'right' },
                        { key: 'closing', label: 'Closing', align: 'right' },
                        { key: 'actions', label: '', align: 'right' },
                    ]"
                >
                    <tr v-for="account in group.accounts" :key="account.id" class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-slate-600">{{ account.code }}</td>
                        <td class="px-3 py-2 font-medium text-slate-800">{{ account.name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-600">
                            {{ formatCents(Math.abs(account.opening_balance_cents)) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">
                            <span v-if="account.period_debits_cents > 0">{{ formatCents(account.period_debits_cents) }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">
                            <span v-if="account.period_credits_cents > 0">{{ formatCents(account.period_credits_cents) }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-semibold">
                            <span :class="account.closing_balance_cents < 0 ? 'text-rose-600' : 'text-brand-700'">
                                {{ formatCents(Math.abs(account.closing_balance_cents)) }}
                                <span v-if="account.closing_balance_cents < 0" class="ml-1 text-xs font-normal text-rose-400">abnormal</span>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <AppButton size="sm" variant="ghost" @click="navigateTo(account.statement_url)">
                                View entries
                            </AppButton>
                        </td>
                    </tr>

                    <tr class="border-t border-slate-200 bg-slate-50">
                        <td colspan="5" class="px-3 py-2 text-xs font-semibold uppercase text-slate-500">
                            {{ typeLabels[group.type] ?? group.type }} total
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-sm font-bold text-slate-800">
                            {{ formatCents(Math.abs(group.accounts.reduce((s, a) => s + a.closing_balance_cents, 0))) }}
                        </td>
                        <td class="px-3 py-2" />
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </FeatureShell>
</template>
