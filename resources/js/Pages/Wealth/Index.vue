<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
import { PiggyBank } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTable from '@/Components/AppTable.vue';
import type { TableColumn } from '@/Components/AppTable.vue';
import DialogModal from '@/Components/DialogModal.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import HelpTip from '@/Components/HelpTip.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';
import {
    filterByChartRange,
    toIndexedSeries,
    wealthChartGrid,
    wealthIndexedAxis,
    wealthValueAxis,
    WEALTH_CHART_RANGES,
    type WealthChartRange,
} from '@/Composables/useWealthChartAxis';

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent]);

type ChartMode = 'value' | 'indexed';
const chartMode = ref<ChartMode>('value');
const chartRange = ref<WealthChartRange>('1Y');

type Portfolio = {
    id: number;
    name: string;
    base_currency: string;
    financial_year_start_month: number;
    is_default: boolean;
    notes: string | null;
    is_archived: boolean;
    archived_at: string | null;
};

type Overview = {
    total_cents: number;
    accessible_cents: number;
    restricted_cents: number;
    currency: string;
    month: { investment_movement_cents: number; contributions_cents: number; withdrawals_cents: number };
    financial_year: {
        investment_movement_cents: number;
        contributions_cents: number;
        withdrawals_cents: number;
        label: string;
    };
    assets: Array<{
        id: number;
        name: string;
        owner_name: string;
        asset_type_label: string;
        institution: string | null;
        current_value_cents: number;
        period_movement_cents: number;
        financial_year_movement_cents: number;
        is_archived: boolean;
        archived_at: string | null;
    }>;
};

type HistoricalGrowth = {
    title: string;
    end_month_label: string;
    rows: Array<{
        fy_label: string;
        year_end_label: string;
        date: string;
        value_cents: number;
        movement_cents: number | null;
        is_current: boolean;
    }>;
};

const props = defineProps<{
    portfolio: Portfolio;
    portfolios: Portfolio[];
    overview: Overview;
    monthly_history: Array<{ label: string; date: string; value_cents: number }>;
    historical_growth: HistoricalGrowth;
    show_archived: boolean;
    can_manage: boolean;
}>();

const page = usePage();
const toast = useToast();
const canManage = computed(() => props.can_manage || (page.props.team_permissions as string[] | undefined)?.includes('wealth.manage'));

const currency = computed(() => props.overview.currency || props.portfolio.base_currency || 'ZAR');
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, currency.value);
const formatSigned = (cents: number) => {
    const n = Number(cents) || 0;
    const formatted = formatCents(Math.abs(n));
    if (n > 0) return `+${formatted}`;
    if (n < 0) return `−${formatted}`;
    return formatted;
};

const changeClass = (cents: number) => {
    const n = Number(cents) || 0;
    if (n > 0) return 'text-emerald-700';
    if (n < 0) return 'text-rose-700';
    return 'text-slate-900';
};

const portfolioOptions = computed(() =>
    props.portfolios.map((p) => ({
        label: [
            p.is_default ? `${p.name} (default)` : p.name,
            p.is_archived ? '· archived' : null,
        ].filter(Boolean).join(' '),
        value: String(p.id),
    })),
);

const selectedPortfolioId = ref(String(props.portfolio.id));
watch(
    () => props.portfolio.id,
    (id) => {
        selectedPortfolioId.value = String(id);
    },
);

const switchPortfolio = (value: string) => {
    if (value === String(props.portfolio.id)) return;
    router.get(
        route('wealth.index', {
            portfolio: value,
            ...(props.show_archived ? { show_archived: 1 } : {}),
        }),
        {},
        { preserveState: false },
    );
};

const toggleShowArchived = () => {
    router.get(
        route('wealth.index', {
            portfolio: props.portfolio.id,
            ...(props.show_archived ? {} : { show_archived: 1 }),
        }),
        {},
        { preserveState: false, preserveScroll: true },
    );
};

const portfolioQuery = computed(() => ({
    portfolio: props.portfolio.id,
    ...(props.show_archived ? { show_archived: 1 } : {}),
}));

const activePortfolioCount = computed(
    () => props.portfolios.filter((p) => !p.is_archived).length,
);

const showForceDeletePortfolio = ref(false);

