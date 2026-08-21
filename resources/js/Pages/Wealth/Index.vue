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
    }>;
};

const props = defineProps<{
    portfolio: Portfolio;
    portfolios: Portfolio[];
    overview: Overview;
    monthly_history: Array<{ label: string; date: string; value_cents: number }>;
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

const portfolioOptions = computed(() =>
    props.portfolios.map((p) => ({
        label: p.is_default ? `${p.name} (default)` : p.name,
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
    router.get(route('wealth.index', { portfolio: value }), {}, { preserveState: false });
};

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
    if (savingPortfolio.value || props.portfolios.length <= 1) return;
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

const columns: TableColumn[] = [
    { key: 'asset', label: 'Asset' },
    { key: 'owner', label: 'Owner' },
    { key: 'type', label: 'Type' },
    { key: 'institution', label: 'Institution' },
    { key: 'value', label: 'Current value', align: 'right' },
    { key: 'period', label: 'Period movement', align: 'right' },
    { key: 'fy', label: 'FY movement', align: 'right' },
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

const portfolioQuery = computed(() => ({ portfolio: props.portfolio.id }));
</script>

<template>
    <AppLayout
        title="Wealth"
        :breadcrumbs="[{ label: 'Wealth' }]"
    >
        <PageHeader
            title="Wealth"
            :subtitle="`Viewing ${portfolio.name} · ${portfolio.base_currency}`"
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
                    <Link :href="route('wealth.history', portfolioQuery)">
                        <AppButton variant="secondary" size="sm">History</AppButton>
                    </Link>
                    <Link :href="route('wealth.allowances.index', portfolioQuery)">
                        <AppButton variant="secondary" size="sm">Allowances</AppButton>
                    </Link>
                    <Link v-if="canManage" :href="route('wealth.assets.create', portfolioQuery)">
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
                <p class="mt-2 text-xl font-semibold tabular-nums text-slate-900">{{ formatSigned(overview.month.investment_movement_cents) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Change FY {{ overview.financial_year.label }}</p>
                <p class="mt-2 text-xl font-semibold tabular-nums text-slate-900">{{ formatSigned(overview.financial_year.investment_movement_cents) }}</p>
            </AppCard>
        </div>

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
            <h3 class="mb-3 text-base font-semibold text-slate-900">Assets</h3>
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
                    @click="router.visit(route('wealth.assets.show', row.id))"
                >
                    <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">{{ row.name }}</td>
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
                <template v-if="canManage" #action>
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
                        <p class="mt-1 text-xs text-slate-500">Used for FY movement and annual history on this portfolio.</p>
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
                    <p v-if="portfolios.length > 1" class="text-xs text-slate-500">
                        Archiving removes this portfolio from the switcher. Assets remain in the database but are not shown until restored in a later release.
                    </p>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full flex-wrap items-center justify-between gap-2">
                    <AppButton
                        v-if="portfolios.length > 1"
                        variant="danger"
                        :disabled="savingPortfolio"
                        @click="archivePortfolio"
                    >
                        Archive portfolio
                    </AppButton>
                    <div class="ml-auto flex gap-2">
                        <AppButton variant="secondary" :disabled="savingPortfolio" @click="showEditModal = false">Cancel</AppButton>
                        <AppButton variant="primary" :loading="savingPortfolio" :disabled="savingPortfolio" @click="updatePortfolio">
                            Save
                        </AppButton>
                    </div>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>
