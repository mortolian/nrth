<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import { ChevronDown, StickyNote } from 'lucide-vue-next';
import VChart from 'vue-echarts';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTable from '@/Components/AppTable.vue';
import type { TableColumn } from '@/Components/AppTable.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import type { RowActionItem } from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent]);

type Option = { value: string; label: string };

const props = defineProps<{
    detail: {
        asset: {
            id: number;
            name: string;
            owner_name: string;
            asset_type_label: string;
            institution: string | null;
            currency: string;
            liquidity_label: string;
            interest_rate_bps: number | null;
            notes: string | null;
            is_active: boolean;
        };
        current_value_cents: number;
        financial_year: {
            opening_cents: number;
            closing_cents: number;
            contributions_cents: number;
            withdrawals_cents: number;
            investment_movement_cents: number;
            label: string;
            starts_on: string;
            ends_on: string;
            opening_as_of: string | null;
            used_synthetic_opening: boolean;
        };
        valuations: Array<{
            id: number;
            valued_on: string;
            value_cents: number;
            change_cents: number | null;
            change_percent: number | null;
            year_label: string;
            notes: string | null;
            source: string;
        }>;
        transactions: Array<{
            id: number;
            type: string;
            type_label: string;
            occurred_on: string;
            amount_cents: number;
            signed_amount_cents: number;
            year_label: string;
            notes: string | null;
        }>;
        chart: Array<{
            date: string;
            label: string;
            value_cents: number;
            change_cents: number | null;
            change_percent: number | null;
        }>;
        yearly_summaries: Array<{
            label: string;
            starts_on: string;
            ends_on: string;
            as_of: string;
            is_current: boolean;
            opening_cents: number;
            closing_cents: number;
            contributions_cents: number;
            withdrawals_cents: number;
            investment_movement_cents: number;
            opening_as_of: string | null;
            used_synthetic_opening: boolean;
        }>;
    };
    transaction_types: Option[];
    can_manage: boolean;
}>();

const toast = useToast();
const currency = computed(() => props.detail.asset.currency || 'ZAR');
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, currency.value);
const formatSigned = (cents: number) => {
    const n = Number(cents) || 0;
    const formatted = formatCents(Math.abs(n));
    if (n > 0) return `+${formatted}`;
    if (n < 0) return `−${formatted}`;
    return formatted;
};

const changeClass = (cents: number | null | undefined) => {
    const n = Number(cents) || 0;
    if (n > 0) return 'text-emerald-700';
    if (n < 0) return 'text-rose-700';
    return 'text-slate-500';
};

const formatChangePercent = (percent: number | null | undefined) => {
    if (percent == null || !Number.isFinite(percent)) return null;
    const abs = Math.abs(percent).toFixed(1);
    if (percent > 0) return `+${abs}%`;
    if (percent < 0) return `−${abs}%`;
    return '0.0%';
};

const interestPercent = computed(() =>
    props.detail.asset.interest_rate_bps != null
        ? `${(props.detail.asset.interest_rate_bps / 100).toFixed(2)}%`
        : '—',
);

const chartOptions = computed(() => ({
    tooltip: {
        trigger: 'axis',
        formatter: (params: Array<{ dataIndex: number; marker: string; seriesName: string; value: number }>) => {
            const point = params[0];
            if (!point) return '';
            const row = props.detail.chart[point.dataIndex];
            if (!row) return '';

            const lines = [
                row.label,
                `${point.marker} Value: ${formatCents(row.value_cents)}`,
            ];

            if (row.change_cents != null) {
                const percent = formatChangePercent(row.change_percent);
                lines.push(
                    `Change: ${formatSigned(row.change_cents)}${percent ? ` (${percent})` : ''}`,
                );
            }

            return lines.join('<br/>');
        },
    },
    grid: { left: 48, right: 16, top: 24, bottom: 32 },
    xAxis: {
        type: 'category',
        data: props.detail.chart.map((p) => p.label),
        axisLabel: { color: '#64748b', fontSize: 11 },
    },
    yAxis: {
        type: 'value',
        axisLabel: {
            color: '#64748b',
            fontSize: 11,
            formatter: (v: number) => useFormatCurrency(v / 100, currency.value),
        },
        splitLine: { lineStyle: { color: '#e2e8f0' } },
    },
    series: [
        {
            name: 'Value',
            type: 'line',
            smooth: true,
            data: props.detail.chart.map((p) => ({
                value: p.value_cents,
                itemStyle: {
                    color:
                        p.change_cents == null
                            ? '#0f766e'
                            : p.change_cents > 0
                              ? '#047857'
                              : p.change_cents < 0
                                ? '#be123c'
                                : '#64748b',
                },
            })),
            areaStyle: { opacity: 0.08, color: '#0f766e' },
            lineStyle: { width: 2, color: '#0f766e' },
            symbol: 'circle',
            symbolSize: 7,
        },
    ],
}));

