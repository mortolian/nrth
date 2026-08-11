<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTable from '@/Components/AppTable.vue';
import type { TableColumn } from '@/Components/AppTable.vue';
import DialogModal from '@/Components/DialogModal.vue';
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
            contributions_cents: number;
            withdrawals_cents: number;
            investment_movement_cents: number;
            label: string;
        };
        valuations: Array<{ id: number; valued_on: string; value_cents: number; notes: string | null; source: string }>;
        transactions: Array<{ id: number; type: string; type_label: string; occurred_on: string; amount_cents: number; notes: string | null }>;
        chart: Array<{ date: string; label: string; value_cents: number }>;
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

const interestPercent = computed(() =>
    props.detail.asset.interest_rate_bps != null
        ? `${(props.detail.asset.interest_rate_bps / 100).toFixed(2)}%`
        : '—',
);

const chartOptions = computed(() => ({
    tooltip: { trigger: 'axis' },
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
            type: 'line',
            smooth: true,
            data: props.detail.chart.map((p) => p.value_cents),
            areaStyle: { opacity: 0.08 },
            lineStyle: { width: 2, color: '#0f766e' },
            itemStyle: { color: '#0f766e' },
        },
    ],
}));

const today = () => new Date().toISOString().slice(0, 10);

const showValuationModal = ref(false);
const showTransactionModal = ref(false);
const savingValuation = ref(false);
const savingTransaction = ref(false);

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

const resetValuationForm = () => {
    valuationForm.value = { valued_on: today(), value: '', notes: '' };
};

const resetTransactionForm = () => {
    transactionForm.value = { type: 'contribution', occurred_on: today(), amount: '', notes: '' };
};

const openValuationModal = () => {
    resetValuationForm();
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

const closeTransactionModal = () => {
    if (savingTransaction.value) return;
    showTransactionModal.value = false;
    resetTransactionForm();
};

watch(showValuationModal, (open) => {
    if (open) resetValuationForm();
});

watch(showTransactionModal, (open) => {
    if (open) resetTransactionForm();
});

const submitValuation = () => {
    if (savingValuation.value) return;

    const value = Number(valuationForm.value.value);
    if (!valuationForm.value.valued_on || !Number.isFinite(value) || value < 0 || valuationForm.value.value === '') {
        toast.error('Enter a date and a non-negative value.');
        return;
    }

    savingValuation.value = true;
    router.post(
        route('wealth.assets.valuations.store', props.detail.asset.id),
        {
            valued_on: valuationForm.value.valued_on,
            value_cents: Math.round(value * 100),
            notes: valuationForm.value.notes.trim() || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Valuation saved.');
                showValuationModal.value = false;
                resetValuationForm();
            },
            onError: () => {
                toast.error('Could not save valuation.');
            },
            onFinish: () => {
                savingValuation.value = false;
            },
        },
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

    savingTransaction.value = true;
    router.post(
        route('wealth.assets.transactions.store', props.detail.asset.id),
        {
            type: transactionForm.value.type,
            occurred_on: transactionForm.value.occurred_on,
            amount_cents: Math.round(amount * 100),
            notes: transactionForm.value.notes.trim() || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Transaction recorded.');
                showTransactionModal.value = false;
                resetTransactionForm();
            },
            onError: () => {
                toast.error('Could not save transaction.');
            },
            onFinish: () => {
                savingTransaction.value = false;
            },
        },
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

const valuationColumns: TableColumn[] = [
    { key: 'date', label: 'Date' },
    { key: 'value', label: 'Value', align: 'right' },
    { key: 'notes', label: 'Notes' },
    { key: 'actions', label: '', align: 'right' },
];

const txColumns: TableColumn[] = [
    { key: 'date', label: 'Date' },
    { key: 'type', label: 'Type' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'notes', label: 'Notes' },
    { key: 'actions', label: '', align: 'right' },
];
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
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">FY opening</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.opening_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">FY contributions</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.contributions_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">FY withdrawals</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatCents(detail.financial_year.withdrawals_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">FY investment movement</p>
                <p class="mt-2 text-xl font-semibold tabular-nums">{{ formatSigned(detail.financial_year.investment_movement_cents) }}</p>
            </AppCard>
        </div>

        <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-600">
            <span>Liquidity: <strong class="text-slate-900">{{ detail.asset.liquidity_label }}</strong></span>
            <span>Interest rate: <strong class="text-slate-900">{{ interestPercent }}</strong></span>
            <span>FY {{ detail.financial_year.label }}</span>
        </div>
        <p v-if="detail.asset.notes" class="mt-2 text-sm text-slate-600">{{ detail.asset.notes }}</p>

        <AppCard class="mt-6">
            <h3 class="mb-3 text-base font-semibold text-slate-900">Value over time</h3>
            <div v-if="detail.chart.length" class="h-56 w-full md:h-72">
                <VChart class="h-full w-full" :option="chartOptions" autoresize />
            </div>
            <p v-else class="text-sm text-slate-500">Add valuations to plot history.</p>
        </AppCard>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <AppCard>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-slate-900">Valuations</h3>
                    <AppButton
                        v-if="can_manage"
                        variant="secondary"
                        size="sm"
                        @click="openValuationModal"
                    >
                        Add valuation
                    </AppButton>
                </div>
                <AppTable :columns="valuationColumns" :show-pagination="false" dense table-class="text-sm" embedded>
                    <tr v-for="row in detail.valuations" :key="row.id">
                        <td class="whitespace-nowrap px-3 py-2">{{ row.valued_on }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.value_cents) }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ row.notes || '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <button
                                v-if="can_manage"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click.stop="deleteValuation(row.id)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </AppTable>
                <p v-if="!detail.valuations.length" class="mt-2 text-sm text-slate-500">No valuations yet.</p>
            </AppCard>

            <AppCard>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-slate-900">Transactions</h3>
                    <AppButton
                        v-if="can_manage"
                        variant="secondary"
                        size="sm"
                        @click="openTransactionModal"
                    >
                        Add transaction
                    </AppButton>
                </div>
                <AppTable :columns="txColumns" :show-pagination="false" dense table-class="text-sm" embedded>
                    <tr v-for="row in detail.transactions" :key="row.id">
                        <td class="whitespace-nowrap px-3 py-2">{{ row.occurred_on }}</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ row.type_label }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.amount_cents) }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ row.notes || '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <button
                                v-if="can_manage"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click.stop="deleteTransaction(row.id)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </AppTable>
                <p v-if="!detail.transactions.length" class="mt-2 text-sm text-slate-500">No transactions yet.</p>
            </AppCard>
        </div>

        <DialogModal :show="showValuationModal" max-width="lg" @close="closeValuationModal">
            <template #title>
                Add valuation
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
                            <AppInput v-model="valuationForm.notes" placeholder="Optional" />
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
                        Save valuation
                    </AppButton>
                </div>
            </template>
        </DialogModal>

        <DialogModal :show="showTransactionModal" max-width="lg" @close="closeTransactionModal">
            <template #title>
                Add transaction
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
                            <AppInput v-model="transactionForm.notes" placeholder="Optional" />
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
                        Save transaction
                    </AppButton>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>
