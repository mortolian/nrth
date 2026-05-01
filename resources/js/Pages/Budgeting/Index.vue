<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    budgets: { type: Array, default: () => [] },
    active_budget: { type: Object, default: null },
    monthly_variance: { type: Array, default: () => [] },
    company_currency: { type: String, default: 'ZAR' },
    variance_currency_aligned: { type: Boolean, default: true },
});

const displayCurrency = computed(() => {
    if (props.active_budget?.currency) return props.active_budget.currency;
    const first = props.budgets[0];
    return first?.currency ?? 'ZAR';
});

const formatCents = (cents, currency = displayCurrency.value) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const deleteBudget = (budget) => {
    if (!window.confirm(`Delete budget “${budget.name}”? This cannot be undone.`)) return;
    router.delete(route('budgeting.destroy', budget.id), { preserveScroll: true });
};

const progressColor = (percentage) => {
    if (percentage >= 100) return 'bg-rose-500';
    if (percentage >= 80) return 'bg-amber-500';
    return 'bg-brand-500';
};

const chartOptions = computed(() => {
    const sym = displayCurrency.value === 'ZAR' ? 'R' : displayCurrency.value;
    const series = [
        {
            name: 'Budgeted',
            type: 'line',
            data: props.monthly_variance.map((row) => row.budgeted),
            lineStyle: { type: 'dashed', color: '#0ea5e9' },
            itemStyle: { color: '#0ea5e9' },
        },
    ];
    if (props.variance_currency_aligned) {
        series.push({
            name: `Actual (${props.company_currency})`,
            type: 'line',
            data: props.monthly_variance.map((row) => row.actual ?? 0),
            lineStyle: { color: '#22c55e' },
            itemStyle: { color: '#22c55e' },
        });
    }
    return {
        tooltip: { trigger: 'axis' },
        legend: { data: series.map((s) => s.name) },
        grid: { left: 16, right: 16, top: 36, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: props.monthly_variance.map((row) => row.month) },
        yAxis: {
            type: 'value',
            axisLabel: {
                formatter: (value) => `${sym} ${(Number(value) / 100).toLocaleString('en-ZA')}`,
            },
        },
        series,
    };
});
</script>

