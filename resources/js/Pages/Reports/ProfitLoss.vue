<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type Line = { account_id: number; code: string; name: string; amount: number };

const props = defineProps<{
    report: {
        income: Line[];
        expenses: Line[];
        totals: { income: number; expenses: number; net_profit: number };
    };
    comparison: null | {
        income: Line[];
        expenses: Line[];
        totals: { income: number; expenses: number; net_profit: number };
    };
    period: { from: string; to: string; preset: string };
    compare: string;
}>();

const state = ref({
    preset: props.period.preset || 'this_month',
    compare: props.compare || 'none',
    from: props.period.from,
    to: props.period.to,
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const comparisonMap = computed(() => ({
    income: Object.fromEntries((props.comparison?.income ?? []).map((line) => [line.account_id, line.amount])),
    expenses: Object.fromEntries((props.comparison?.expenses ?? []).map((line) => [line.account_id, line.amount])),
}));

const apply = () => {
    router.get(route('reports.profit-loss'), { ...state.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const drilldown = (line: Line) => {
    router.visit(route('accounting.transactions.index'), { data: { search: line.name } });
};
</script>

<template>
    <AppLayout
        title="Profit & Loss"
        :breadcrumbs="[
            { label: 'Reports' },
            { label: 'Profit & Loss' },
        ]"
    >
        <PageHeader title="Profit & Loss" subtitle="Standard statement of income and expenses">
            <template #actions>
                <AppButton variant="secondary">Export PDF</AppButton>
                <AppButton variant="secondary">Export Excel</AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Period</label>
                    <AppSelect
                        :model-value="state.preset"
                        :options="[
                            { label: 'This Month', value: 'this_month' },
                            { label: 'Last Month', value: 'last_month' },
                            { label: 'This Quarter', value: 'this_quarter' },
                            { label: 'This Tax Year', value: 'this_tax_year' },
                            { label: 'Last Tax Year', value: 'last_tax_year' },
                            { label: 'Custom', value: 'custom' },
                        ]"
                        @update:model-value="state.preset = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Compare to</label>
                    <AppSelect
                        :model-value="state.compare"
                        :options="[
                            { label: 'None', value: 'none' },
                            { label: 'Previous Period', value: 'previous_period' },
                            { label: 'Same Period Last Year', value: 'same_period_last_year' },
                        ]"
                        @update:model-value="state.compare = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="state.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="state.to" type="date" />
                </div>
                <div class="flex items-end">
                    <AppButton variant="secondary" @click="apply">Apply</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="text-lg font-semibold text-slate-900">INCOME</h3>
            <table class="mt-2 min-w-full text-sm">
                <tbody>
                    <tr
                        v-for="line in report.income"
                        :key="`income-${line.account_id}`"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="drilldown(line)"
                    >
                        <td class="px-2 py-2">{{ line.name }}</td>
                        <td class="px-2 py-2 text-right">{{ formatCents(line.amount) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right text-slate-500">
                            {{ formatCents(comparisonMap.income[line.account_id] || 0) }}
                        </td>
                        <td v-if="comparison" class="px-2 py-2 text-right">
                            <span :class="line.amount - (comparisonMap.income[line.account_id] || 0) >= 0 ? 'text-brand-700' : 'text-rose-600'">
                                {{ formatCents(line.amount - (comparisonMap.income[line.account_id] || 0)) }}
                            </span>
                        </td>
                    </tr>
                    <tr class="border-t border-slate-300 font-semibold">
                        <td class="px-2 py-2">Total Income</td>
                        <td class="px-2 py-2 text-right">{{ formatCents(report.totals.income) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right">{{ formatCents(comparison.totals.income) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right">{{ formatCents(report.totals.income - comparison.totals.income) }}</td>
                    </tr>
                </tbody>
            </table>

            <h3 class="mt-6 text-lg font-semibold text-slate-900">EXPENSES</h3>
            <table class="mt-2 min-w-full text-sm">
                <tbody>
                    <tr
                        v-for="line in report.expenses"
                        :key="`expense-${line.account_id}`"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="drilldown(line)"
                    >
                        <td class="px-2 py-2">{{ line.name }}</td>
                        <td class="px-2 py-2 text-right">{{ formatCents(line.amount) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right text-slate-500">
                            {{ formatCents(comparisonMap.expenses[line.account_id] || 0) }}
                        </td>
                        <td v-if="comparison" class="px-2 py-2 text-right">
                            <span :class="line.amount - (comparisonMap.expenses[line.account_id] || 0) <= 0 ? 'text-brand-700' : 'text-rose-600'">
                                {{ formatCents(line.amount - (comparisonMap.expenses[line.account_id] || 0)) }}
                            </span>
                        </td>
                    </tr>
                    <tr class="border-t border-slate-300 font-semibold">
                        <td class="px-2 py-2">Total Expenses</td>
                        <td class="px-2 py-2 text-right">{{ formatCents(report.totals.expenses) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right">{{ formatCents(comparison.totals.expenses) }}</td>
                        <td v-if="comparison" class="px-2 py-2 text-right">{{ formatCents(report.totals.expenses - comparison.totals.expenses) }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-6 border-t-2 border-slate-900 pt-4">
                <p class="text-lg font-semibold">NET PROFIT / (LOSS)</p>
                <p :class="report.totals.net_profit >= 0 ? 'text-brand-700' : 'text-rose-600'" class="text-3xl font-bold">
                    {{ formatCents(report.totals.net_profit) }}
                </p>
            </div>
        </AppCard>
    </AppLayout>
</template>
