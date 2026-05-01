<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
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
    vat_enabled: { type: Boolean, default: true },
});

const formatCents = (cents) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
const formatRowCents = (cents, currency) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');
const daysOverdueInt = (value) => {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.max(0, Math.floor(n));
};
const paymentDrawerOpen = ref(false);
const selectedInvoice = ref(null);
const isLoading = computed(() => !props.kpis || !Object.keys(props.kpis).length);
const isChartLoading = computed(() => {
    if (isLoading.value) return true;
    if (!props.revenue_chart || props.revenue_chart.length === 0) return true;

    // If backend hasn't populated meaningful values yet, avoid rendering an empty chart.
    return props.revenue_chart.every((row) => {
        const revenue = Number(row?.revenue ?? 0);
        const expenses = Number(row?.expenses ?? 0);
        return revenue === 0 && expenses === 0;
    });
});

const kpiRows = computed(() => {
    const base = [
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
            key: 'net_profit_mtd',
            title: 'Net Profit MTD',
            icon: TrendingUp,
            ...props.kpis.net_profit_mtd,
        },
    ];

    if (props.vat_enabled) {
        base.push({
            key: 'vat_liability',
            title: 'VAT Liability',
            icon: Landmark,
            ...props.kpis.vat_liability,
        });
    }

    return base.map((item) => ({
        ...item,
        value: formatCents(item.amount ?? 0),
        hint: item.trend_percentage == null
            ? 'No prior month data'
            : `${item.trend_percentage >= 0 ? '+' : ''}${item.trend_percentage}% vs last month`,
    }));
});

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
            itemStyle: { color: '#00a86b' },
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

const rowActionItems = (invoice) => {
    const actions = [
        { id: 'view', label: 'View' },
        { id: 'download_pdf', label: 'Download PDF' },
    ];
    if (invoice.status === 'draft') {
        actions.push({ id: 'send', label: 'Send' });
        actions.push({ id: 'mark_sent', label: 'Mark as sent' });
    }
    if (invoice.status !== 'paid' && invoice.status !== 'void') {
        actions.push({ id: 'record_payment', label: 'Record Payment' });
    }
    if (invoice.status === 'sent') actions.push({ id: 'void', label: 'Void' });
    if (invoice.status === 'void') actions.push({ id: 'unvoid', label: 'Restore' });
    if (invoice.can_delete) actions.push({ id: 'delete', label: 'Delete' });
    return actions;
};

const onInvoiceAction = (invoice, actionId) => {
    if (actionId === 'view') {
        router.visit(route('invoicing.invoices.show', invoice.id));
    } else if (actionId === 'download_pdf') {
        window.location.assign(route('invoices.pdf.download', invoice.id));
    } else if (actionId === 'send') {
        router.post(route('invoicing.invoices.send', invoice.id));
    } else if (actionId === 'mark_sent') {
        router.post(route('invoicing.invoices.mark-sent', invoice.id));
    } else if (actionId === 'void') {
        router.post(route('invoicing.invoices.void', invoice.id));
    } else if (actionId === 'unvoid') {
        router.post(route('invoicing.invoices.unvoid', invoice.id));
    } else if (actionId === 'record_payment') {
        openRecordPayment(invoice);
    } else if (actionId === 'delete') {
        if (!window.confirm(`Permanently delete invoice ${invoice.number}? This cannot be undone.`)) {
            return;
        }
        router.delete(route('invoicing.invoices.destroy', invoice.id), {
            preserveScroll: true,
        });
    }
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
            <div
                class="grid gap-4 sm:grid-cols-2"
                :class="props.vat_enabled ? 'xl:grid-cols-4' : 'xl:grid-cols-3'"
            >
                <template v-if="isLoading">
                    <AppCard v-for="n in (props.vat_enabled ? 4 : 3)" :key="`kpi-skeleton-${n}`">
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
                    <div
                        v-if="isChartLoading"
                        class="flex h-56 items-center justify-center rounded-md border-2 border-dashed border-slate-200 bg-slate-50 px-4 text-center md:h-80"
                    >
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-slate-700">No chart data yet</p>
                            <p class="text-xs text-slate-500">
                                Revenue vs Expenses will appear once there are posted transactions for this period.
                            </p>
                        </div>
                    </div>
                    <div v-else class="h-56 w-full md:h-80">
                        <VChart class="h-full w-full" :option="chartOptions" autoresize />
                    </div>
                </AppCard>

                <AppCard v-if="props.vat_enabled">
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
                <AppCard v-else>
                    <h3 class="mb-2 text-lg font-semibold text-slate-900">VAT is disabled</h3>
                    <p class="text-sm text-slate-600">
                        VAT cards and reports are hidden until VAT is enabled in company settings.
                    </p>
                    <a :href="route('settings.company', { tab: 'tax' })" class="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">
                        Enable VAT in Company settings
                    </a>
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
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900">{{ invoice.client }}</p>
                                    <p class="truncate text-sm text-slate-600">{{ invoice.number }}</p>
                                </div>
                                <AppBadge :variant="daysOverdueInt(invoice.days_overdue) > 0 ? 'danger' : 'neutral'" class="whitespace-nowrap">
                                    {{ daysOverdueInt(invoice.days_overdue) > 0 ? `${daysOverdueInt(invoice.days_overdue)}d` : 'OK' }}
                                </AppBadge>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-sm">
                                <span class="text-slate-500 whitespace-nowrap">Due <DateDisplay :value="invoice.due_date" /></span>
                                <span class="font-semibold">{{ formatRowCents(invoice.amount, invoice.currency) }}</span>
                            </div>
                            <div class="mt-3 flex justify-end">
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(invoice)"
                                    :aria-label="`Actions for ${invoice.number}`"
                                    @select="(id) => onInvoiceAction(invoice, id)"
                                />
                            </div>
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
                            { key: 'actions', label: '', widthClass: 'w-[1%] whitespace-nowrap text-right' },
                        ]"
                        table-class="min-w-[820px]"
                        :page="outstanding_invoices.current_page ?? 1"
                        :last-page="outstanding_invoices.last_page ?? 1"
                        :loading="isLoading"
                    >
                        <tr
                            v-for="invoice in outstanding_invoices.data ?? []"
                            :key="invoice.id"
                            :class="[
                                'text-sm text-slate-700',
                                daysOverdueInt(invoice.days_overdue) > 0 ? 'border-l-2 border-l-rose-300' : '',
                            ]"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">{{ invoice.client }}</td>
                            <td class="px-4 py-3 whitespace-nowrap font-medium">{{ invoice.number }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ formatRowCents(invoice.amount, invoice.currency) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap"><DateDisplay :value="invoice.due_date" /></td>
                            <td class="px-4 py-3">
                                <AppBadge :variant="daysOverdueInt(invoice.days_overdue) > 0 ? 'danger' : 'neutral'" class="whitespace-nowrap">
                                    {{ daysOverdueInt(invoice.days_overdue) > 0 ? `${daysOverdueInt(invoice.days_overdue)} days` : 'Current' }}
                                </AppBadge>
                            </td>
                            <td class="px-4 py-3 text-right align-middle">
                                <div class="inline-flex justify-end">
                                    <InvoiceRowActionsMenu
                                        :actions="rowActionItems(invoice)"
                                        :aria-label="`Actions for ${invoice.number}`"
                                        @select="(id) => onInvoiceAction(invoice, id)"
                                    />
                                </div>
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
                                        item.percentage < 75 ? 'bg-brand-500' : item.percentage <= 90 ? 'bg-amber-500' : 'bg-rose-500',
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
