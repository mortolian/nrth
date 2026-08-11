<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LineChart, BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTable from '@/Components/AppTable.vue';
import type { TableColumn } from '@/Components/AppTable.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

use([CanvasRenderer, LineChart, BarChart, GridComponent, TooltipComponent]);

const props = defineProps<{
    portfolio: { id: number; name: string; base_currency: string };
    monthly: Array<{ label: string; date: string; value_cents: number }>;
    annual: Array<{ label: string; date: string; value_cents: number }>;
}>();

const currency = computed(() => props.portfolio.base_currency || 'ZAR');
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, currency.value);

const monthlyChart = computed(() => ({
    tooltip: { trigger: 'axis' },
    grid: { left: 56, right: 16, top: 24, bottom: 40 },
    xAxis: { type: 'category', data: props.monthly.map((p) => p.label), axisLabel: { rotate: 45, fontSize: 10 } },
    yAxis: {
        type: 'value',
        axisLabel: { formatter: (v: number) => useFormatCurrency(v / 100, currency.value), fontSize: 11 },
    },
    series: [{ type: 'line', smooth: true, data: props.monthly.map((p) => p.value_cents), lineStyle: { color: '#0f766e' }, itemStyle: { color: '#0f766e' } }],
}));

const annualChart = computed(() => ({
    tooltip: { trigger: 'axis' },
    grid: { left: 56, right: 16, top: 24, bottom: 32 },
    xAxis: { type: 'category', data: props.annual.map((p) => p.label) },
    yAxis: {
        type: 'value',
        axisLabel: { formatter: (v: number) => useFormatCurrency(v / 100, currency.value), fontSize: 11 },
    },
    series: [{ type: 'bar', data: props.annual.map((p) => p.value_cents), itemStyle: { color: '#0f766e' } }],
}));

const columns: TableColumn[] = [
    { key: 'period', label: 'Period' },
    { key: 'date', label: 'As of' },
    { key: 'value', label: 'Portfolio value', align: 'right' },
];
</script>

<template>
    <AppLayout
        title="Wealth history"
        :breadcrumbs="[
            { label: 'Wealth', href: route('wealth.index') },
            { label: 'History' },
        ]"
    >
        <PageHeader
            title="Portfolio history"
            subtitle="Derived from individual asset valuation snapshots."
        >
            <template #actions>
                <Link :href="route('wealth.index')">
                    <AppButton variant="secondary" size="sm">Back to overview</AppButton>
                </Link>
            </template>
        </PageHeader>

        <AppCard class="mt-6">
            <h3 class="mb-3 text-base font-semibold text-slate-900">Monthly</h3>
            <div v-if="monthly.length" class="h-64 w-full md:h-80">
                <VChart class="h-full w-full" :option="monthlyChart" autoresize />
            </div>
            <AppTable v-if="monthly.length" class="mt-4" :columns="columns" :show-pagination="false" dense table-class="text-sm" embedded>
                <tr v-for="row in [...monthly].reverse()" :key="row.date">
                    <td class="whitespace-nowrap px-3 py-2">{{ row.label }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.date }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.value_cents) }}</td>
                </tr>
            </AppTable>
            <p v-else class="text-sm text-slate-500">No monthly history yet.</p>
        </AppCard>

        <AppCard class="mt-6">
            <h3 class="mb-3 text-base font-semibold text-slate-900">Annual (financial year end)</h3>
            <div v-if="annual.length" class="h-56 w-full md:h-72">
                <VChart class="h-full w-full" :option="annualChart" autoresize />
            </div>
            <AppTable v-if="annual.length" class="mt-4" :columns="columns" :show-pagination="false" dense table-class="text-sm" embedded>
                <tr v-for="row in [...annual].reverse()" :key="`${row.label}-${row.date}`">
                    <td class="whitespace-nowrap px-3 py-2">{{ row.label }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.date }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.value_cents) }}</td>
                </tr>
            </AppTable>
            <p v-else class="text-sm text-slate-500">No annual history yet.</p>
        </AppCard>
    </AppLayout>
</template>
