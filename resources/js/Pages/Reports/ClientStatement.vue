<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const reportsTabs = useReportsTabs();

type StatementLine = {
    date: string;
    type: 'invoice' | 'payment';
    reference: string;
    detail: string;
    invoice_id: number;
    charge_cents: number;
    credit_cents: number;
    balance_cents: number;
};

type Statement = {
    client: { id: number; name: string; email: string | null; address_lines: string[] };
    period: { from: string; to: string };
    opening_cents: number;
    charges_cents: number;
    credits_cents: number;
    closing_cents: number;
    lines: StatementLine[];
};

const props = defineProps<{
    clients: Array<{ id: number; name: string }>;
    statement: Statement | null;
    period: { from: string; to: string };
    currency: string;
}>();

const state = ref({
    client_id: props.statement?.client.id ? String(props.statement.client.id) : '',
    from: props.period.from,
    to: props.period.to,
});

watch(() => [props.statement?.client.id, props.period.from, props.period.to], () => {
    state.value = {
        client_id: props.statement?.client.id ? String(props.statement.client.id) : state.value.client_id,
        from: props.period.from,
        to: props.period.to,
    };
});

const page = usePage<{ team_permissions?: string[] }>();
const canExport = computed(() => (page.props.team_permissions ?? []).includes('reports.export'));
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, props.currency);

const clientOptions = computed(() => props.clients.map((client) => ({
    label: client.name,
    value: String(client.id),
})));

const pdfHref = computed(() => {
    if (!props.statement) {
        return route('reports.client-statement');
    }

    return route('reports.client-statement.pdf', {
        client_id: props.statement.client.id,
        from: props.period.from,
        to: props.period.to,
    });
});

const columns = [
    { key: 'date', label: 'Date', widthClass: 'w-[14%]' },
    { key: 'detail', label: 'Details', widthClass: 'w-[28%]' },
    { key: 'reference', label: 'Reference', widthClass: 'w-[18%]' },
    { key: 'charge', label: 'Charge', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'credit', label: 'Credit', align: 'right' as const, widthClass: 'w-[13%]' },
    { key: 'balance', label: 'Balance', align: 'right' as const, widthClass: 'w-[14%]' },
];

const apply = () => {
    router.get(route('reports.client-statement'), {
        client_id: state.value.client_id || undefined,
        from: state.value.from,
        to: state.value.to,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="client-statement"
        :tabs="reportsTabs"
        document-title="Client statement"
        subtitle="One client's invoices, payments, and running balance"
    >
        <template v-if="canExport && statement" #actions>
            <AppButton as="a" variant="secondary" :href="pdfHref">Export PDF</AppButton>
        </template>

        <AppCard>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,16rem)_minmax(0,11rem)_minmax(0,11rem)_auto] xl:items-end">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                    <AppSelect
                        :model-value="state.client_id"
                        :options="clientOptions"
                        placeholder="Choose a client"
                        @update:model-value="state.client_id = String($event ?? '')"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="state.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="state.to" type="date" />
                </div>
                <div>
                    <AppButton variant="secondary" @click="apply">Apply</AppButton>
                </div>
            </div>
        </AppCard>

        <template v-if="statement">
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <AppCard>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Opening</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(statement.opening_cents) }}</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoiced</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(statement.charges_cents) }}</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Received</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(statement.credits_cents) }}</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Closing</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatCents(statement.closing_cents) }}</p>
                </AppCard>
            </div>

            <AppCard class="mt-5 overflow-hidden p-0">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">{{ statement.client.name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ period.from }} → {{ period.to }} · {{ currency }}</p>
                </div>
                <EmptyState
                    v-if="statement.lines.length === 0"
                    title="No activity in this period"
                    :description="`Opening balance ${formatCents(statement.opening_cents)} carries through to the close.`"
                />
                <AppTable
                    v-else
                    :columns="columns"
                    table-class="w-full table-fixed text-sm"
                    dense
                    embedded
                    :show-pagination="false"
                >
                    <tr>
                        <td class="px-3 py-2 text-slate-500">{{ period.from }}</td>
                        <td class="px-3 py-2">Opening balance</td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(statement.opening_cents) }}</td>
                    </tr>
                    <tr v-for="(line, index) in statement.lines" :key="`${line.type}-${line.invoice_id}-${line.date}-${index}`">
                        <td class="px-3 py-2 whitespace-nowrap">{{ line.date }}</td>
                        <td class="px-3 py-2">
                            <a
                                :href="route('invoicing.invoices.show', line.invoice_id)"
                                class="font-medium text-brand-700 hover:underline"
                            >{{ line.detail }}</a>
                        </td>
                        <td class="truncate px-3 py-2 text-slate-600">{{ line.reference }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ line.charge_cents ? formatCents(line.charge_cents) : '' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ line.credit_cents ? formatCents(line.credit_cents) : '' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(line.balance_cents) }}</td>
                    </tr>
                    <template #footer>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-3 py-2" colspan="3">Closing balance</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(statement.charges_cents) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(statement.credits_cents) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatCents(statement.closing_cents) }}</td>
                        </tr>
                    </template>
                </AppTable>
            </AppCard>
        </template>

        <AppCard v-else class="mt-5">
            <EmptyState
                title="Choose a client"
                description="Pick a client and a date range, then apply. The statement lists invoices and payments with a running balance."
            />
        </AppCard>
    </FeatureShell>
</template>
