<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const reportsTabs = useReportsTabs();

type MonthRow = { key: string; label: string; count: number; total_cents: number };

type TransactionRow = {
    id: number;
    date: string | null;
    description: string;
    reference: string | null;
    account: string | null;
    direction: 'in' | 'out';
    signed_cents: number;
};

type FilterResult = {
    id: number;
    alias: string;
    match: string;
    count: number;
    total_cents: number;
    truncated: boolean;
    months: MonthRow[];
    transactions: TransactionRow[];
};

const props = defineProps<{
    report: { id: number; name: string };
    result: {
        period: { from: string; to: string };
        presentation: { view: 'summary' | 'detailed'; breakdown: 'period' | 'monthly' };
        filters: FilterResult[];
    };
    currency: string;
}>();

const page = usePage<{ team_permissions?: string[] }>();
const canManage = computed(() => (page.props.team_permissions ?? []).includes('reports.manage'));
const canExport = computed(() => (page.props.team_permissions ?? []).includes('reports.export'));

const state = ref({
    from: props.result.period.from,
    to: props.result.period.to,
    view: props.result.presentation.view,
    breakdown: props.result.presentation.breakdown,
});

watch(
    () => props.result,
    (result) => {
        state.value.from = result.period.from;
        state.value.to = result.period.to;
        state.value.view = result.presentation.view;
        state.value.breakdown = result.presentation.breakdown;
    },
);

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, props.currency || 'ZAR');

const pdfHref = computed(() => route('reports.statement-filters.pdf', {
    statementFilterReport: props.report.id,
    from: props.result.period.from,
    to: props.result.period.to,
    view: props.result.presentation.view,
    breakdown: props.result.presentation.breakdown,
}));

const apply = () => {
    router.get(
        route('reports.statement-filters.show', props.report.id),
        { ...state.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="statement-filters"
        :tabs="reportsTabs"
        :document-title="report.name"
        :subtitle="`${report.name}. Debits increase each total. Credits, such as refunds, reduce it.`"
    >
        <template #actions>
            <AppButton v-if="canManage" as="a" variant="secondary" :href="route('reports.statement-filters.edit', report.id)">Edit filters</AppButton>
            <AppButton v-if="canExport" as="a" variant="secondary" :href="pdfHref">Export PDF</AppButton>
        </template>

        <AppCard>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,11rem)_minmax(0,11rem)_minmax(0,12rem)_minmax(0,12rem)_auto] xl:items-end">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="state.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="state.to" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Breakdown</label>
                    <AppSelect
                        :model-value="state.breakdown"
                        :options="[
                            { label: 'Whole period', value: 'period' },
                            { label: 'By month', value: 'monthly' },
                        ]"
                        @update:model-value="state.breakdown = $event === 'monthly' ? 'monthly' : 'period'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Report</label>
                    <AppSelect
                        :model-value="state.view"
                        :options="[
                            { label: 'Totals', value: 'summary' },
                            { label: 'With transactions', value: 'detailed' },
                        ]"
                        @update:model-value="state.view = $event === 'detailed' ? 'detailed' : 'summary'"
                    />
                </div>
                <div>
                    <AppButton variant="secondary" @click="apply">Apply</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                dense
                embedded
                table-class="text-sm table-fixed"
                :show-pagination="false"
                :columns="[
                    { key: 'alias', label: 'Alias' },
                    { key: 'match', label: 'Filter' },
                    { key: 'count', label: 'Transactions', align: 'right', widthClass: 'w-32' },
                    { key: 'total', label: 'Total', align: 'right', widthClass: 'w-40' },
                ]"
            >
                <tr v-for="filter in result.filters" :key="filter.id">
                    <td class="px-3 py-2 font-medium text-slate-900">{{ filter.alias }}</td>
                    <td class="px-3 py-2 text-slate-900">{{ filter.match }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ filter.count }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-medium text-slate-900">{{ formatCents(filter.total_cents) }}</td>
                </tr>
            </AppTable>
        </AppCard>

        <template v-for="filter in result.filters" :key="`detail-${filter.id}`">
            <AppCard v-if="result.presentation.breakdown === 'monthly' && filter.months.length > 0" class="mt-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-900">{{ filter.alias }} by month</h2>
                <AppTable
                    dense
                    embedded
                    table-class="text-sm table-fixed"
                    :show-pagination="false"
                    :columns="[
                        { key: 'month', label: 'Month' },
                        { key: 'count', label: 'Transactions', align: 'right', widthClass: 'w-32' },
                        { key: 'total', label: 'Total', align: 'right', widthClass: 'w-40' },
                    ]"
                >
                    <tr v-for="month in filter.months" :key="month.key">
                        <td class="px-3 py-2 text-slate-900">{{ month.label }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ month.count }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">{{ formatCents(month.total_cents) }}</td>
                    </tr>
                </AppTable>
            </AppCard>

            <AppCard v-if="result.presentation.view === 'detailed'" class="mt-5">
                <h2 class="mb-1 text-[1.3em] font-semibold leading-tight text-slate-900">{{ filter.alias }}</h2>
                <p class="mb-3 text-xs text-slate-500">Contains “{{ filter.match }}”</p>
                <p v-if="filter.count === 0" class="text-sm text-slate-500">No matching statement lines in this period.</p>
                <template v-else>
                    <p v-if="filter.truncated" class="mb-3 text-xs text-slate-500">
                        Showing the first 400 of {{ filter.count }} lines. The total above includes every match.
                    </p>
                    <AppTable
                        dense
                        embedded
                        max-body-class="max-h-96 overflow-y-auto"
                        table-class="text-sm table-fixed"
                        :show-pagination="false"
                        :columns="[
                            { key: 'date', label: 'Date', widthClass: 'w-28' },
                            { key: 'description', label: 'Description' },
                            { key: 'reference', label: 'Reference', widthClass: 'w-44' },
                            { key: 'account', label: 'Account', widthClass: 'w-36' },
                            { key: 'amount', label: 'Amount', align: 'right', widthClass: 'w-40' },
                        ]"
                    >
                        <tr v-for="line in filter.transactions" :key="line.id">
                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ line.date }}</td>
                            <td class="px-3 py-2 text-slate-900">{{ line.description }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ line.reference || '—' }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ line.account || '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">{{ formatCents(line.signed_cents) }}</td>
                        </tr>
                    </AppTable>
                </template>
            </AppCard>
        </template>
    </FeatureShell>
</template>