const showCreateModal = ref(false);
const showEditModal = ref(false);
const savingPortfolio = ref(false);
const createForm = ref({ name: '', financial_year_start_month: String(props.portfolio.financial_year_start_month) });
const editForm = ref({
    name: props.portfolio.name,
    financial_year_start_month: String(props.portfolio.financial_year_start_month),
    is_default: props.portfolio.is_default,
});

watch(showEditModal, (open) => {
    if (!open) return;
    editForm.value = {
        name: props.portfolio.name,
        financial_year_start_month: String(props.portfolio.financial_year_start_month),
        is_default: props.portfolio.is_default,
    };
});

const monthOptions = [
    { label: 'January', value: '1' },
    { label: 'February', value: '2' },
    { label: 'March', value: '3' },
    { label: 'April', value: '4' },
    { label: 'May', value: '5' },
    { label: 'June', value: '6' },
    { label: 'July', value: '7' },
    { label: 'August', value: '8' },
    { label: 'September', value: '9' },
    { label: 'October', value: '10' },
    { label: 'November', value: '11' },
    { label: 'December', value: '12' },
];

const openCreateModal = () => {
    createForm.value = {
        name: '',
        financial_year_start_month: String(props.portfolio.financial_year_start_month),
    };
    showCreateModal.value = true;
};

const createPortfolio = () => {
    if (savingPortfolio.value) return;
    const name = createForm.value.name.trim();
    if (!name) {
        toast.error('Enter a portfolio name.');
        return;
    }
    savingPortfolio.value = true;
    router.post(
        route('wealth.portfolios.store'),
        {
            name,
            financial_year_start_month: Number(createForm.value.financial_year_start_month),
        },
        {
            onSuccess: () => {
                showCreateModal.value = false;
            },
            onError: () => toast.error('Could not create portfolio.'),
            onFinish: () => {
                savingPortfolio.value = false;
            },
        },
    );
};

const updatePortfolio = () => {
    if (savingPortfolio.value) return;
    const name = editForm.value.name.trim();
    if (!name) {
        toast.error('Enter a portfolio name.');
        return;
    }
    savingPortfolio.value = true;
    router.put(
        route('wealth.portfolios.update', props.portfolio.id),
        {
            name,
            financial_year_start_month: Number(editForm.value.financial_year_start_month),
            is_default: editForm.value.is_default,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showEditModal.value = false;
                toast.success('Portfolio updated.');
            },
            onError: () => toast.error('Could not update portfolio.'),
            onFinish: () => {
                savingPortfolio.value = false;
            },
        },
    );
};

const archivePortfolio = () => {
    if (savingPortfolio.value || activePortfolioCount.value <= 1 || props.portfolio.is_archived) return;
    savingPortfolio.value = true;
    router.delete(route('wealth.portfolios.destroy', props.portfolio.id), {
        onSuccess: () => {
            showEditModal.value = false;
        },
        onError: () => toast.error('Could not archive portfolio.'),
        onFinish: () => {
            savingPortfolio.value = false;
        },
    });
};

const restorePortfolio = () => {
    if (savingPortfolio.value || !props.portfolio.is_archived) return;
    savingPortfolio.value = true;
    router.post(route('wealth.portfolios.restore', props.portfolio.id), {}, {
        onSuccess: () => {
            showEditModal.value = false;
            toast.success('Portfolio restored.');
        },
        onError: () => toast.error('Could not restore portfolio.'),
        onFinish: () => {
            savingPortfolio.value = false;
        },
    });
};

const forceDeletePortfolio = () => {
    if (savingPortfolio.value) return;
    if (!props.portfolio.is_archived && activePortfolioCount.value <= 1) return;
    savingPortfolio.value = true;
    router.delete(route('wealth.portfolios.force-destroy', props.portfolio.id), {
        onSuccess: () => {
            showEditModal.value = false;
            showForceDeletePortfolio.value = false;
        },
        onError: () => toast.error('Could not delete portfolio.'),
        onFinish: () => {
            savingPortfolio.value = false;
        },
    });
};