const today = () => new Date().toISOString().slice(0, 10);

const showValuationModal = ref(false);
const showTransactionModal = ref(false);
const showNoteModal = ref(false);
const noteModalTitle = ref('Note');
const noteModalBody = ref('');
const savingValuation = ref(false);
const savingTransaction = ref(false);
const editingValuationId = ref<number | null>(null);
const editingTransactionId = ref<number | null>(null);

const valuationForm = ref({
    valued_on: today(),
    value: '',
    notes: '',
});

const transactionForm = ref({
    type: 'contribution',
    occurred_on: today(),
    amount: '',
    notes: '',
});

const transactionTypeOptions = computed(() =>
    props.transaction_types.map((o) => ({ label: o.label, value: o.value })),
);

const valuationModalTitle = computed(() =>
    editingValuationId.value ? 'Edit valuation' : 'Add valuation',
);

const transactionModalTitle = computed(() =>
    editingTransactionId.value ? 'Edit transaction' : 'Add transaction',
);

const resetValuationForm = () => {
    editingValuationId.value = null;
    valuationForm.value = { valued_on: today(), value: '', notes: '' };
};

const resetTransactionForm = () => {
    editingTransactionId.value = null;
    transactionForm.value = { type: 'contribution', occurred_on: today(), amount: '', notes: '' };
};

const openValuationModal = () => {
    resetValuationForm();
    showValuationModal.value = true;
};

const openEditValuationModal = (row: (typeof props.detail.valuations)[number]) => {
    editingValuationId.value = row.id;
    valuationForm.value = {
        valued_on: row.valued_on,
        value: (row.value_cents / 100).toFixed(2),
        notes: row.notes ?? '',
    };
    showValuationModal.value = true;
};

const closeValuationModal = () => {
    if (savingValuation.value) return;
    showValuationModal.value = false;
    resetValuationForm();
};

const openTransactionModal = () => {
    resetTransactionForm();
    showTransactionModal.value = true;
};

const openEditTransactionModal = (row: (typeof props.detail.transactions)[number]) => {
    editingTransactionId.value = row.id;
    transactionForm.value = {
        type: row.type,
        occurred_on: row.occurred_on,
        amount: (row.amount_cents / 100).toFixed(2),
        notes: row.notes ?? '',
    };
    showTransactionModal.value = true;
};

const closeTransactionModal = () => {
    if (savingTransaction.value) return;
    showTransactionModal.value = false;
    resetTransactionForm();
};

const submitValuation = () => {
    if (savingValuation.value) return;

    const value = Number(valuationForm.value.value);
    if (!valuationForm.value.valued_on || !Number.isFinite(value) || value < 0 || valuationForm.value.value === '') {
        toast.error('Enter a date and a non-negative value.');
        return;
    }

    const payload = {
        valued_on: valuationForm.value.valued_on,
        value_cents: Math.round(value * 100),
        notes: valuationForm.value.notes.trim() || null,
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(editingValuationId.value ? 'Valuation updated.' : 'Valuation saved.');
            showValuationModal.value = false;
            resetValuationForm();
        },
        onError: () => {
            toast.error('Could not save valuation.');
        },
        onFinish: () => {
            savingValuation.value = false;
        },
    };

    savingValuation.value = true;
    if (editingValuationId.value) {
        router.put(
            route('wealth.assets.valuations.update', [props.detail.asset.id, editingValuationId.value]),
            payload,
            options,
        );
        return;
    }

    router.post(
        route('wealth.assets.valuations.store', props.detail.asset.id),
        payload,
        options,
    );
};