<template>
    <AppLayout
        title="Budgeting"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgeting' },
        ]"
    >
        <PageHeader title="Budgeting Overview" subtitle="Track plan vs spend across categories and months">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">New Budget</AppButton>
            </template>
        </PageHeader>

        <AppCard v-if="active_budget" class="mt-5">
            <div class="grid gap-6 xl:grid-cols-3">
                <div class="xl:col-span-1">
                    <h3 class="text-lg font-semibold text-slate-900">{{ active_budget.name }}</h3>
                    <p class="text-sm text-slate-500">{{ active_budget.period }}</p>
                    <div class="mt-4 rounded-xl border border-slate-200 p-4">
                        <p class="text-sm text-slate-500">Overall spend vs budget</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">
                            {{ active_budget.percentage_used }}%
                        </p>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ formatCents(active_budget.total_spent) }} / {{ formatCents(active_budget.total_allocated) }}
                        </p>
                        <p v-if="!active_budget.company_spend_aligned" class="mt-1 text-xs text-amber-700">
                            Spend totals mix ledger-linked categories only; set budget currency to {{ company_currency }} to compare all expenses.
                        </p>
                        <div class="mt-3 h-3 w-full rounded-full bg-slate-100">
                            <div
                                :class="progressColor(active_budget.percentage_used)"
                                class="h-3 rounded-full"
                                :style="{ width: `${Math.min(100, active_budget.percentage_used)}%` }"
                            />
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-2">
                    <h3 class="mb-3 text-lg font-semibold text-slate-900">Categories & line items</h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div v-for="row in active_budget.categories" :key="row.name" class="rounded-lg border border-slate-200 p-3">
                            <p class="font-medium text-slate-900">{{ row.name }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                Envelope (period): {{ formatCents(row.envelope_cents) }} · Planned from lines:
                                {{ formatCents(row.period_planned_cents) }}
                                <span v-if="row.planned_fill_percent > 100" class="text-rose-600"> (over envelope)</span>
                            </p>
                            <p v-if="row.has_account" class="text-xs text-slate-500">
                                Spent (linked account): {{ formatCents(row.spent_cents) }} · Remaining:
                                {{ formatCents(row.remaining_cents) }}
                            </p>
                            <p v-else class="text-xs text-slate-400">No ledger account linked — spend bar is hidden.</p>
                            <div v-if="row.has_account" class="mt-2 h-2 w-full rounded-full bg-slate-100">
                                <div
                                    :class="progressColor(row.percentage)"
                                    class="h-2 rounded-full"
                                    :style="{ width: `${Math.min(100, row.percentage)}%` }"
                                />
                            </div>
                            <ul v-if="row.items?.length" class="mt-2 space-y-1 border-t border-slate-100 pt-2 text-xs text-slate-600">
                                <li v-for="(it, idx) in row.items" :key="idx">
                                    {{ it.label }} — {{ formatCents(it.monthly_budget_currency_cents) }}/mo in budget currency (line
                                    {{ formatCents(it.monthly_amount_cents, it.currency) }}/mo); period
                                    {{ formatCents(it.period_total_budget_cents) }}, annualised
                                    {{ formatCents(it.annualized_budget_cents) }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AppCard>

        <AppCard v-else-if="budgets.length" class="mt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">No active budget</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Edit a budget and enable “Set as active budget” so targets, variance, and the dashboard use it.
                    </p>
                </div>
                <AppButton variant="primary" @click="router.visit(route('budgeting.edit', budgets[0].id))">Open a budget</AppButton>
            </div>
        </AppCard>

        <AppCard v-else class="mt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">No budgets yet</h3>
                    <p class="mt-1 text-sm text-slate-500">Create a budget to plan expense categories and track variance.</p>
                </div>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">New Budget</AppButton>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Monthly Variance</h3>
            <p v-if="!active_budget" class="mb-3 text-sm text-slate-500">
                Budgeted amounts appear when you have an active budget covering these months.
            </p>
            <p v-else-if="!variance_currency_aligned" class="mb-3 text-sm text-amber-800">
                Budget is in {{ displayCurrency }} but books use {{ company_currency }}; only the budgeted series is compared here.
            </p>
            <VChart class="h-80 w-full" :option="chartOptions" autoresize />
            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span
                    v-for="row in monthly_variance.filter((item) => item.variance != null && item.variance < 0)"
                    :key="`over-${row.month}`"
                    class="rounded-md bg-rose-50 px-2 py-1 text-rose-700"
                >
                    Over budget: {{ row.month }} ({{ formatCents(Math.abs(row.variance)) }})
                </span>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Past Budgets</h3>
            <AppTable
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'period', label: 'Period' },
                    { key: 'allocated', label: 'Total allocated' },
                    { key: 'spent', label: 'Total spent' },
                    { key: 'used', label: '% used' },
                    { key: 'status', label: 'Status' },
                    { key: 'actions', label: '' },
                ]"
                :page="1"
                :last-page="1"
            >
                <tr v-for="budget in budgets" :key="budget.id">
                    <td class="px-4 py-3 font-medium text-slate-900">{{ budget.name }}</td>
                    <td class="px-4 py-3">{{ budget.period }}</td>
                    <td class="px-4 py-3">{{ formatCents(budget.total_allocated, budget.currency) }}</td>
                    <td class="px-4 py-3">{{ formatCents(budget.total_spent, budget.currency) }}</td>
                    <td class="px-4 py-3">{{ budget.percentage_used }}%</td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="budget.status === 'active' ? 'success' : 'neutral'">{{ budget.status }}</AppBadge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex justify-end gap-1">
                            <AppButton size="sm" variant="ghost" @click="router.visit(route('budgeting.edit', budget.id))">Edit</AppButton>
                            <AppButton size="sm" variant="ghost" class="text-rose-600 hover:text-rose-700" @click="deleteBudget(budget)">
                                Delete
                            </AppButton>
                        </div>
                    </td>
                </tr>
                <tr v-if="!budgets.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState title="No budgets yet" description="Create your first budget to start tracking variance." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
