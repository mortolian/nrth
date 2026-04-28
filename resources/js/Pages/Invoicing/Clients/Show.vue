<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type InvoiceHistoryRow = {
    id: number;
    number: string;
    issue_date: string | null;
    due_date: string | null;
    total_cents: number;
    amount_due_cents: number;
    status: string;
    currency: string;
    is_overdue: boolean;
    days_overdue: number;
};

const props = defineProps<{
    client: {
        id: number;
        name: string;
        contact_name: string | null;
        email: string | null;
        phone: string | null;
        vat_number: string | null;
        registration_number: string | null;
        address: {
            street?: string;
            city?: string;
            province?: string;
            postal_code?: string;
            country?: string;
        } | null;
        currency: string;
        payment_terms_days: number;
        notes: string | null;
        is_active: boolean;
    };
    invoice_history: {
        data: InvoiceHistoryRow[];
        current_page: number;
        last_page: number;
        total?: number;
    };
    stats_by_currency: Array<{
        currency: string;
        outstanding_cents: number;
        invoiced_cents: number;
        paid_cents: number;
    }>;
}>();

const formatInvoiceCents = (cents: number, currency: string) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const statusBadgeVariant = (status: string) => {
    if (status === 'paid') return 'success';
    if (status === 'void') return 'neutral';
    if (status === 'overdue') return 'danger';

    return 'info';
};

const addressLines = computed(() => {
    const a = props.client.address;
    if (!a) return [];
    const parts = [a.street, a.city, a.province, a.postal_code, a.country].filter(
        (p): p is string => Boolean(p && String(p).trim() !== ''),
    );

    return parts;
});

const hasAddress = computed(() => addressLines.value.length > 0);

const viewAllInvoicesUrl = computed(() =>
    route('invoicing.invoices.index', { client_id: props.client.id }),
);