const submitTransaction = () => {
    if (savingTransaction.value) return;

    const amount = Number(transactionForm.value.amount);
    if (!transactionForm.value.occurred_on || !Number.isFinite(amount) || transactionForm.value.amount === '') {
        toast.error('Enter a date and an amount.');
        return;
    }

    if (transactionForm.value.type !== 'adjustment' && amount < 0) {
        toast.error('Amount must be zero or positive for this type.');
        return;
    }

    const payload = {
        type: transactionForm.value.type,
        occurred_on: transactionForm.value.occurred_on,
        amount_cents: Math.round(amount * 100),
        notes: transactionForm.value.notes.trim() || null,
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(editingTransactionId.value ? 'Transaction updated.' : 'Transaction recorded.');
            showTransactionModal.value = false;
            resetTransactionForm();
        },
        onError: () => {
            toast.error('Could not save transaction.');
        },
        onFinish: () => {
            savingTransaction.value = false;
        },
    };

    savingTransaction.value = true;
    if (editingTransactionId.value) {
        router.put(
            route('wealth.assets.transactions.update', [props.detail.asset.id, editingTransactionId.value]),
            payload,
            options,
        );
        return;
    }

    router.post(
        route('wealth.assets.transactions.store', props.detail.asset.id),
        payload,
        options,
    );
};

const deleteValuation = (id: number) => {
    router.delete(route('wealth.assets.valuations.destroy', [props.detail.asset.id, id]), {
        preserveScroll: true,
        onSuccess: () => toast.success('Valuation removed.'),
    });
};

const deleteTransaction = (id: number) => {
    router.delete(route('wealth.assets.transactions.destroy', [props.detail.asset.id, id]), {
        preserveScroll: true,
        onSuccess: () => toast.success('Transaction removed.'),
    });
};

const openNoteModal = (title: string, notes: string) => {
    noteModalTitle.value = title;
    noteModalBody.value = notes;
    showNoteModal.value = true;
};

const closeNoteModal = () => {
    showNoteModal.value = false;
    noteModalBody.value = '';
};

const manageRowActions: RowActionItem[] = [
    { id: 'edit', label: 'Edit' },
    { id: 'delete', label: 'Remove' },
];

const onValuationAction = (
    row: (typeof props.detail.valuations)[number],
    actionId: string,
) => {
    if (actionId === 'edit') {
        openEditValuationModal(row);
        return;
    }
    if (actionId === 'delete') {
        deleteValuation(row.id);
    }
};

const onTransactionAction = (
    row: (typeof props.detail.transactions)[number],
    actionId: string,
) => {
    if (actionId === 'edit') {
        openEditTransactionModal(row);
        return;
    }
    if (actionId === 'delete') {
        deleteTransaction(row.id);
    }
};

