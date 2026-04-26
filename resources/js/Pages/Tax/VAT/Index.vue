<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = defineProps<{
    current_period: null | {
        id: number;
        period_start: string;
        period_end: string;
        due_date: string | null;
        due_in_days: number | null;
        status: string;
    };
    vat_summary: {
        output_vat: number;
        input_vat: number;
        net_vat: number;
        transaction_count: number;
    };
    periods: Array<{
        id: number;
        period_start: string | null;
        period_end: string | null;
        status: string;
        submitted_at: string | null;
        output_vat: number;
        input_vat: number;
        net_vat: number;
    }>;
    vat_transactions: {
        data: Array<{
            id: string;
            date: string | null;
            reference: string;
            description: string;
            excl_vat: number;
            vat_rate: number;
            vat_amount: number;
            type: 'input' | 'output';
        }>;
        current_page: number;
        last_page: number;
    };
}>();

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
const dueSoon = computed(() => props.current_period?.due_in_days !== null && (props.current_period?.due_in_days ?? 99) <= 14);

const submitPeriod = () => {
    if (!props.current_period) return;
    router.post(route('tax.vat.submit', props.current_period.id));
};

const pageChange = (page: number) => {
    router.get(route('tax.vat.index'), { page }, { preserveState: true, preserveScroll: true, replace: true });
};

const totalExclVat = computed(() => props.vat_transactions.data.reduce((sum, row) => sum + Number(row.excl_vat || 0), 0));
const totalVat = computed(() => props.vat_transactions.data.reduce((sum, row) => sum + Number(row.vat_amount || 0), 0));
</script>

<template>
    <AppLayout
        title="VAT Returns"
        :breadcrumbs="[
            { label: 'Tax' },
            { label: 'VAT Returns' },
        ]"
    >
        <PageHeader title="VAT Returns" subtitle="Manage VAT201 periods and supporting transactions" />

        <AppCard class="mt-5" v-if="current_period">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-slate-500">Current VAT period</p>
                    <h3 class="mt-1 text-xl font-semibold text-slate-900">
                        {{ current_period.period_start }} — {{ current_period.period_end }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-600">
                        Due: {{ current_period.due_date || '—' }}
                        <span v-if="current_period.due_in_days !== null && dueSoon" class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-amber-700">
                            {{ current_period.due_in_days }} days left
                        </span>
                    </p>
                </div>
                <AppBadge :variant="current_period.status === 'submitted' ? 'success' : 'warning'">
                    {{ current_period.status }}
                </AppBadge>
            </div>
        </AppCard>

        <div class="mt-5 grid gap-6 xl:grid-cols-3">
            <AppCard class="xl:col-span-2">
                <h3 class="mb-4 text-lg font-semibold text-slate-900">VAT201 Summary</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Output VAT (VAT collected on sales)</span>
                        <span class="font-medium text-slate-900">{{ formatCents(vat_summary.output_vat) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600">Less: Input VAT (VAT paid on purchases)</span>
                        <span class="font-medium text-slate-900">{{ formatCents(vat_summary.input_vat) }}</span>
                    </div>
                    <div class="border-t border-slate-200 pt-2" />
                    <div class="flex items-center justify-between text-base font-bold">
                        <span class="text-slate-900">Net VAT {{ vat_summary.net_vat >= 0 ? 'payable to SARS' : 'refund due from SARS' }}</span>
                        <span :class="vat_summary.net_vat >= 0 ? 'text-slate-900' : 'text-emerald-700'">
                            {{ formatCents(Math.abs(vat_summary.net_vat)) }}
                        </span>
                    </div>
                </div>
            </AppCard>

            <AppCard>
                <h3 class="mb-3 text-lg font-semibold text-slate-900">Actions</h3>
                <div class="space-y-2">
                    <AppButton variant="secondary" class="w-full justify-start">View supporting transactions</AppButton>
                    <AppButton variant="secondary" class="w-full justify-start">Export VAT report (Excel)</AppButton>
                    <AppButton
                        variant="primary"
                        class="w-full justify-start"
                        :disabled="!current_period || current_period.status === 'submitted'"
                        @click="submitPeriod"
                    >
                        Mark as submitted
                    </AppButton>
                </div>
                <p class="mt-3 text-xs text-slate-500">Transactions in period: {{ vat_summary.transaction_count }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Supporting Transactions</h3>
            <AppTable
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'description', label: 'Description' },
                    { key: 'excl', label: 'Excl VAT' },
                    { key: 'rate', label: 'VAT Rate' },
                    { key: 'amount', label: 'VAT Amount' },
                    { key: 'type', label: 'Type' },
                ]"
                :page="vat_transactions.current_page"
                :last-page="vat_transactions.last_page"
                @page-change="pageChange"
            >
                <tr v-for="row in vat_transactions.data" :key="row.id">
                    <td class="px-4 py-3">{{ row.date || '-' }}</td>
                    <td class="px-4 py-3">{{ row.reference }}</td>
                    <td class="px-4 py-3">{{ row.description }}</td>
                    <td class="px-4 py-3">{{ formatCents(row.excl_vat) }}</td>
                    <td class="px-4 py-3">{{ (Number(row.vat_rate || 0) * 100).toFixed(2) }}%</td>
                    <td class="px-4 py-3">{{ formatCents(row.vat_amount) }}</td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="row.type === 'input' ? 'success' : 'info'">{{ row.type }}</AppBadge>
                    </td>
                </tr>
                <tr v-if="!vat_transactions.data.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState title="No VAT transactions found" description="No VAT-bearing transactions in this period." />
                    </td>
                </tr>
                <tr v-if="vat_transactions.data.length" class="bg-slate-50 font-semibold">
                    <td class="px-4 py-3" colspan="3">Totals</td>
                    <td class="px-4 py-3">{{ formatCents(totalExclVat) }}</td>
                    <td class="px-4 py-3">—</td>
                    <td class="px-4 py-3">{{ formatCents(totalVat) }}</td>
                    <td class="px-4 py-3">—</td>
                </tr>
            </AppTable>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Past Periods</h3>
            <AppTable
                :columns="[
                    { key: 'period', label: 'Period' },
                    { key: 'status', label: 'Status' },
                    { key: 'output', label: 'Output VAT' },
                    { key: 'input', label: 'Input VAT' },
                    { key: 'net', label: 'Net VAT' },
                    { key: 'submitted', label: 'Submitted' },
                ]"
                :page="1"
                :last-page="1"
            >
                <tr v-for="period in periods" :key="period.id">
                    <td class="px-4 py-3">{{ period.period_start }} — {{ period.period_end }}</td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="period.status === 'submitted' ? 'success' : period.status === 'open' ? 'warning' : 'neutral'">
                            {{ period.status }}
                        </AppBadge>
                    </td>
                    <td class="px-4 py-3">{{ formatCents(period.output_vat) }}</td>
                    <td class="px-4 py-3">{{ formatCents(period.input_vat) }}</td>
                    <td class="px-4 py-3 font-medium">{{ formatCents(period.net_vat) }}</td>
                    <td class="px-4 py-3">{{ period.submitted_at || '—' }}</td>
                </tr>
                <tr v-if="!periods.length">
                    <td colspan="6" class="px-4 py-6">
                        <EmptyState title="No VAT periods yet" description="VAT periods will appear once generated." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