const columns: TableColumn[] = [
    { key: 'asset', label: 'Asset' },
    { key: 'owner', label: 'Owner' },
    { key: 'type', label: 'Type' },
    { key: 'institution', label: 'Institution' },
    { key: 'value', label: 'Current value', align: 'right' },
    { key: 'period', label: 'Period movement', align: 'right' },
    { key: 'fy', label: 'FY movement', align: 'right' },
];

const growthColumns: TableColumn[] = [
    { key: 'fy', label: 'Financial year' },
    { key: 'year_end', label: 'Year-end' },
    { key: 'as_of', label: 'As of' },
    { key: 'value', label: 'Market value', align: 'right' },
    { key: 'movement', label: 'Movement', align: 'right' },
];

const chartOptions = computed(() => {
    const points = filterByChartRange(props.monthly_history, chartRange.value);
    const valuesCents = points.map((p) => p.value_cents);
    const indexed = chartMode.value === 'indexed';
    const seriesData = indexed ? toIndexedSeries(valuesCents) : valuesCents;

    return {
        tooltip: {
            trigger: 'axis',
            formatter: (params: Array<{ dataIndex: number; marker: string; value: number }>) => {
                const point = params[0];
                if (!point) return '';
                const row = points[point.dataIndex];
                if (!row) return '';

                const lines = [row.label, `${point.marker} ${formatCents(row.value_cents)}`];
                if (indexed) {
                    lines.push(`Indexed: ${Number(point.value).toFixed(1)} (start = 100)`);
                }

                return lines.join('<br/>');
            },
        },
        grid: wealthChartGrid(),
        xAxis: {
            type: 'category',
            data: points.map((p) => p.label),
            axisLabel: { color: '#64748b', fontSize: 11 },
        },
        yAxis: indexed ? wealthIndexedAxis() : wealthValueAxis(currency.value),
        series: [
            {
                type: 'line',
                smooth: true,
                data: seriesData,
                areaStyle: { opacity: 0.08 },
                lineStyle: { width: 2, color: '#0f766e' },
                itemStyle: { color: '#0f766e' },
            },
        ],
    };
});
</script>

