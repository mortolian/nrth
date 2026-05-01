<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import RecordInvoicePaymentDrawer from '@/Components/RecordInvoicePaymentDrawer.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { Filter, X } from 'lucide-vue-next';

type InvoiceRow = {
    id: number;
    client_name: string;
    number: string;
    issue_date: string | null;
    due_date: string | null;
    currency: string;
    total: number;
    amount_due: number;
    status: string;
    is_overdue: boolean;
    days_overdue: number;
    can_delete: boolean;
    company_currency_code?: string | null;
    fx_rate_invoice_to_company?: string | null;
    fx_rate_date?: string | null;
    total_company_currency_cents?: number | null;
};

const props = defineProps<{
    invoices: {
        data: InvoiceRow[];
        current_page: number;
        last_page: number;
    };
    summary: {
        draft_count: number;
        sent_count: number;
        overdue_count: number;
        overdue_total: number;
        overdue_totals_by_currency: Array<{ currency: string; total_cents: number }>;
    };
    filters: {
        status: string;
        from: string | null;
        to: string | null;
        client: string | null;
        client_id: number | null;
        min_amount: number | null;
        max_amount: number | null;
    };
    filter_client: { id: number; name: string } | null;
}>();

const page = usePage<{ csrf_token?: string; vat_enabled?: boolean }>();
const selected = ref<number[]>([]);
const exportingZip = ref(false);
const paymentDrawerOpen = ref(false);
const selectedInvoice = ref<InvoiceRow | null>(null);

const recordPaymentInvoice = computed(() => {
    const inv = selectedInvoice.value;
    if (!inv) return null;
    return {
        id: inv.id,
        number: inv.number,
        client_name: inv.client_name,
        amount_due_cents: inv.amount_due,
        total_cents: inv.total,
        currency: inv.currency,
        company_currency_code: inv.company_currency_code ?? null,
        fx_rate_invoice_to_company: inv.fx_rate_invoice_to_company ?? null,
        fx_rate_date: inv.fx_rate_date ?? null,
        total_company_currency_cents: inv.total_company_currency_cents ?? null,
    };
});

const chargesVatForPayment = computed(() => page.props.vat_enabled !== false);

const localFilters = ref({
    status: props.filters.status ?? 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    client: props.filters.client ?? '',
    client_id: props.filters.client_id != null ? String(props.filters.client_id) : '',
    min_amount: props.filters.min_amount?.toString() ?? '',
    max_amount: props.filters.max_amount?.toString() ?? '',
});

const buildInvoiceIndexQuery = (extra: Record<string, string | number> = {}) => {
    const q: Record<string, string | number> = {
        status: localFilters.value.status,
        ...extra,
    };
    if (localFilters.value.from) q.from = localFilters.value.from;
    if (localFilters.value.to) q.to = localFilters.value.to;
    if (localFilters.value.client) q.client = localFilters.value.client;
    if (localFilters.value.client_id) q.client_id = Number(localFilters.value.client_id);
    if (localFilters.value.min_amount) q.min_amount = Number(localFilters.value.min_amount);
    if (localFilters.value.max_amount) q.max_amount = Number(localFilters.value.max_amount);

    return q;
};

const statusOptions = [
    { label: 'All', value: 'all' },
    { label: 'Draft', value: 'draft' },
    { label: 'Sent', value: 'sent' },
    { label: 'Overdue', value: 'overdue' },
    { label: 'Paid', value: 'paid' },
    { label: 'Void', value: 'void' },
];

