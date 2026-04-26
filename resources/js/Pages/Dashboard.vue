<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { BarChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
import { CircleDollarSign, HandCoins, Landmark, TrendingUp, X } from 'lucide-vue-next';
import { ref } from 'vue';

use([BarChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    kpis: { type: Object, default: () => ({}) },
    revenue_chart: { type: Array, default: () => [] },
    outstanding_invoices: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1 }) },
    recent_transactions: { type: Array, default: () => [] },
    budget_progress: { type: Array, default: () => [] },
    vat_summary: { type: Object, default: () => ({}) },
});

const formatCents = (cents) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
const paymentDrawerOpen = ref(false);
const selectedInvoice = ref(null);
const isLoading = computed(() => !props.kpis || !Object.keys(props.kpis).length);

const kpiRows = computed(() => [
    {
        key: 'revenue_mtd',
        title: 'Revenue MTD',
        icon: CircleDollarSign,
        ...props.kpis.revenue_mtd,
    },
    {
        key: 'outstanding_invoices',
        title: 'Outstanding Invoices',
        icon: HandCoins,
        ...props.kpis.outstanding_invoices,
    },
    {
        key: 'vat_liability',
        title: 'VAT Liability',
        icon: Landmark,
        ...props.kpis.vat_liability,
    },
    {
        key: 'net_profit_mtd',
        title: 'Net Profit MTD',
        icon: TrendingUp,
        ...props.kpis.net_profit_mtd,
    },
].map((item) => ({
        ...item,
        value: formatCents(item.amount ?? 0),
        hint: item.trend_percentage == null
            ? 'No prior month data'
            : `${item.trend_percentage >= 0 ? '+' : ''}${item.trend_percentage}% vs last month`,
    })));

const chartOptions = computed(() => ({
    tooltip: { trigger: 'axis' },
    legend: { data: ['Revenue', 'Expenses'] },
    grid: { left: 16, right: 16, top: 36, bottom: 24, containLabel: true },
    xAxis: { type: 'category', data: props.revenue_chart.map((row) => row.month) ?? [] },
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
            data: props.revenue_chart.map((row) => row.revenue) ?? [],
            itemStyle: { color: '#059669' },
        },
        {
            name: 'Expenses',
            type: 'bar',
            data: props.revenue_chart.map((row) => row.expenses) ?? [],
            itemStyle: { color: '#ef6f6c' },
        },
    ],
}));

const openRecordPayment = (invoice) => {
    selectedInvoice.value = invoice;
    paymentDrawerOpen.value = true;
};
</script>

