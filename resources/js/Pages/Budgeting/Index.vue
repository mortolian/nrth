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
import { ArrowDownRight, ArrowUpRight } from 'lucide-vue-next';

use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    budgets: { type: Array, default: () => [] },
    active_budget: { type: Object, default: null },
    monthly_variance: { type: Array, default: () => [] },
});

const formatCents = (cents) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const progressColor = (percentage) => {
    if (percentage >= 100) return 'bg-rose-500';
    if (percentage >= 80) return 'bg-amber-500';
    return 'bg-brand-500';
};

const chartOptions = computed(() => ({
    tooltip: { trigger: 'axis' },
    legend: { data: ['Budgeted', 'Actual'] },
    grid: { left: 16, right: 16, top: 36, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: props.monthly_variance.map((row) => row.month) },
    yAxis: {
        type: 'value',
        axisLabel: {
            formatter: (value) => `R ${(Number(value) / 100).toLocaleString('en-ZA')}`,
        },
    },
    series: [
        {
            name: 'Budgeted',
            type: 'line',
            data: props.monthly_variance.map((row) => row.budgeted),
            lineStyle: { type: 'dashed', color: '#0ea5e9' },
            itemStyle: { color: '#0ea5e9' },
        },
        {
            name: 'Actual',
            type: 'line',
            data: props.monthly_variance.map((row) => row.actual),
            lineStyle: { color: '#22c55e' },
            itemStyle: { color: '#22c55e' },
        },
    ],
}));
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
                    <h3 class="mb-3 text-lg font-semibold text-slate-900">Category Breakdown</h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div v-for="row in active_budget.categories" :key="row.category" class="rounded-lg border border-slate-200 p-3">
                            <div class="flex items-start justify-between">
                                <p class="font-medium text-slate-900">{{ row.category }}</p>
                                <span class="inline-flex items-center gap-1 text-xs" :class="row.trend === 'faster' ? 'text-rose-600' : 'text-brand-600'">
                                    <ArrowUpRight v-if="row.trend === 'faster'" class="h-3.5 w-3.5" />
                                    <ArrowDownRight v-else class="h-3.5 w-3.5" />
                                    {{ row.trend === 'faster' ? 'Faster' : 'Slower' }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Allocated: {{ formatCents(row.allocated) }}</p>
                            <p class="text-xs text-slate-500">Spent: {{ formatCents(row.spent) }}</p>
                            <p class="text-xs text-slate-500">Remaining: {{ formatCents(row.remaining) }}</p>
                            <div class="mt-2 h-2 w-full rounded-full bg-slate-100">
                                <div
                                    :class="progressColor(row.percentage)"
                                    class="h-2 rounded-full"
                                    :style="{ width: `${Math.min(100, row.percentage)}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Monthly Variance</h3>
            <VChart class="h-80 w-full" :option="chartOptions" autoresize />
            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span
                    v-for="row in monthly_variance.filter((item) => item.variance < 0)"
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
                <tr v-for="budget in budgets" :key="budget.name">
                    <td class="px-4 py-3 font-medium text-slate-900">{{ budget.name }}</td>
                    <td class="px-4 py-3">{{ budget.period }}</td>
                    <td class="px-4 py-3">{{ formatCents(budget.total_allocated) }}</td>
                    <td class="px-4 py-3">{{ formatCents(budget.total_spent) }}</td>
                    <td class="px-4 py-3">{{ budget.percentage_used }}%</td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="budget.status === 'active' ? 'success' : 'neutral'">{{ budget.status }}</AppBadge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <AppButton size="sm" variant="ghost" @click="router.visit(route('budgeting.edit', budget.id))">Edit</AppButton>
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