<template>
    <AppLayout
        title="Wealth"
        :breadcrumbs="[{ label: 'Wealth' }]"
    >
        <PageHeader
            title="Wealth"
            :subtitle="`Viewing ${portfolio.name} · ${portfolio.base_currency}${portfolio.is_archived ? ' · archived' : ''}`"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-[13rem] w-52">
                        <AppSelect
                            :model-value="selectedPortfolioId"
                            :options="portfolioOptions"
                            size="sm"
                            @update:model-value="switchPortfolio"
                        />
                    </div>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :class="show_archived ? 'ring-1 ring-slate-300' : ''"
                        @click="toggleShowArchived"
                    >
                        {{ show_archived ? 'Hide archived' : 'Show archived' }}
                    </AppButton>
                    <AppButton
                        v-if="canManage"
                        variant="secondary"
                        size="sm"
                        @click="showEditModal = true"
                    >
                        Portfolio settings
                    </AppButton>
                    <AppButton
                        v-if="canManage"
                        variant="secondary"
                        size="sm"
                        @click="openCreateModal"
                    >
                        New portfolio
                    </AppButton>
                    <Link :href="route('wealth.allowances.index', portfolioQuery)">
                        <AppButton variant="secondary" size="sm">Allowances</AppButton>
                    </Link>
                    <Link v-if="canManage && !portfolio.is_archived" :href="route('wealth.assets.create', portfolioQuery)">
                        <AppButton variant="primary" size="sm">Add asset</AppButton>
                    </Link>
                </div>
            </template>
        </PageHeader>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total wealth</p>
                <p class="mt-2 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(overview.total_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Accessible</p>
                <p class="mt-2 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(overview.accessible_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Restricted / retirement</p>
                <p class="mt-2 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(overview.restricted_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Change this month</p>
                <p
                    class="mt-2 text-xl font-semibold tabular-nums"
                    :class="changeClass(overview.month.investment_movement_cents)"
                >
                    {{ formatSigned(overview.month.investment_movement_cents) }}
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Change FY {{ overview.financial_year.label }}</p>
                <p
                    class="mt-2 text-xl font-semibold tabular-nums"
                    :class="changeClass(overview.financial_year.investment_movement_cents)"
                >
                    {{ formatSigned(overview.financial_year.investment_movement_cents) }}
                </p>
            </AppCard>
        </div>

        <AppCard class="mt-6">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div class="flex items-center gap-1.5">
                    <h3 class="text-base font-semibold text-slate-900">{{ historical_growth.title }}</h3>
                    <HelpTip
                        :text="`Market value at each financial year-end (${historical_growth.end_month_label}), with movement vs the previous year-end. The current year shows year-to-date.`"
                        label="About year-end portfolio value"
                    />
                </div>
            </div>
            <AppTable
                v-if="historical_growth.rows.length"
                :columns="growthColumns"
                :show-pagination="false"
                dense
                table-class="text-sm"
                embedded
            >
                <tr
                    v-for="row in historical_growth.rows"
                    :key="row.date"
                    :class="row.is_current ? 'bg-slate-50' : ''"
                >
                    <td class="whitespace-nowrap px-3 py-2">
                        <span class="font-medium text-slate-900">{{ row.fy_label }}</span>
                        <span v-if="row.is_current" class="ml-2 text-xs font-medium text-brand-700">Current</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                        {{ row.year_end_label }}
                        <span v-if="row.is_current" class="text-slate-500"> · YTD</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.date }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">
                        {{ formatCents(row.value_cents) }}
                    </td>
                    <td
                        class="whitespace-nowrap px-3 py-2 text-right tabular-nums"
                        :class="row.movement_cents == null ? 'text-slate-400' : changeClass(row.movement_cents)"
                    >
                        <template v-if="row.movement_cents == null">—</template>
                        <template v-else>{{ formatSigned(row.movement_cents) }}</template>
                    </td>
                </tr>
            </AppTable>
            <p v-else class="text-sm text-slate-500">
                Add valuations across financial years to see year-end portfolio value at each {{ historical_growth.end_month_label }} year-end.
            </p>
        </AppCard>

        <AppCard class="mt-6">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-center gap-1.5">
                    <h3 class="text-base font-semibold text-slate-900">Portfolio value</h3>
                    <HelpTip
                        text="Axis scaled to the range so small moves are visible. Use Indexed to compare growth from the first point in the selected period (100)."
                        label="About portfolio value chart"
                    />
                </div>
                <div v-if="monthly_history.length" class="flex flex-wrap items-center gap-2">
                    <div
                        class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5"
                        role="group"
                        aria-label="Chart time range"
                    >
                        <button
                            v-for="option in WEALTH_CHART_RANGES"
                            :key="option.value"
                            type="button"
                            class="rounded-md px-2 py-1 text-xs font-medium tabular-nums transition"
                            :class="chartRange === option.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            @click="chartRange = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div
                        class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5"
                        role="group"
                        aria-label="Chart scale"
                    >
                        <button
                            type="button"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                            :class="chartMode === 'value' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            @click="chartMode = 'value'"
                        >
                            Value
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                            :class="chartMode === 'indexed' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            @click="chartMode = 'indexed'"
                        >
                            Indexed
                        </button>
                    </div>
                </div>
            </div>
            <div v-if="monthly_history.length" class="h-56 w-full md:h-72">
                <VChart class="h-full w-full" :option="chartOptions" autoresize />
            </div>
            <EmptyState
                v-else
                title="No valuation history yet"
                description="Add assets and monthly valuations to see portfolio growth over time."
                :icon="PiggyBank"
            />
        </AppCard>

        <AppCard class="mt-6">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base font-semibold text-slate-900">Assets</h3>
                <p v-if="show_archived" class="text-xs text-slate-500">Archived assets are shown with a badge and excluded from totals above.</p>
            </div>
            <AppTable
                v-if="overview.assets.length"
                :columns="columns"
                :show-pagination="false"
                dense
                table-class="text-sm"
                embedded
            >
                <tr
                    v-for="row in overview.assets"
                    :key="row.id"
                    class="cursor-pointer hover:bg-slate-50"
                    :class="row.is_archived ? 'bg-slate-50/80' : ''"
                    @click="router.visit(route('wealth.assets.show', row.id))"
                >
                    <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">
                        <span class="inline-flex items-center gap-2">
                            {{ row.name }}
                            <AppBadge v-if="row.is_archived" variant="neutral">Archived</AppBadge>
                        </span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.owner_name }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.asset_type_label }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.institution || '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">{{ formatCents(row.current_value_cents) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ formatSigned(row.period_movement_cents) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ formatSigned(row.financial_year_movement_cents) }}</td>
                </tr>
            </AppTable>
            <EmptyState
                v-else
                title="No assets yet"
                :description="canManage ? 'Add your first investment, savings, or retirement account to this portfolio.' : 'Assets will appear here once someone with access adds them.'"
                :icon="PiggyBank"
            >
                <template v-if="canManage && !portfolio.is_archived" #action>
                    <Link :href="route('wealth.assets.create', portfolioQuery)">
                        <AppButton variant="primary" size="sm">Add asset</AppButton>
                    </Link>
                </template>
            </EmptyState>
        </AppCard>

        <DialogModal :show="showCreateModal" max-width="lg" @close="showCreateModal = false">
            <template #title>
                New portfolio
            </template>
            <template #content>
                <div class="space-y-4 text-left text-slate-900">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                        <AppInput v-model="createForm.name" placeholder="e.g. Personal, Business, Kids" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Financial year starts</label>
                        <AppSelect v-model="createForm.financial_year_start_month" :options="monthOptions" />
                        <p class="mt-1 text-xs text-slate-500">Used for FY movement and year-end portfolio value on this portfolio.</p>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="savingPortfolio" @click="showCreateModal = false">Cancel</AppButton>
                    <AppButton variant="primary" :loading="savingPortfolio" :disabled="savingPortfolio" @click="createPortfolio">
                        Create portfolio
                    </AppButton>
                </div>
            </template>
        </DialogModal>

        <DialogModal :show="showEditModal" max-width="lg" @close="showEditModal = false">
            <template #title>
                Portfolio settings
            </template>
            <template #content>
                <div class="space-y-4 text-left text-slate-900">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                        <AppInput v-model="editForm.name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Financial year starts</label>
                        <AppSelect v-model="editForm.financial_year_start_month" :options="monthOptions" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            v-model="editForm.is_default"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Default portfolio for this business
                    </label>
                    <p class="text-xs text-slate-500">
                        Archive hides this portfolio from the switcher (unless Show archived is on). Delete permanently removes the portfolio and all of its assets.
                    </p>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap gap-2">
                        <AppButton
                            v-if="!portfolio.is_archived && activePortfolioCount > 1"
                            variant="danger"
                            :disabled="savingPortfolio"
                            @click="archivePortfolio"
                        >
                            Archive
                        </AppButton>
                        <AppButton
                            v-if="portfolio.is_archived"
                            variant="secondary"
                            :disabled="savingPortfolio"
                            @click="restorePortfolio"
                        >
                            Restore
                        </AppButton>
                        <AppButton
                            v-if="portfolio.is_archived || activePortfolioCount > 1"
                            variant="danger"
                            :disabled="savingPortfolio"
                            @click="showForceDeletePortfolio = true"
                        >
                            Delete permanently
                        </AppButton>
                    </div>
                    <div class="ml-auto flex gap-2">
                        <AppButton variant="secondary" :disabled="savingPortfolio" @click="showEditModal = false">Cancel</AppButton>
                        <AppButton variant="primary" :loading="savingPortfolio" :disabled="savingPortfolio || portfolio.is_archived" @click="updatePortfolio">
                            Save
                        </AppButton>
                    </div>
                </div>
            </template>
        </DialogModal>

        <ConfirmationModal :show="showForceDeletePortfolio" @close="showForceDeletePortfolio = false">
            <template #title>
                Delete portfolio permanently?
            </template>
            <template #content>
                <p class="text-sm text-slate-600">
                    This permanently deletes <strong>{{ portfolio.name }}</strong> and all of its assets, valuations, and transactions. This cannot be undone.
                </p>
            </template>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="secondary" :disabled="savingPortfolio" @click="showForceDeletePortfolio = false">Cancel</AppButton>
                    <AppButton variant="danger" :loading="savingPortfolio" :disabled="savingPortfolio" @click="forceDeletePortfolio">
                        Delete permanently
                    </AppButton>
                </div>
            </template>
        </ConfirmationModal>
    </AppLayout>
</template>
