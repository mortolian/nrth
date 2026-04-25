<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { useDateRange } from '@/composables/useDateRange';
import { useFlash } from '@/composables/useFlash';
import { BarChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';

use([BarChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    kpis: { type: Array, default: () => [] },
    revenue_vs_expenses: {
        type: Object,
        default: () => ({ labels: [], revenue_cents: [], expense_cents: [] }),
    },
    outstanding_invoices: { type: Array, default: () => [] },
    recent_transactions: { type: Array, default: () => [] },
    budget_progress: { type: Array, default: () => [] },
});

const { setPreset } = useDateRange();
setPreset('this_month');
const { success, error } = useFlash();

const formatCents = (cents) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const kpiRows = computed(() =>
    props.kpis.map((kpi) => ({
        title: kpi.title,
        value: formatCents(kpi.value_cents),
        hint: kpi.trend_percent == null
            ? 'No prior month data'
            : `${kpi.trend_percent >= 0 ? '+' : ''}${kpi.trend_percent}% vs last month`,
    })),
);

const chartOptions = computed(() => ({
    tooltip: { trigger: 'axis' },
    legend: { data: ['Revenue', 'Expenses'] },
    grid: { left: 16, right: 16, top: 36, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: props.revenue_vs_expenses.labels ?? [] },
    yAxis: {
        type: 'value',
        axisLabel: {
            formatter: (value) => `R ${(Number(value) / 100).toLocaleString('en-ZA')}`,
        },
    },
    series: [
        {
            name: 'Revenue',
            type: 'bar',
            data: props.revenue_vs_expenses.revenue_cents ?? [],
            itemStyle: { color: '#059669' },
        },
        {
            name: 'Expenses',
            type: 'bar',
            data: props.revenue_vs_expenses.expense_cents ?? [],
            itemStyle: { color: '#dc2626' },
        },
    ],
}));
</script>

<template>
    <AppLayout
        title="Dashboard"
        :breadcrumbs="[
            { label: 'Dashboard' },
        ]"
    >
        <template #header>
            <h2 class="text-2xl font-semibold leading-tight text-slate-900">
                Dashboard
            </h2>
        </template>

        <div class="space-y-6">
            <AppCard v-if="success || error">
                <p v-if="success" class="text-sm text-emerald-700">{{ success }}</p>
                <p v-if="error" class="text-sm text-rose-700">{{ error }}</p>
            </AppCard>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="kpi in kpiRows"
                    :key="kpi.title"
                    :title="kpi.title"
                    :value="kpi.value"
                    :hint="kpi.hint"
                />
            </div>

            <AppCard>
                <h3 class="mb-4 text-lg font-semibold text-slate-900">Revenue vs Expenses (Last 6 Months)</h3>
                <VChart class="h-80 w-full" :option="chartOptions" autoresize />
            </AppCard>

            <AppCard>
                <h3 class="mb-4 text-lg font-semibold text-slate-900">Outstanding Invoices</h3>
                <AppTable :columns="['Client', 'Invoice #', 'Amount', 'Due Date', 'Days Overdue', 'Action']">
                    <tr
                        v-for="invoice in outstanding_invoices"
                        :key="invoice.id"
                        class="text-sm text-slate-700"
                    >
                        <td class="px-4 py-3">{{ invoice.client_name }}</td>
                        <td class="px-4 py-3 font-medium">{{ invoice.invoice_number }}</td>
                        <td class="px-4 py-3">{{ formatCents(invoice.amount_cents) }}</td>
                        <td class="px-4 py-3"><DateDisplay :value="invoice.due_date" /></td>
                        <td class="px-4 py-3">
                            <AppBadge :variant="invoice.days_overdue > 0 ? 'danger' : 'default'">
                                {{ invoice.days_overdue > 0 ? `${invoice.days_overdue} days` : 'Current' }}
                            </AppBadge>
                        </td>
                        <td class="px-4 py-3">
                            <AppButton size="sm" variant="secondary">Record payment</AppButton>
                        </td>
                    </tr>
                    <tr v-if="!outstanding_invoices.length">
                        <td class="px-4 py-4 text-sm text-slate-500" colspan="6">No outstanding invoices.</td>
                    </tr>
                </AppTable>
            </AppCard>

            <div class="grid gap-6 lg:grid-cols-2">
                <AppCard>
                    <h3 class="mb-4 text-lg font-semibold text-slate-900">Recent Transactions</h3>
                    <ul class="space-y-3">
                        <li
                            v-for="transaction in recent_transactions"
                            :key="transaction.id"
                            class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2"
                        >
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ transaction.description }}</p>
                                <p class="text-xs text-slate-500">
                                    <DateDisplay :value="transaction.date" /> · {{ transaction.account }}
                                </p>
                            </div>
                            <p class="text-sm font-semibold text-slate-900">{{ formatCents(transaction.amount_cents) }}</p>
                        </li>
                        <li v-if="!recent_transactions.length" class="text-sm text-slate-500">No recent transactions.</li>
                    </ul>
                </AppCard>

                <AppCard>
                    <h3 class="mb-4 text-lg font-semibold text-slate-900">Budget Progress (Current Month)</h3>
                    <div class="space-y-4">
                        <div v-for="item in budget_progress" :key="item.category">
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-medium text-slate-700">{{ item.category }}</span>
                                <span class="text-slate-500">
                                    {{ formatCents(item.spent_cents) }} / {{ formatCents(item.allocated_cents) }}
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100">
                                <div
                                    class="h-2 rounded-full bg-slate-900"
                                    :style="{ width: `${Math.min(item.progress_percent, 100)}%` }"
                                />
                            </div>
                        </div>
                        <p v-if="!budget_progress.length" class="text-sm text-slate-500">No budget categories for this month yet.</p>
                    </div>
                </AppCard>
            </div>
        </div>
    </AppLayout>
</template>