const goHistoryPage = (page: number) => {
    router.get(route('invoicing.clients.show', props.client.id), { page }, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <AppLayout
        :title="client.name"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Clients', href: route('invoicing.clients.index') },
            { label: client.name },
        ]"
    >
        <PageHeader :title="client.name" subtitle="Client profile and invoicing performance">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('invoicing.invoices.create'))">New Invoice</AppButton>
                <AppButton variant="secondary">New Quote</AppButton>
                <AppButton variant="primary" @click="router.visit(route('invoicing.clients.edit', client.id))">Edit Client</AppButton>
            </template>
        </PageHeader>

        <div class="mt-5 space-y-4">
            <template v-if="stats_by_currency.length">
                <div
                    v-for="block in stats_by_currency"
                    :key="block.currency"
                    class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ block.currency }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500">Outstanding</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">
                                {{ formatInvoiceCents(block.outstanding_cents, block.currency) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total invoiced</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">
                                {{ formatInvoiceCents(block.invoiced_cents, block.currency) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total paid</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">
                                {{ formatInvoiceCents(block.paid_cents, block.currency) }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>
            <div v-else class="rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-6 text-center text-sm text-slate-600">
                No invoice totals yet. Amounts will appear here by currency once you create invoices.
            </div>
        </div>

        <div class="mt-6 space-y-6">
            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Client details</h3>

                <div class="mt-5 grid gap-8 lg:grid-cols-3">
                    <div class="space-y-3 lg:border-r lg:border-slate-100 lg:pr-8">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contact</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Name</dt>
                                <dd class="font-medium text-slate-900">{{ client.contact_name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Email</dt>
                                <dd class="break-words text-slate-900">{{ client.email || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Phone</dt>
                                <dd class="text-slate-900">{{ client.phone || '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="space-y-3 lg:border-r lg:border-slate-100 lg:pr-8">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Registration</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">VAT number</dt>
                                <dd class="font-mono text-slate-900">{{ client.vat_number || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Registration</dt>
                                <dd class="text-slate-900">{{ client.registration_number || '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="space-y-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoicing defaults</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Default currency</dt>
                                <dd class="font-medium text-slate-900">{{ client.currency }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Payment terms</dt>
                                <dd class="font-medium text-slate-900">{{ client.payment_terms_days }} days</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Account status</dt>
                                <dd class="mt-1">
                                    <AppBadge :variant="client.is_active ? 'success' : 'neutral'">
                                        {{ client.is_active ? 'Active' : 'Inactive' }}
                                    </AppBadge>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div v-if="hasAddress" class="mt-8 border-t border-slate-100 pt-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Address</p>
                    <div class="mt-2 space-y-0.5 text-sm leading-relaxed text-slate-800">
                        <p v-for="(line, idx) in addressLines" :key="idx">{{ line }}</p>
                    </div>
                </div>

                <div v-if="client.notes" class="mt-8 border-t border-slate-100 pt-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</p>
                    <p class="mt-2 max-w-3xl whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ client.notes }}</p>
                </div>
            </AppCard>

            <AppCard>
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-base font-semibold text-slate-900">Invoice history</h3>
                    <Link
                        :href="viewAllInvoicesUrl"
                        class="shrink-0 text-sm font-medium text-brand-700 hover:text-brand-600 hover:underline"
                    >
                        View in invoice list
                    </Link>
                </div>
                <p v-if="invoice_history.last_page > 1" class="mb-4 text-xs text-slate-500">
                    Page {{ invoice_history.current_page }} of {{ invoice_history.last_page }} · 25 per page
                </p>

                <div class="mb-4 space-y-3 md:hidden">
                    <button
                        v-for="invoice in invoice_history.data"
                        :key="`m-${invoice.id}`"
                        type="button"
                        class="w-full rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm active:bg-slate-50"
                        @click="router.visit(route('invoicing.invoices.show', invoice.id))"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ invoice.number }}</p>
                                <p class="text-xs text-slate-500">
                                    Issued <DateDisplay :value="invoice.issue_date" />
                                </p>
                            </div>
                            <AppBadge :variant="statusBadgeVariant(invoice.status)">
                                {{
                                    invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void'
                                        ? 'overdue'
                                        : invoice.status
                                }}
                            </AppBadge>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                            <span>Due <DateDisplay :value="invoice.due_date" /></span>
                            <span v-if="invoice.is_overdue" class="font-medium text-rose-700">{{ invoice.days_overdue }}d late</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 text-sm">
                            <span class="text-slate-500">Outstanding</span>
                            <span
                                :class="invoice.amount_due_cents > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'"
                            >{{ formatInvoiceCents(invoice.amount_due_cents, invoice.currency) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-xs text-slate-500">
                            <span>Total</span>
                            <span class="tabular-nums">{{ formatInvoiceCents(invoice.total_cents, invoice.currency) }}</span>
                        </div>
                    </button>
                    <div
                        v-if="!invoice_history.data.length"
                        class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500"
                    >
                        No invoices yet. Create the first invoice for this client.
                    </div>
                </div>

                <div class="hidden md:block">
                    <AppTable
                        table-class="min-w-[720px]"
                        :show-pagination="invoice_history.last_page > 1"
                        :columns="[
                            { key: 'number', label: 'Invoice #', widthClass: 'whitespace-nowrap' },
                            { key: 'issue_date', label: 'Issued', widthClass: 'whitespace-nowrap' },
                            { key: 'due_date', label: 'Due', widthClass: 'whitespace-nowrap' },
                            { key: 'total', label: 'Total', widthClass: 'whitespace-nowrap text-right tabular-nums' },
                            { key: 'amount_due', label: 'Outstanding', widthClass: 'whitespace-nowrap text-right tabular-nums' },
                            { key: 'status', label: 'Status', widthClass: 'whitespace-nowrap' },
                        ]"
                        :page="invoice_history.current_page"
                        :last-page="invoice_history.last_page"
                        @page-change="goHistoryPage"
                    >
                        <tr
                            v-for="invoice in invoice_history.data"
                            :key="invoice.id"
                            class="cursor-pointer text-sm text-slate-700 hover:bg-slate-50"
                            @click="router.visit(route('invoicing.invoices.show', invoice.id))"
                        >
                            <td class="whitespace-nowrap px-3 py-3 align-middle">
                                <span class="font-medium text-brand-700">{{ invoice.number }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 align-middle text-slate-700">
                                <DateDisplay :value="invoice.issue_date" />
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 align-middle text-slate-700">
                                <div class="flex flex-nowrap items-center gap-1.5">
                                    <DateDisplay :value="invoice.due_date" />
                                    <AppBadge v-if="invoice.is_overdue" variant="danger">{{ invoice.days_overdue }}d</AppBadge>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-right align-middle tabular-nums text-slate-700">
                                {{ formatInvoiceCents(invoice.total_cents, invoice.currency) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-right align-middle tabular-nums">
                                <span
                                    :class="invoice.amount_due_cents > 0 ? 'font-semibold text-slate-900' : 'text-slate-500'"
                                >
                                    {{ formatInvoiceCents(invoice.amount_due_cents, invoice.currency) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 align-middle">
                                <AppBadge :variant="statusBadgeVariant(invoice.status)">
                                    {{
                                        invoice.is_overdue && invoice.status !== 'paid' && invoice.status !== 'void'
                                            ? 'overdue'
                                            : invoice.status
                                    }}
                                </AppBadge>
                            </td>
                        </tr>
                        <tr v-if="!invoice_history.data.length">
                            <td colspan="6" class="px-4 py-5">
                                <EmptyState title="No invoices yet" description="Create the first invoice for this client.">
                                    <template #action>
                                        <AppButton variant="primary" @click="router.visit(route('invoicing.invoices.create'))">
                                            New Invoice
                                        </AppButton>
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                    </AppTable>
                </div>
            </AppCard>
        </div>
    </AppLayout>
</template>
