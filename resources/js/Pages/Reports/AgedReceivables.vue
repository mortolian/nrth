<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import FeatureShell from '@/Components/FeatureShell.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const reportsTabs = useReportsTabs();

type AgedInvoice = {
    id: number;
    number: string;
    issue_date: string | null;
    due_date: string | null;
    days_overdue: number;
    bucket: string;
    amount_cents: number;
    currency: string;
};

type AgedClient = {
    client_id: number;
    name: string;
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    over_90: number;
    total: number;
    invoices: AgedInvoice[];
};

const props = defineProps<{
    report: {
        as_of: string;
        clients: AgedClient[];
        totals: {
            current: number;
            days_1_30: number;
            days_31_60: number;
            days_61_90: number;
            over_90: number;
            total: number;
        };
        invoice_count: number;
    };
    as_of: string;
    currency: string;
}>();

const asOf = ref(props.as_of);
const openClients = ref<Record<number, boolean>>({});

watch(() => props.as_of, (value) => {
    asOf.value = value;
});

const page = usePage<{ team_permissions?: string[] }>();
const canExport = computed(() => (page.props.team_permissions ?? []).includes('reports.export'));
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, props.currency);
const overdue = computed(() => props.report.totals.total - props.report.totals.current);

const pdfHref = computed(() => route('reports.aged-receivables.pdf', { as_of: props.as_of }));

const columns = [
    { key: 'name', label: 'Client', widthClass: 'w-[22%]' },
    { key: 'current', label: 'Current', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'days_1_30', label: '1–30', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'days_31_60', label: '31–60', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'days_61_90', label: '61–90', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'over_90', label: '90+', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'total', label: 'Total', align: 'right' as const, widthClass: 'w-[13%]' },
];

const apply = () => {
    router.get(route('reports.aged-receivables'), { as_of: asOf.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const toggle = (clientId: number) => {
    openClients.value = { ...openClients.value, [clientId]: !openClients.value[clientId] };
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="aged-receivables"
        :tabs="reportsTabs"
        document-title="Aged receivables"
        subtitle="Open invoices grouped by how long they have been due"
    >
        <template v-if="canExport" #actions>
            <AppButton as="a" variant="secondary" :href="pdfHref">Export PDF</AppButton>
        </template>

        <AppCard>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">As of</label>
                    <AppInput v-model="asOf" type="date" />
                </div>
                <AppButton variant="secondary" @click="apply">Apply</AppButton>
            </div>
            <p class="mt-3 text-sm text-slate-500">
                Amounts are in {{ currency }}. Payments after this date are not deducted.
            </p>
        </AppCard>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total owing</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(report.totals.total) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Current</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(report.totals.current) }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Overdue</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(overdue) }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5 overflow-hidden p-0">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-900">By client</h2>
                <p class="mt-1 text-sm text-slate-500">{{ report.invoice_count }} open invoice{{ report.invoice_count === 1 ? '' : 's' }}</p>
            </div>
            <EmptyState
                v-if="report.clients.length === 0"
                title="Nothing outstanding"
                description="Issued invoices with a balance at this date will appear here."
            />
            <AppTable
                v-else
                :columns="columns"
                table-class="w-full table-fixed text-sm"
                dense
                embedded
                :show-pagination="false"
            >
                <template v-for="client in report.clients" :key="client.client_id">
                    <tr class="cursor-pointer hover:bg-slate-50" @click="toggle(client.client_id)">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center gap-1 font-medium text-slate-900">
                                <ChevronRight class="h-4 w-4 shrink-0 text-slate-400" :class="openClients[client.client_id] ? 'rotate-90' : ''" />
                                <span class="truncate">{{ client.name }}</span>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(client.current) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(client.days_1_30) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(client.days_31_60) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(client.days_61_90) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(client.over_90) }}</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatCents(client.total) }}</td>
                    </tr>
                    <tr v-if="openClients[client.client_id]">
                        <td colspan="7" class="bg-slate-50 px-3 py-3">
                            <table class="w-full table-fixed text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="w-[18%] px-2 py-1 font-medium">Invoice</th>
                                        <th class="w-[16%] px-2 py-1 font-medium">Issued</th>
                                        <th class="w-[16%] px-2 py-1 font-medium">Due</th>
                                        <th class="w-[14%] px-2 py-1 text-right font-medium">Days</th>
                                        <th class="w-[36%] px-2 py-1 text-right font-medium">Outstanding</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="invoice in client.invoices" :key="invoice.id">
                                        <td class="px-2 py-1">
                                            <a
                                                :href="route('invoicing.invoices.show', invoice.id)"
                                                class="font-medium text-brand-700 hover:underline"
                                                @click.stop
                                            >{{ invoice.number }}</a>
                                        </td>
                                        <td class="px-2 py-1 text-slate-600">{{ invoice.issue_date }}</td>
                                        <td class="px-2 py-1 text-slate-600">{{ invoice.due_date || '—' }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums text-slate-600">{{ invoice.days_overdue }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums">{{ formatCents(invoice.amount_cents) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </template>
                <template #footer>
                    <tr class="border-t border-slate-300 font-semibold">
                        <td class="px-3 py-2">Total</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.current) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.days_1_30) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.days_31_60) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.days_61_90) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.over_90) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(report.totals.total) }}</td>
                    </tr>
                </template>
            </AppTable>
        </AppCard>
    </FeatureShell>
</template>