<template>
    <AppLayout
        title="Dashboard"
        :breadcrumbs="[
            { label: 'Dashboard' },
        ]"
    >
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-slate-900 sm:text-2xl">
                Dashboard
            </h2>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <template v-if="isLoading">
                    <AppCard v-for="n in 4" :key="`kpi-skeleton-${n}`">
                        <div class="h-5 w-24 animate-pulse rounded bg-slate-100" />
                        <div class="mt-3 h-8 w-32 animate-pulse rounded bg-slate-100" />
                    </AppCard>
                </template>
                <template v-else>
                    <StatCard
                        v-for="kpi in kpiRows"
                        :key="kpi.key"
                        :title="kpi.title"
                        :value="kpi.value"
                        :hint="kpi.hint"
                        :trend="kpi.trend_direction ?? 'neutral'"
                        :trend-percent="kpi.trend_percentage ?? null"
                        :icon="kpi.icon"
                    />
                </template>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <AppCard class="xl:col-span-2">
                    <h3 class="mb-3 text-base font-semibold text-slate-900 sm:mb-4 sm:text-lg">Revenue vs Expenses</h3>
                    <div v-if="isLoading" class="h-56 animate-pulse rounded bg-slate-100 md:h-80" />
                    <VChart v-else class="h-56 w-full md:h-80" :option="chartOptions" autoresize />
                </AppCard>

                <AppCard>
                    <h3 class="mb-4 text-lg font-semibold text-slate-900">VAT Summary</h3>
                    <div v-if="isLoading" class="space-y-3">
                        <div v-for="n in 5" :key="`vat-skeleton-${n}`" class="h-4 animate-pulse rounded bg-slate-100" />
                    </div>
                    <div v-else class="space-y-3 text-sm">
                        <p class="text-slate-500">Current period: <span class="font-medium text-slate-700">{{ vat_summary.current_period }}</span></p>
                        <div class="flex items-center justify-between"><span>Output VAT</span><span class="font-semibold">{{ formatCents(vat_summary.output_vat) }}</span></div>
                        <div class="flex items-center justify-between"><span>Input VAT</span><span class="font-semibold">{{ formatCents(vat_summary.input_vat) }}</span></div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2"><span class="font-medium">Net VAT</span><span class="font-semibold">{{ formatCents(vat_summary.net_vat) }}</span></div>
                        <div class="text-xs text-slate-500">Due date: <DateDisplay :value="vat_summary.due_date" /></div>
                    </div>
                </AppCard>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <AppCard id="outstanding-invoices" class="xl:col-span-2">
                    <h3 class="mb-4 text-lg font-semibold text-slate-900">Outstanding Invoices</h3>
                    <div class="space-y-3 md:hidden">
                        <div
                            v-for="invoice in outstanding_invoices.data ?? []"
                            :key="`mo-${invoice.id}`"
                            class="rounded-xl border border-slate-200 bg-slate-50/80 p-4"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium text-slate-900">{{ invoice.client }}</p>
                                    <p class="text-sm text-slate-600">{{ invoice.number }}</p>
                                </div>
                                <AppBadge :variant="invoice.days_overdue > 0 ? 'danger' : 'neutral'">
                                    {{ invoice.days_overdue > 0 ? `${invoice.days_overdue}d` : 'OK' }}
                                </AppBadge>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm">
                                <span class="text-slate-500">Due <DateDisplay :value="invoice.due_date" /></span>
                                <span class="font-semibold">{{ formatCents(invoice.amount) }}</span>
                            </div>
                            <AppButton class="mt-3 w-full min-h-11" variant="secondary" @click="openRecordPayment(invoice)">Record Payment</AppButton>
                        </div>
                        <p v-if="!isLoading && !(outstanding_invoices.data ?? []).length" class="text-sm text-slate-500">No outstanding invoices.</p>
                    </div>
                    <AppTable
                        class="hidden md:block"
                        :columns="[
                            { key: 'client', label: 'Client' },
                            { key: 'number', label: 'Invoice #' },
                            { key: 'amount', label: 'Amount', sortable: true },
                            { key: 'due_date', label: 'Due Date', sortable: true },
                            { key: 'days_overdue', label: 'Days Overdue' },
                            { key: 'action', label: 'Action' },
                        ]"
                        :page="outstanding_invoices.current_page ?? 1"
                        :last-page="outstanding_invoices.last_page ?? 1"
                        :loading="isLoading"
                    >
                        <tr
                            v-for="invoice in outstanding_invoices.data ?? []"
                            :key="invoice.id"
                            :class="[
                                'text-sm text-slate-700',
                                invoice.days_overdue > 0 ? 'border-l-2 border-l-rose-300' : '',
                            ]"
                        >
                            <td class="px-4 py-3">{{ invoice.client }}</td>
                            <td class="px-4 py-3 font-medium">{{ invoice.number }}</td>
                            <td class="px-4 py-3">{{ formatCents(invoice.amount) }}</td>
                            <td class="px-4 py-3"><DateDisplay :value="invoice.due_date" /></td>
                            <td class="px-4 py-3">
                                <AppBadge :variant="invoice.days_overdue > 0 ? 'danger' : 'neutral'">
                                    {{ invoice.days_overdue > 0 ? `${invoice.days_overdue} days` : 'Current' }}
                                </AppBadge>
                            </td>
                            <td class="px-4 py-3">
                                <AppButton size="sm" variant="secondary" @click="openRecordPayment(invoice)">Record Payment</AppButton>
                            </td>
                        </tr>
                        <tr v-if="!isLoading && !(outstanding_invoices.data ?? []).length">
                            <td class="px-4 py-4 text-sm text-slate-500" colspan="6">No outstanding invoices.</td>
                        </tr>
                    </AppTable>
                </AppCard>

                <AppCard>
                    <h3 class="mb-4 text-lg font-semibold text-slate-900">Budget Progress (Current Month)</h3>
                    <div v-if="isLoading" class="space-y-3">
                        <div v-for="n in 4" :key="`budget-skeleton-${n}`" class="h-8 animate-pulse rounded bg-slate-100" />
                    </div>
                    <div v-else class="space-y-4">
                        <div v-for="item in budget_progress" :key="item.category">
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-medium text-slate-700">{{ item.category }}</span>
                                <span class="text-slate-500">
                                    {{ formatCents(item.spent) }} / {{ formatCents(item.allocated) }}
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100">
                                <div
                                    :class="[
                                        'h-2 rounded-full',
                                        item.percentage < 75 ? 'bg-emerald-600' : item.percentage <= 90 ? 'bg-amber-500' : 'bg-rose-500',
                                    ]"
                                    :style="{ width: `${Math.min(item.percentage, 100)}%` }"
                                />
                            </div>
                        </div>
                        <p v-if="!budget_progress.length" class="text-sm text-slate-500">No budget categories for this month yet.</p>
                    </div>
                </AppCard>
            </div>

            <AppCard>
                <h3 class="mb-4 text-lg font-semibold text-slate-900">Recent Transactions</h3>
                <AppTable
                    :columns="[
                        { key: 'date', label: 'Date', sortable: true },
                        { key: 'description', label: 'Description' },
                        { key: 'account', label: 'Account' },
                        { key: 'type', label: 'Type' },
                        { key: 'amount', label: 'Amount', sortable: true },
                    ]"
                    :loading="isLoading"
                >
                    <tr v-for="transaction in recent_transactions" :key="transaction.id" class="text-sm text-slate-700">
                        <td class="px-4 py-3"><DateDisplay :value="transaction.date" /></td>
                        <td class="px-4 py-3 font-medium">{{ transaction.description }}</td>
                        <td class="px-4 py-3">{{ transaction.account }}</td>
                        <td class="px-4 py-3">
                            <AppBadge variant="info">{{ transaction.type }}</AppBadge>
                        </td>
                        <td class="px-4 py-3">{{ formatCents(transaction.amount_cents) }}</td>
                    </tr>
                    <tr v-if="!isLoading && !recent_transactions.length">
                        <td class="px-4 py-4 text-sm text-slate-500" colspan="5">No recent transactions.</td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>

        <div
            v-if="paymentDrawerOpen"
            class="fixed inset-0 z-[80] bg-black/40"
            @click="paymentDrawerOpen = false"
        />
        <aside
            :class="[
                'fixed inset-y-0 right-0 z-[90] w-full max-w-md transform bg-white shadow-xl transition-transform',
                paymentDrawerOpen ? 'translate-x-0' : 'translate-x-full',
            ]"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-lg font-semibold text-slate-900">Record Payment</h3>
                <button class="rounded p-1 hover:bg-slate-100" @click="paymentDrawerOpen = false">
                    <X class="h-5 w-5" />
                </button>
            </div>
            <div class="space-y-4 px-5 py-4 text-sm text-slate-600">
                <p v-if="selectedInvoice">
                    Invoice <strong>{{ selectedInvoice.number }}</strong> for
                    <strong>{{ selectedInvoice.client }}</strong> is ready for payment capture.
                </p>
                <p>UI scaffold ready. Hook this to the payment form/action next.</p>
                <AppButton variant="primary">Continue</AppButton>
            </div>
        </aside>
    </AppLayout>
</template>