const valuationColumns: TableColumn[] = [
    { key: 'date', label: 'Date' },
    { key: 'value', label: 'Value', align: 'right' },
    { key: 'change', label: 'Change', align: 'right' },
    { key: 'notes', label: '', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];

const txColumns: TableColumn[] = [
    { key: 'date', label: 'Date' },
    { key: 'type', label: 'Type' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'notes', label: '', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];

const yearColumns: TableColumn[] = [
    { key: 'year', label: 'Financial year' },
    { key: 'opening', label: 'Opening', align: 'right' },
    { key: 'closing', label: 'Closing', align: 'right' },
    { key: 'contributions', label: 'Contributions', align: 'right' },
    { key: 'withdrawals', label: 'Withdrawals', align: 'right' },
    { key: 'movement', label: 'Movement', align: 'right' },
];

type YearGroup<T> = {
    label: string;
    is_current: boolean;
    rows: T[];
};

const groupByYear = <T extends { year_label: string }>(
    rows: T[],
    currentLabel: string,
): YearGroup<T>[] => {
    const groups = new Map<string, YearGroup<T>>();

    for (const row of rows) {
        const existing = groups.get(row.year_label);
        if (existing) {
            existing.rows.push(row);
            continue;
        }

        groups.set(row.year_label, {
            label: row.year_label,
            is_current: row.year_label === currentLabel,
            rows: [row],
        });
    }

    return Array.from(groups.values());
};

const valuationYearGroups = computed(() =>
    groupByYear(props.detail.valuations, props.detail.financial_year.label),
);

const transactionYearGroups = computed(() =>
    groupByYear(props.detail.transactions, props.detail.financial_year.label),
);

const expandedValuationYears = ref<string[]>([props.detail.financial_year.label]);
const expandedTransactionYears = ref<string[]>([props.detail.financial_year.label]);

const isValuationYearExpanded = (label: string) =>
    expandedValuationYears.value.includes(label);

const isTransactionYearExpanded = (label: string) =>
    expandedTransactionYears.value.includes(label);

const toggleValuationYear = (label: string) => {
    if (expandedValuationYears.value.includes(label)) {
        expandedValuationYears.value = expandedValuationYears.value.filter((year) => year !== label);
        return;
    }
    expandedValuationYears.value = [...expandedValuationYears.value, label];
};

const toggleTransactionYear = (label: string) => {
    if (expandedTransactionYears.value.includes(label)) {
        expandedTransactionYears.value = expandedTransactionYears.value.filter((year) => year !== label);
        return;
    }
    expandedTransactionYears.value = [...expandedTransactionYears.value, label];
};
</script>

<template>
    <AppLayout
        :title="detail.asset.name"
        :breadcrumbs="[
            { label: 'Wealth', href: route('wealth.index') },
            { label: detail.asset.name },
        ]"
    >
        <PageHeader
            :title="detail.asset.name"
            :subtitle="`${detail.asset.owner_name} · ${detail.asset.asset_type_label}${detail.asset.institution ? ` · ${detail.asset.institution}` : ''}`"
        >
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('wealth.index')">
                        <AppButton variant="secondary" size="sm">Back to Wealth</AppButton>
                    </Link>
                    <Link v-if="can_manage" :href="route('wealth.assets.edit', detail.asset.id)">
                        <AppButton variant="secondary" size="sm">Edit</AppButton>
                    </Link>
                </div>
            </template>
        </PageHeader>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Current value</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.current_value_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Opening · FY {{ detail.financial_year.label }}
                </p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.opening_cents) }}</p>
                <p v-if="detail.financial_year.opening_as_of" class="mt-1 text-xs text-slate-500">
                    As of {{ detail.financial_year.opening_as_of }}
                    <span v-if="detail.financial_year.used_synthetic_opening"> · first valuation this FY</span>
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Contributions · FY {{ detail.financial_year.label }}
                </p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.contributions_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Withdrawals · FY {{ detail.financial_year.label }}
                </p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.withdrawals_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Movement · FY {{ detail.financial_year.label }}
                </p>
                <p
                    class="mt-2 text-xl font-semibold tabular-nums"
                    :class="changeClass(detail.financial_year.investment_movement_cents)"
                >
                    {{ formatSigned(detail.financial_year.investment_movement_cents) }}
                </p>
            </AppCard>
        </div>

        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
            <span>Liquidity: <strong class="text-slate-900">{{ detail.asset.liquidity_label }}</strong></span>
            <span>Interest rate: <strong class="text-slate-900">{{ interestPercent }}</strong></span>
            <span>
                FY {{ detail.financial_year.label }}:
                <strong class="text-slate-900">
                    {{ detail.financial_year.starts_on }} → {{ detail.financial_year.ends_on }}
                </strong>
            </span>
        </div>
        <p v-if="detail.asset.notes" class="mt-2 text-sm text-slate-600">{{ detail.asset.notes }}</p>

        <AppCard class="mt-6">
            <h3 class="mb-3 text-base font-semibold text-slate-900">Value over time</h3>
            <div v-if="detail.chart.length" class="h-56 w-full md:h-72">
                <VChart class="h-full w-full" :option="chartOptions" autoresize />
            </div>
            <p v-else class="text-sm text-slate-500">Add valuations to plot history.</p>
        </AppCard>

        <AppCard class="mt-6">
            <h3 class="mb-1 text-base font-semibold text-slate-900">Yearly summary</h3>
            <p class="mb-3 text-sm text-slate-500">
                One row per financial year. Opening uses the previous FY’s last valuation when present;
                otherwise the first valuation in that year. Movement is closing − opening − contributions + withdrawals for that year only.
            </p>
            <AppTable
                v-if="detail.yearly_summaries.length"
                :columns="yearColumns"
                :show-pagination="false"
                dense
                table-class="text-sm"
                embedded
            >
                <tr v-for="row in detail.yearly_summaries" :key="row.starts_on">
                    <td class="whitespace-nowrap px-3 py-2">
                        <span class="font-medium text-slate-900">{{ row.label }}</span>
                        <span v-if="row.is_current" class="ml-2 text-xs font-medium text-brand-700">Current</span>
                        <div class="text-xs text-slate-500">
                            {{ row.starts_on }} → {{ row.is_current ? row.as_of : row.ends_on }}
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.opening_cents) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.closing_cents) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.contributions_cents) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.withdrawals_cents) }}</td>
                    <td
                        class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-medium"
                        :class="changeClass(row.investment_movement_cents)"
                    >
                        {{ formatSigned(row.investment_movement_cents) }}
                    </td>
                </tr>
            </AppTable>
            <p v-else class="text-sm text-slate-500">Add valuations or transactions to build yearly summaries.</p>
        </AppCard>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <AppCard>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Valuations</h3>
                        <p class="text-xs text-slate-500">
                            Grouped by financial year. Change is vs the previous valuation in the same year.
                        </p>
                    </div>
                    <AppButton
                        v-if="can_manage"
                        variant="secondary"
                        size="sm"
                        @click="openValuationModal"
                    >
                        Add valuation
                    </AppButton>
                </div>
                <div v-if="valuationYearGroups.length" class="space-y-3">
                    <div
                        v-for="group in valuationYearGroups"
                        :key="`val-${group.label}`"
                        class="overflow-hidden rounded-lg border border-slate-200"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 bg-slate-50 px-3 py-2 text-left hover:bg-slate-100"
                            @click="toggleValuationYear(group.label)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ChevronDown
                                    class="h-4 w-4 shrink-0 text-slate-500 transition-transform"
                                    :class="{ '-rotate-90': !isValuationYearExpanded(group.label) }"
                                />
                                <span class="truncate text-sm font-medium text-slate-900">
                                    FY {{ group.label }}
                                </span>
                                <span
                                    v-if="group.is_current"
                                    class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-700"
                                >
                                    Current
                                </span>
                            </span>
                            <span class="shrink-0 text-xs text-slate-500">
                                {{ group.rows.length }} {{ group.rows.length === 1 ? 'entry' : 'entries' }}
                            </span>
                        </button>
                        <div v-show="isValuationYearExpanded(group.label)">
                            <AppTable :columns="valuationColumns" :show-pagination="false" dense table-class="text-sm" embedded>
                                <tr v-for="row in group.rows" :key="row.id">
                                    <td class="whitespace-nowrap px-3 py-2">{{ row.valued_on }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.value_cents) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                        <template v-if="row.change_cents == null">
                                            <span class="text-slate-400">—</span>
                                        </template>
                                        <span v-else :class="changeClass(row.change_cents)">
                                            {{ formatSigned(row.change_cents) }}
                                            <span
                                                v-if="formatChangePercent(row.change_percent)"
                                                class="ml-1 text-[11px] opacity-80"
                                            >
                                                {{ formatChangePercent(row.change_percent) }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="w-8 px-1 py-2 text-right">
                                        <button
                                            v-if="row.notes"
                                            type="button"
                                            class="inline-flex rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                            aria-label="View note"
                                            @click.stop="openNoteModal(`Valuation · ${row.valued_on}`, row.notes)"
                                        >
                                            <StickyNote class="h-3.5 w-3.5" aria-hidden="true" />
                                        </button>
                                    </td>
                                    <td class="w-10 px-1 py-2 text-right" @click.stop>
                                        <div v-if="can_manage" class="flex justify-end">
                                            <InvoiceRowActionsMenu
                                                :actions="manageRowActions"
                                                :aria-label="`Actions for valuation ${row.valued_on}`"
                                                @select="(id) => onValuationAction(row, id)"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </AppTable>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-slate-500">No valuations yet.</p>
            </AppCard>

            <AppCard>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Transactions</h3>
                        <p class="text-xs text-slate-500">Grouped by financial year. Older years stay collapsed.</p>
                    </div>
                    <AppButton
                        v-if="can_manage"
                        variant="secondary"
                        size="sm"
                        @click="openTransactionModal"
                    >
                        Add transaction
                    </AppButton>
                </div>
                <div v-if="transactionYearGroups.length" class="space-y-3">
                    <div
                        v-for="group in transactionYearGroups"
                        :key="`tx-${group.label}`"
                        class="overflow-hidden rounded-lg border border-slate-200"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 bg-slate-50 px-3 py-2 text-left hover:bg-slate-100"
                            @click="toggleTransactionYear(group.label)"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ChevronDown
                                    class="h-4 w-4 shrink-0 text-slate-500 transition-transform"
                                    :class="{ '-rotate-90': !isTransactionYearExpanded(group.label) }"
                                />
                                <span class="truncate text-sm font-medium text-slate-900">
                                    FY {{ group.label }}
                                </span>
                                <span
                                    v-if="group.is_current"
                                    class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-700"
                                >
                                    Current
                                </span>
                            </span>
                            <span class="shrink-0 text-xs text-slate-500">
                                {{ group.rows.length }} {{ group.rows.length === 1 ? 'entry' : 'entries' }}
                            </span>
                        </button>
                        <div v-show="isTransactionYearExpanded(group.label)">
                            <AppTable :columns="txColumns" :show-pagination="false" dense table-class="text-sm" embedded>
                                <tr v-for="row in group.rows" :key="row.id">
                                    <td class="whitespace-nowrap px-3 py-2">{{ row.occurred_on }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ row.type_label }}</td>
                                    <td
                                        class="whitespace-nowrap px-3 py-2 text-right tabular-nums"
                                        :class="changeClass(row.signed_amount_cents)"
                                    >
                                        {{ formatSigned(row.signed_amount_cents) }}
                                    </td>
                                    <td class="w-8 px-1 py-2 text-right">
                                        <button
                                            v-if="row.notes"
                                            type="button"
                                            class="inline-flex rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                            aria-label="View note"
                                            @click.stop="openNoteModal(`${row.type_label} · ${row.occurred_on}`, row.notes)"
                                        >
                                            <StickyNote class="h-3.5 w-3.5" aria-hidden="true" />
                                        </button>
                                    </td>
                                    <td class="w-10 px-1 py-2 text-right" @click.stop>
                                        <div v-if="can_manage" class="flex justify-end">
                                            <InvoiceRowActionsMenu
                                                :actions="manageRowActions"
                                                :aria-label="`Actions for ${row.type_label} on ${row.occurred_on}`"
                                                @select="(id) => onTransactionAction(row, id)"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </AppTable>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-slate-500">No transactions yet.</p>
            </AppCard>
        </div>

        <DialogModal :show="showValuationModal" max-width="lg" @close="closeValuationModal">
            <template #title>
                {{ valuationModalTitle }}
            </template>
            <template #content>
                <form id="wealth-valuation-form" class="space-y-4 text-left text-slate-900" @submit.prevent="submitValuation">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Date</label>
                            <AppInput v-model="valuationForm.valued_on" type="date" required />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Value ({{ currency }})
                            </label>
                            <AppInput
                                v-model="valuationForm.value"
                                type="number"
                                step="0.01"
                                min="0"
                                inputmode="decimal"
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                            <textarea
                                v-model="valuationForm.notes"
                                rows="3"
                                class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                placeholder="Optional"
                            />
                        </div>
                    </div>
                </form>
            </template>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="savingValuation" @click="closeValuationModal">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="primary"
                        :loading="savingValuation"
                        :disabled="savingValuation"
                        @click="submitValuation"
                    >
                        {{ editingValuationId ? 'Update valuation' : 'Save valuation' }}
                    </AppButton>
                </div>
            </template>
        </DialogModal>

        <DialogModal :show="showTransactionModal" max-width="lg" @close="closeTransactionModal">
            <template #title>
                {{ transactionModalTitle }}
            </template>
            <template #content>
                <form id="wealth-transaction-form" class="space-y-4 text-left text-slate-900" @submit.prevent="submitTransaction">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                            <AppSelect
                                v-model="transactionForm.type"
                                :options="transactionTypeOptions"
                                placeholder="Select type"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Date</label>
                            <AppInput v-model="transactionForm.occurred_on" type="date" required />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Amount ({{ currency }})
                            </label>
                            <AppInput
                                v-model="transactionForm.amount"
                                type="number"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                            <textarea
                                v-model="transactionForm.notes"
                                rows="3"
                                class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                placeholder="Optional"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">
                        Contributions and withdrawals affect investment movement. Interest, dividends, and fees are recorded for history and are already reflected in valuations.
                    </p>
                </form>
            </template>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="savingTransaction" @click="closeTransactionModal">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="primary"
                        :loading="savingTransaction"
                        :disabled="savingTransaction"
                        @click="submitTransaction"
                    >
                        {{ editingTransactionId ? 'Update transaction' : 'Save transaction' }}
                    </AppButton>
                </div>
            </template>
        </DialogModal>

        <DialogModal :show="showNoteModal" max-width="md" @close="closeNoteModal">
            <template #title>
                {{ noteModalTitle }}
            </template>
            <template #content>
                <p class="whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ noteModalBody }}</p>
            </template>
            <template #footer>
                <AppButton variant="secondary" @click="closeNoteModal">
                    Close
                </AppButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
