<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { Filter, X } from 'lucide-vue-next';

type InvoiceRow = {
    id: number;
    client_name: string;
    number: string;
    issue_date: string | null;
    due_date: string | null;
    total: number;
    amount_due: number;
    status: string;
    is_overdue: boolean;
    days_overdue: number;
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
    };
    filters: {
        status: string;
        from: string | null;
        to: string | null;
        client: string | null;
        min_amount: number | null;
        max_amount: number | null;
    };
}>();

const selected = ref<number[]>([]);
const paymentDrawerOpen = ref(false);
const selectedInvoice = ref<InvoiceRow | null>(null);

const localFilters = ref({
    status: props.filters.status ?? 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    client: props.filters.client ?? '',
    min_amount: props.filters.min_amount?.toString() ?? '',
    max_amount: props.filters.max_amount?.toString() ?? '',
});

const statusOptions = [
    { label: 'All', value: 'all' },
    { label: 'Draft', value: 'draft' },
    { label: 'Sent', value: 'sent' },
    { label: 'Overdue', value: 'overdue' },
    { label: 'Paid', value: 'paid' },
    { label: 'Void', value: 'void' },
];

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyFilters = () => {
    router.get(route('invoicing.invoices.index'), {
        ...localFilters.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const clearFilters = () => {
    localFilters.value = {
        status: 'all',
        from: '',
        to: '',
        client: '',
        min_amount: '',
        max_amount: '',
    };
    applyFilters();
};

const navigateToPage = (page: number) => {
    router.get(route('invoicing.invoices.index'), {
        ...localFilters.value,
        page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const rowActionItems = (invoice: InvoiceRow) => {
    const actions = [{ id: 'view', label: 'View' }];
    if (invoice.status === 'draft') actions.push({ id: 'send', label: 'Send' });
    if (invoice.status !== 'paid' && invoice.status !== 'void') actions.push({ id: 'record_payment', label: 'Record Payment' });
    if (invoice.status === 'draft' || invoice.status === 'sent') actions.push({ id: 'void', label: 'Void' });
    if (invoice.status === 'void') actions.push({ id: 'unvoid', label: 'Restore' });
    return actions;
};

const onAction = (invoice: InvoiceRow, actionId: string) => {
    if (actionId === 'view') {
        router.visit(route('invoicing.invoices.show', invoice.id));
    } else if (actionId === 'send') {
        router.post(route('invoicing.invoices.send', invoice.id));
    } else if (actionId === 'void') {
        router.post(route('invoicing.invoices.void', invoice.id));
    } else if (actionId === 'unvoid') {
        router.post(route('invoicing.invoices.unvoid', invoice.id));
    } else if (actionId === 'record_payment') {
        selectedInvoice.value = invoice;
        paymentDrawerOpen.value = true;
    }
};

const toggleSelected = (id: number, checked: boolean) => {
    if (checked) {
        if (!selected.value.includes(id)) selected.value.push(id);
        return;
    }
    selected.value = selected.value.filter((item) => item !== id);
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
        <PageHeader title="Invoices" subtitle="Track and manage money in">
            <template #actions>
                <Link
                    :href="route('invoicing.invoices.create')"
                    class="inline-flex items-center justify-center rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-400"
                >
                    New Invoice
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
                <StatCard title="Draft" :value="String(summary.draft_count)" hint="Awaiting send" trend="neutral" />
                <StatCard title="Sent" :value="String(summary.sent_count)" hint="Awaiting payment" trend="neutral" />
                <StatCard title="Overdue" :value="String(summary.overdue_count)" hint="Invoices past due" trend="down" />
                <AppCard>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Overdue Total</p>
                    <p class="mt-1 text-2xl font-semibold text-rose-600">{{ formatCents(summary.overdue_total) }}</p>
                </AppCard>
            </div>

            <AppCard>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="status in statusOptions"
                            :key="status.value"
                            type="button"
                            :class="[
                                'min-h-11 rounded-md border px-3 py-2 text-sm transition md:min-h-0 md:py-1.5',
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
                            <AppInput v-model="localFilters.min_amount" type="number" placeholder="0" />
                        </div>
                        <div class="xl:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Max amount</label>
                            <AppInput v-model="localFilters.max_amount" type="number" placeholder="0" />
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
                        <AppButton variant="secondary" size="sm" :disabled="selected.length === 0">Export selected (PDF zip)</AppButton>
                        <AppButton variant="secondary" size="sm" :disabled="selected.length === 0">Mark as sent</AppButton>
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
                            <div>
                                <p class="font-semibold text-slate-900">{{ invoice.number }}</p>
                                <p class="text-sm text-slate-600">{{ invoice.client_name }}</p>
                            </div>
                            <AppBadge
                                :variant="invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : invoice.is_overdue ? 'danger' : 'info'"
                            >
                                {{ invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void' ? 'overdue' : invoice.status }}
                            </AppBadge>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                            <span>Due <DateDisplay :value="invoice.due_date" /></span>
                            <span v-if="invoice.is_overdue" class="font-medium text-rose-700">{{ invoice.days_overdue }}d late</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 text-sm">
                            <span class="text-slate-500">Due</span>
                            <span :class="invoice.amount_due > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'">{{ formatCents(invoice.amount_due) }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2" @click.stop>
                            <AppButton
                                v-for="action in rowActionItems(invoice)"
                                :key="`m-${invoice.id}-${action.id}`"
                                size="sm"
                                variant="secondary"
                                class="min-h-10"
                                @click="onAction(invoice, action.id)"
                            >
                                {{ action.label }}
                            </AppButton>
                        </div>
                    </button>
                    <div v-if="!invoices.data.length" class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                        No invoices match. Try filters or create a new invoice.
                    </div>
                </div>

                <div class="hidden md:block">
                    <AppTable
                    :columns="[
                        { key: 'select', label: '' },
                        { key: 'number', label: 'Number', sortable: true },
                        { key: 'client', label: 'Client name', sortable: true },
                        { key: 'issue', label: 'Issue date', sortable: true },
                        { key: 'due', label: 'Due date', sortable: true },
                        { key: 'total', label: 'Total', sortable: true },
                        { key: 'amount_due', label: 'Amount Due', sortable: true },
                        { key: 'status', label: 'Status', sortable: true },
                        { key: 'actions', label: 'Actions' },
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
                        <td class="px-4 py-3" @click.stop>
                            <input
                                type="checkbox"
                                :checked="selected.includes(invoice.id)"
                                class="h-4 w-4 rounded border-slate-300"
                                @change="toggleSelected(invoice.id, ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
                        <td class="px-4 py-3">
                            <a
                                :href="route('invoicing.invoices.show', invoice.id)"
                                class="font-medium text-brand-700 hover:underline"
                                @click.stop
                            >
                                {{ invoice.number }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ invoice.client_name }}</td>
                        <td class="px-4 py-3"><DateDisplay :value="invoice.issue_date" /></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <DateDisplay :value="invoice.due_date" />
                                <AppBadge v-if="invoice.is_overdue" variant="danger">{{ invoice.days_overdue }}d</AppBadge>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ formatCents(invoice.total) }}</td>
                        <td class="px-4 py-3">
                            <span :class="invoice.amount_due > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'">
                                {{ formatCents(invoice.amount_due) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <AppBadge
                                :variant="invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : invoice.is_overdue ? 'danger' : 'info'"
                            >
                                {{ invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void' ? 'overdue' : invoice.status }}
                            </AppBadge>
                        </td>
                        <td class="px-4 py-3" @click.stop>
                            <div class="flex flex-wrap gap-1">
                                <AppButton
                                    v-for="action in rowActionItems(invoice)"
                                    :key="`${invoice.id}-${action.id}`"
                                    size="sm"
                                    variant="ghost"
                                    @click="onAction(invoice, action.id)"
                                >
                                    {{ action.label }}
                                </AppButton>
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
                    <strong>{{ selectedInvoice.client_name }}</strong>.
                </p>
                <p>Slide-over scaffold ready for payment form wiring.</p>
                <AppButton variant="primary">Continue</AppButton>
            </div>
        </aside>
    </AppLayout>
</template>
