<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type QuoteRow = {
    id: number;
    number: string;
    client_name: string;
    issue_date: string;
    expiry_date: string;
    total_cents: number;
    currency: string;
    status: 'draft' | 'sent' | 'accepted' | 'expired' | 'converted';
    converted_invoice_id: number | null;
};

const props = defineProps<{
    quotes: QuoteRow[];
    summary: { draft: number; sent: number; accepted: number; expired: number };
    filters: { status: string; search: string | null };
}>();

const status = ref<'all' | QuoteRow['status']>((props.filters.status as 'all' | QuoteRow['status']) ?? 'all');
const search = ref(props.filters.search ?? '');

const rows = computed(() =>
    props.quotes.filter((q) => {
        const statusOk = status.value === 'all' || q.status === status.value;
        const term = search.value.trim().toLowerCase();
        const searchOk = !term || q.number.toLowerCase().includes(term) || q.client_name.toLowerCase().includes(term);
        return statusOk && searchOk;
    }),
);

const applyFilters = () => {
    router.get(route('invoicing.quotes.index'), {
        status: status.value,
        search: search.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const formatQuoteTotal = (cents: number, code: string) => useFormatCurrency(cents / 100, code || 'ZAR');

const badgeVariant = (value: QuoteRow['status']) => {
    if (value === 'accepted') return 'success';
    if (value === 'expired') return 'danger';
    if (value === 'converted') return 'neutral';
    return 'info';
};

const quoteActionItems = (quote: QuoteRow) => {
    const actions = [
        { id: 'view', label: 'View' },
        { id: 'edit', label: 'Edit' },
        { id: 'download_pdf', label: 'Download PDF' },
    ];

    if (quote.status === 'draft') {
        actions.push({ id: 'send', label: 'Send quote' });
        actions.push({ id: 'mark_sent', label: 'Mark as sent' });
    }
    if (quote.status === 'sent') {
        actions.push({ id: 'accept', label: 'Mark accepted' });
        actions.push({ id: 'decline', label: 'Mark declined' });
    }
    if (quote.status === 'converted' && quote.converted_invoice_id) {
        actions.push({ id: 'view_invoice', label: 'View invoice' });
    }
    actions.push({ id: 'delete', label: 'Delete quote' });

    return actions;
};

const onAction = (quote: QuoteRow, actionId: string) => {
    if (actionId === 'view') {
        router.visit(route('invoicing.quotes.show', quote.id));
    } else if (actionId === 'edit') {
        router.visit(route('invoicing.quotes.edit', quote.id));
    } else if (actionId === 'download_pdf') {
        window.location.assign(route('invoicing.quotes.pdf.download', quote.id));
    } else if (actionId === 'send') {
        router.post(route('invoicing.quotes.send', quote.id));
    } else if (actionId === 'mark_sent') {
        router.post(route('invoicing.quotes.mark-sent', quote.id));
    } else if (actionId === 'accept') {
        router.post(route('invoicing.quotes.accept', quote.id));
    } else if (actionId === 'decline') {
        router.post(route('invoicing.quotes.decline', quote.id));
    } else if (actionId === 'view_invoice' && quote.converted_invoice_id) {
        router.visit(route('invoicing.invoices.show', quote.converted_invoice_id));
    } else if (actionId === 'delete') {
        if (!window.confirm(`Permanently delete quote ${quote.number}? This cannot be undone.`)) {
            return;
        }
        router.delete(route('invoicing.quotes.destroy', quote.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout title="Quotes" :breadcrumbs="[{ label: 'Money In' }, { label: 'Quotes' }]">
        <Head title="Quotes" />

        <PageHeader title="Quotes">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('invoicing.quotes.create'))">New Quote</AppButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard title="Draft" :value="String(summary.draft)" hint="Not sent yet" trend="neutral" />
                <StatCard title="Sent" :value="String(summary.sent)" hint="Awaiting response" trend="neutral" />
                <StatCard title="Accepted" :value="String(summary.accepted)" hint="Ready to convert" trend="up" />
                <StatCard title="Expired" :value="String(summary.expired)" hint="Needs follow-up" trend="down" />
            </div>

            <AppCard>
                <div class="grid gap-3 md:grid-cols-3">
                    <AppSelect
                        :model-value="status"
                        :options="[
                            { label: 'All statuses', value: 'all' },
                            { label: 'Draft', value: 'draft' },
                            { label: 'Sent', value: 'sent' },
                            { label: 'Accepted', value: 'accepted' },
                            { label: 'Expired', value: 'expired' },
                            { label: 'Converted', value: 'converted' },
                        ]"
                        @update:model-value="status = $event"
                    />
                    <div class="md:col-span-2">
                        <AppInput v-model="search" placeholder="Search by quote number or client..." />
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <AppButton size="sm" variant="secondary" @click="applyFilters">Apply</AppButton>
                    <AppButton
                        size="sm"
                        variant="ghost"
                        @click="
                            status = 'all';
                            search = '';
                            applyFilters();
                        "
                    >
                        Clear
                    </AppButton>
                </div>
            </AppCard>

            <AppCard>
                <AppTable
                    :columns="[
                        { key: 'number', label: 'Quote' },
                        { key: 'client', label: 'Client' },
                        { key: 'issue', label: 'Issued' },
                        { key: 'expiry', label: 'Expiry' },
                        { key: 'amount', label: 'Amount' },
                        { key: 'status', label: 'Status' },
                        { key: 'actions', label: 'Actions' },
                    ]"
                    :page="1"
                    :last-page="1"
                >
                    <tr v-for="quote in rows" :key="quote.id" class="text-sm text-slate-700 hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-brand-700">
                            <a :href="route('invoicing.quotes.show', quote.id)" class="hover:underline">
                                {{ quote.number }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ quote.client_name }}</td>
                        <td class="px-4 py-3">{{ quote.issue_date }}</td>
                        <td class="px-4 py-3">{{ quote.expiry_date }}</td>
                        <td class="px-4 py-3">{{ formatQuoteTotal(quote.total_cents, quote.currency) }}</td>
                        <td class="px-4 py-3">
                            <AppBadge :variant="badgeVariant(quote.status)">{{ quote.status }}</AppBadge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="inline-flex">
                                <InvoiceRowActionsMenu
                                    :actions="quoteActionItems(quote)"
                                    :aria-label="`Actions for ${quote.number}`"
                                    @select="(id) => onAction(quote, id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No quotes match your filters.</td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>

