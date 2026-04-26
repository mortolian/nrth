<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

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
    invoice_history: Array<{
        id: number;
        number: string;
        issue_date: string | null;
        due_date: string | null;
        total_cents: number;
        amount_due_cents: number;
        status: string;
    }>;
    stats: {
        outstanding_balance_cents: number;
        total_invoiced_cents: number;
        total_paid_cents: number;
    };
}>();

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
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

        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <StatCard title="Outstanding balance" :value="formatCents(stats.outstanding_balance_cents)" trend="neutral" />
            <StatCard title="Total invoiced" :value="formatCents(stats.total_invoiced_cents)" trend="neutral" />
            <StatCard title="Total paid" :value="formatCents(stats.total_paid_cents)" trend="up" />
        </div>

        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <AppCard class="xl:col-span-1">
                <h3 class="text-base font-semibold text-slate-900">Client details</h3>
                <div class="mt-3 space-y-2 text-sm">
                    <p><span class="text-slate-500">Contact:</span> {{ client.contact_name || '-' }}</p>
                    <p><span class="text-slate-500">Email:</span> {{ client.email || '-' }}</p>
                    <p><span class="text-slate-500">Phone:</span> {{ client.phone || '-' }}</p>
                    <p><span class="text-slate-500">VAT:</span> {{ client.vat_number || '-' }}</p>
                    <p><span class="text-slate-500">Registration:</span> {{ client.registration_number || '-' }}</p>
                    <p><span class="text-slate-500">Payment terms:</span> {{ client.payment_terms_days }} days</p>
                    <p><span class="text-slate-500">Status:</span> <AppBadge :variant="client.is_active ? 'success' : 'neutral'">{{ client.is_active ? 'active' : 'inactive' }}</AppBadge></p>
                </div>
            </AppCard>

            <AppCard class="xl:col-span-2">
                <h3 class="mb-3 text-base font-semibold text-slate-900">Invoice history</h3>
                <AppTable
                    :columns="[
                        { key: 'number', label: 'Number' },
                        { key: 'issue_date', label: 'Issue date' },
                        { key: 'due_date', label: 'Due date' },
                        { key: 'total', label: 'Total' },
                        { key: 'amount_due', label: 'Amount due' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :page="1"
                    :last-page="1"
                >
                    <tr v-for="invoice in invoice_history" :key="invoice.id" class="cursor-pointer hover:bg-slate-50" @click="router.visit(route('invoicing.invoices.show', invoice.id))">
                        <td class="px-4 py-3 font-medium text-emerald-700">{{ invoice.number }}</td>
                        <td class="px-4 py-3">{{ invoice.issue_date || '-' }}</td>
                        <td class="px-4 py-3">{{ invoice.due_date || '-' }}</td>
                        <td class="px-4 py-3">{{ formatCents(invoice.total_cents) }}</td>
                        <td class="px-4 py-3 font-medium">{{ formatCents(invoice.amount_due_cents) }}</td>
                        <td class="px-4 py-3"><AppBadge :variant="invoice.status === 'paid' ? 'success' : invoice.status === 'void' ? 'neutral' : 'info'">{{ invoice.status }}</AppBadge></td>
                    </tr>
                    <tr v-if="!invoice_history.length">
                        <td colspan="6" class="px-4 py-5">
                            <EmptyState title="No invoices yet" description="Create the first invoice for this client.">
                                <template #action>
                                    <AppButton variant="primary" @click="router.visit(route('invoicing.invoices.create'))">New Invoice</AppButton>
                                </template>
                            </EmptyState>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>
