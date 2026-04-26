<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

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

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

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

const typeBadgeClass: Record<string, string> = {
    asset: 'bg-blue-100 text-blue-700',
    liability: 'bg-rose-100 text-rose-700',
    equity: 'bg-purple-100 text-purple-700',
    income: 'bg-emerald-100 text-emerald-700',
    expense: 'bg-amber-100 text-amber-700',
};

const groupTotals = computed(() =>
    props.groups.map((g) => ({
        type: g.type,
        totalClosing: g.accounts.reduce((sum, a) => sum + a.closing_balance_cents, 0),
    })),
);

const navigateTo = (url: string) => router.get(url, { from: period.value.from, to: period.value.to });
</script>

<template>
    <AppLayout
        title="General Ledger"
        :breadcrumbs="[{ label: 'Accounting' }, { label: 'General Ledger' }]"
    >
        <PageHeader
            title="General Ledger"
            subtitle="Account-by-account activity and running balances for the selected period"
        />

        <AppCard class="mt-5">
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
                    description="Set up your chart of accounts to see the general ledger."
                />
            </AppCard>
        </div>

        <div v-for="group in groups" :key="group.type" class="mt-5">
            <div class="mb-2 flex items-center gap-2">
                <span
                    class="rounded-md px-2.5 py-1 text-xs font-semibold uppercase tracking-wide"
                    :class="typeBadgeClass[group.type] ?? 'bg-slate-100 text-slate-600'"
                >
                    {{ typeLabels[group.type] ?? group.type }}
                </span>
            </div>

            <AppCard>
                <AppTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Account' },
                        { key: 'opening', label: 'Opening balance' },
                        { key: 'debits', label: 'Period debits' },
                        { key: 'credits', label: 'Period credits' },
                        { key: 'closing', label: 'Closing balance' },
                        { key: 'actions', label: '' },
                    ]"
                >
                    <tr v-for="account in group.accounts" :key="account.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-sm text-slate-600">{{ account.code }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ account.name }}</td>
                        <td class="px-4 py-3 tabular-nums text-sm text-slate-600">
                            {{ formatCents(Math.abs(account.opening_balance_cents)) }}
                        </td>
                        <td class="px-4 py-3 tabular-nums text-sm text-slate-700">
                            <span v-if="account.period_debits_cents > 0">{{ formatCents(account.period_debits_cents) }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-sm text-slate-700">
                            <span v-if="account.period_credits_cents > 0">{{ formatCents(account.period_credits_cents) }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-sm font-semibold">
                            <span :class="account.closing_balance_cents < 0 ? 'text-rose-600' : 'text-emerald-700'">
                                {{ formatCents(Math.abs(account.closing_balance_cents)) }}
                                <span v-if="account.closing_balance_cents < 0" class="text-xs font-normal ml-1 text-rose-400">abnormal</span>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <AppButton size="sm" variant="ghost" @click="navigateTo(account.statement_url)">
                                View entries
                            </AppButton>
                        </td>
                    </tr>

                    <!-- Group total row -->
                    <tr class="border-t border-slate-200 bg-slate-50">
                        <td colspan="5" class="px-4 py-2 text-xs font-semibold uppercase text-slate-500">
                            {{ typeLabels[group.type] ?? group.type }} total
                        </td>
                        <td class="px-4 py-2 tabular-nums text-sm font-bold text-slate-800">
                            {{ formatCents(Math.abs(group.accounts.reduce((s, a) => s + a.closing_balance_cents, 0))) }}
                        </td>
                        <td />
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>