const formatRowCents = (cents: number, currency: string) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const applyFilters = () => {
    router.get(route('invoicing.invoices.index'), buildInvoiceIndexQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    localFilters.value = {
        status: 'all',
        from: '',
        to: '',
        client: '',
        client_id: '',
        min_amount: '',
        max_amount: '',
    };
    applyFilters();
};

const clearClientScope = () => {
    localFilters.value.client_id = '';
    applyFilters();
};

/** Summary cards: apply matching status filter and reload the list. */
const applyStatFilter = (status: string) => {
    localFilters.value.status = status;
    applyFilters();
};

const navigateToPage = (page: number) => {
    router.get(route('invoicing.invoices.index'), buildInvoiceIndexQuery({ page }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const rowActionItems = (invoice: InvoiceRow) => {
    const actions = [
        { id: 'view', label: 'View' },
        { id: 'download_pdf', label: 'Download PDF' },
    ];
    if (invoice.status === 'draft') {
        actions.push({ id: 'send', label: 'Send' });
        actions.push({ id: 'mark_sent', label: 'Mark as sent' });
    }
    if (invoice.status !== 'paid' && invoice.status !== 'void') actions.push({ id: 'record_payment', label: 'Record Payment' });
    if (invoice.status === 'sent') actions.push({ id: 'void', label: 'Void' });
    if (invoice.status === 'void') actions.push({ id: 'unvoid', label: 'Restore' });
    if (invoice.can_delete) actions.push({ id: 'delete', label: 'Delete' });
    return actions;
};

const onAction = (invoice: InvoiceRow, actionId: string) => {
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
        selectedInvoice.value = invoice;
        paymentDrawerOpen.value = true;
    } else if (actionId === 'delete') {
        if (!window.confirm(`Permanently delete invoice ${invoice.number}? This cannot be undone.`)) {
            return;
        }
        router.delete(route('invoicing.invoices.destroy', invoice.id), {
            preserveScroll: true,
        });
    }
};

const toggleSelected = (id: number, checked: boolean) => {
    if (checked) {
        if (!selected.value.includes(id)) selected.value.push(id);
        return;
    }
    selected.value = selected.value.filter((item) => item !== id);
};

const exportSelectedPdfZip = async () => {
    if (selected.value.length === 0 || exportingZip.value) {
        return;
    }
    const token = page.props.csrf_token;
    if (!token) {
        window.alert('Unable to export: missing security token. Refresh the page and try again.');
        return;
    }
    exportingZip.value = true;
    try {
        const res = await fetch(route('invoicing.invoices.export-pdf-zip'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ invoice_ids: selected.value }),
        });
        if (!res.ok) {
            const data = (await res.json().catch(() => null)) as { message?: string } | null;
            window.alert(data?.message ?? 'Export failed. Please try again.');
            return;
        }
        const blob = await res.blob();
        const filename =
            res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? 'invoices.zip';
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch {
        window.alert('Export failed. Please try again.');
    } finally {
        exportingZip.value = false;
    }
};
</script>

<template>
    <AppLayout
        title="Invoices"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Invoices' },
        ]"
    >
        <PageHeader title="Invoices">
            <template #actions>
                <Link
                    :href="route('invoicing.invoices.create')"
                    class="inline-flex items-center justify-center rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-400"
                >
                    New Invoice
                </Link>
            </template>
        </PageHeader>

        <div
            v-if="filter_client"
            class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950"
        >
            <span>
                Showing invoices for <strong>{{ filter_client.name }}</strong>
            </span>
            <button
                type="button"
                class="font-medium text-brand-700 underline decoration-brand-600/40 underline-offset-2 hover:text-brand-600"
                @click="clearClientScope"
            >
                Show all clients
            </button>
        </div>

        <div class="space-y-6">
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
                <button
                    type="button"
                    class="block w-full rounded-xl text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    @click="applyStatFilter('draft')"
                >
                    <StatCard title="Draft" :value="String(summary.draft_count)" hint="Awaiting send" trend="neutral" />
                </button>
                <button
                    type="button"
                    class="block w-full rounded-xl text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    @click="applyStatFilter('sent')"
                >
                    <StatCard title="Sent" :value="String(summary.sent_count)" hint="Awaiting payment" trend="neutral" />
                </button>
                <button
                    type="button"
                    class="block w-full rounded-xl text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    @click="applyStatFilter('overdue')"
                >
                    <StatCard title="Overdue" :value="String(summary.overdue_count)" hint="Invoices past due" trend="down" />
                </button>
                <button
                    type="button"
                    class="block w-full rounded-xl text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    @click="applyStatFilter('overdue')"
                >
                    <AppCard class="h-full">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Overdue totals</p>
                        <template v-if="summary.overdue_totals_by_currency.length === 1">
                            <p class="mt-1 text-2xl font-semibold text-rose-600">
                                {{ formatRowCents(summary.overdue_totals_by_currency[0].total_cents, summary.overdue_totals_by_currency[0].currency) }}
                            </p>
                        </template>
                        <template v-else>
                            <div class="mt-1 space-y-1">
                                <div
                                    v-for="t in summary.overdue_totals_by_currency"
                                    :key="t.currency"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t.currency }}</span>
                                    <span class="text-sm font-semibold text-rose-600">{{ formatRowCents(t.total_cents, t.currency) }}</span>
                                </div>
                            </div>
                        </template>
                    </AppCard>
                </button>
            </div>

            <AppCard>
                <div class="flex flex-col gap-4">
                    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                        <button
                            v-for="status in statusOptions"
                            :key="status.value"
                            type="button"
                            :class="[
                                'shrink-0 whitespace-nowrap rounded-md border px-3 py-2 text-sm transition md:py-1.5',
                                localFilters.status === status.value
                                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                                    : 'border-slate-200 text-slate-600 hover:bg-slate-50',
                            ]"
                            @click="localFilters.status = status.value"
                        >
                            {{ status.label }}
                        </button>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <div class="xl:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                            <AppInput v-model="localFilters.from" type="date" />
                        </div>
                        <div class="xl:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                            <AppInput v-model="localFilters.to" type="date" />
                        </div>
                        <div class="xl:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                            <AppInput v-model="localFilters.client" placeholder="Search client..." />
                        </div>
                        <div class="xl:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Min amount</label>
                            <AppInput
                                v-model="localFilters.min_amount"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                            />
                        </div>
                        <div class="xl:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Max amount</label>
                            <AppInput
                                v-model="localFilters.max_amount"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton variant="secondary" @click="applyFilters">
                            <Filter class="mr-2 h-4 w-4" />
                            Apply filters
                        </AppButton>
                        <AppButton variant="ghost" @click="clearFilters">
                            <X class="mr-2 h-4 w-4" />
                            Clear filters
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <AppCard>
                <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Invoice list</h3>
                    <div class="hidden items-center gap-2 sm:flex">
                        <AppButton
                            variant="secondary"
                            size="sm"
                            :disabled="selected.length === 0 || exportingZip"
                            @click="exportSelectedPdfZip"
                        >
                            {{ exportingZip ? 'Preparing…' : 'Export selected (PDF zip)' }}
                        </AppButton>
                    </div>
                </div>

                <div class="mb-4 space-y-3 md:hidden">
                    <button
                        v-for="invoice in invoices.data"
                        :key="`mobile-${invoice.id}`"
                        type="button"
                        class="w-full rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm active:bg-slate-50"
                        @click="onAction(invoice, 'view')"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ invoice.number }}</p>
                                <p class="text-sm text-slate-600">{{ invoice.client_name }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <AppBadge
                                    :variant="invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : invoice.is_overdue ? 'danger' : 'info'"
                                >
                                    {{ invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void' ? 'overdue' : invoice.status }}
                                </AppBadge>
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(invoice)"
                                    :aria-label="`Actions for ${invoice.number}`"
                                    @select="(id) => onAction(invoice, id)"
                                />
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                            <span>Due <DateDisplay :value="invoice.due_date" /></span>
                            <span v-if="invoice.is_overdue" class="font-medium text-rose-700">{{ invoice.days_overdue }}d late</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 text-sm">
                            <span class="text-slate-500">Due</span>
                            <span :class="invoice.amount_due > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'">{{ formatRowCents(invoice.amount_due, invoice.currency) }}</span>
                        </div>
                    </button>
                    <div v-if="!invoices.data.length" class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                        No invoices match. Try filters or create a new invoice.
                    </div>
                </div>

                <div class="hidden md:block">
                    <AppTable
                    table-class="min-w-[920px]"
                    :columns="[
                        { key: 'select', label: '', widthClass: 'w-10 shrink-0' },
                        { key: 'number', label: 'Invoice #', sortable: true, widthClass: 'whitespace-nowrap' },
                        { key: 'client', label: 'Client', sortable: true, widthClass: 'w-[11rem] min-w-[8rem] max-w-[13rem]' },
                        { key: 'issue', label: 'Issued', sortable: true, widthClass: 'whitespace-nowrap' },
                        { key: 'due', label: 'Due', sortable: true, widthClass: 'whitespace-nowrap' },
                        { key: 'total', label: 'Total', sortable: true, widthClass: 'whitespace-nowrap text-right tabular-nums' },
                        { key: 'amount_due', label: 'Outstanding', sortable: true, widthClass: 'whitespace-nowrap text-right tabular-nums' },
                        { key: 'status', label: 'Status', sortable: true, widthClass: 'whitespace-nowrap' },
                        { key: 'actions', label: '', widthClass: 'w-[1%] whitespace-nowrap text-right' },
                    ]"
                    :page="invoices.current_page"
                    :last-page="invoices.last_page"
                    @page-change="navigateToPage"
                >
                    <tr
                        v-for="invoice in invoices.data"
                        :key="invoice.id"
                        :class="[
                            'cursor-pointer text-sm text-slate-700 hover:bg-slate-50',
                            invoice.is_overdue ? 'border-l-2 border-l-rose-300' : '',
                        ]"
                        @click="onAction(invoice, 'view')"
                    >
                        <td class="px-3 py-3 align-middle" @click.stop>
                            <input
                                type="checkbox"
                                :checked="selected.includes(invoice.id)"
                                class="h-4 w-4 rounded border-slate-300"
                                @change="toggleSelected(invoice.id, ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 align-middle">
                            <a
                                :href="route('invoicing.invoices.show', invoice.id)"
                                class="font-medium text-brand-700 hover:underline"
                                @click.stop
                            >
                                {{ invoice.number }}
                            </a>
                        </td>
                        <td class="max-w-[13rem] px-3 py-3 align-middle">
                            <span class="block truncate text-slate-700" :title="invoice.client_name">{{ invoice.client_name }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 align-middle text-slate-700"><DateDisplay :value="invoice.issue_date" /></td>
                        <td class="whitespace-nowrap px-3 py-3 align-middle">
                            <div class="flex flex-nowrap items-center gap-1.5">
                                <span class="text-slate-700"><DateDisplay :value="invoice.due_date" /></span>
                                <AppBadge v-if="invoice.is_overdue" variant="danger">{{ invoice.days_overdue }}d</AppBadge>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right align-middle tabular-nums text-slate-700">{{ formatRowCents(invoice.total, invoice.currency) }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right align-middle tabular-nums">
                            <span :class="invoice.amount_due > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'">
                                {{ formatRowCents(invoice.amount_due, invoice.currency) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 align-middle">
                            <AppBadge
                                :variant="invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : invoice.is_overdue ? 'danger' : 'info'"
                            >
                                {{ invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void' ? 'overdue' : invoice.status }}
                            </AppBadge>
                        </td>
                        <td class="px-3 py-3 text-right align-middle" @click.stop>
                            <div class="inline-flex justify-end">
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(invoice)"
                                    :aria-label="`Actions for ${invoice.number}`"
                                    @select="(id) => onAction(invoice, id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!invoices.data.length">
                        <td colspan="9" class="px-4 py-6">
                            <EmptyState
                                title="No invoices found"
                                description="Try adjusting your filters or create a new invoice."
                            >
                                <template #action>
                                    <Link
                                        :href="route('invoicing.invoices.create')"
                                        class="inline-flex items-center justify-center rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-400"
                                    >
                                        New Invoice
                                    </Link>
                                </template>
                            </EmptyState>
                        </td>
                    </tr>
                </AppTable>
                </div>
            </AppCard>
        </div>

        <RecordInvoicePaymentDrawer
            :open="paymentDrawerOpen"
            :invoice="paymentDrawerOpen ? recordPaymentInvoice : null"
            :charges-vat="chargesVatForPayment"
            @update:open="paymentDrawerOpen = $event"
        />
    </AppLayout>
</template>
