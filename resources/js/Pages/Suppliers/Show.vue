<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type ExpenseHistoryRow = {
    id: number;
    date: string | null;
    category: string;
    description: string | null;
    amount_cents: number;
    vat_amount_cents: number;
    status: string;
    has_receipt: boolean;
};

const props = defineProps<{
    supplier: {
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
        notes: string | null;
        is_active: boolean;
    };
    expense_history: {
        data: ExpenseHistoryRow[];
        current_page: number;
        last_page: number;
        total?: number;
    };
    stats: {
        total_expenses_cents: number;
        expense_count: number;
        currency: string;
    };
}>();

const formatCents = (cents: number, currency: string) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const addressLines = computed(() => {
    const a = props.supplier.address;
    if (!a) return [];
    const parts = [a.street, a.city, a.province, a.postal_code, a.country].filter(
        (p): p is string => Boolean(p && String(p).trim() !== ''),
    );
    return parts;
});

const hasAddress = computed(() => addressLines.value.length > 0);

const canDelete = computed(() => props.stats.expense_count === 0);

const goHistoryPage = (page: number) => {
    router.get(route('suppliers.show', props.supplier.id), { page }, { preserveState: true, preserveScroll: true });
};

const deleteSupplier = () => {
    if (!canDelete.value || !confirm(`Delete supplier “${props.supplier.name}”? This cannot be undone.`)) return;
    router.delete(route('suppliers.destroy', props.supplier.id));
};
</script>

<template>
    <AppLayout
        :title="supplier.name"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Suppliers', href: route('suppliers.index') },
            { label: supplier.name },
        ]"
    >
        <PageHeader :title="supplier.name" subtitle="Supplier profile and expense history">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('expenses.create'))">New Expense</AppButton>
                <AppButton variant="primary" @click="router.visit(route('suppliers.edit', supplier.id))">Edit Supplier</AppButton>
                <AppButton v-if="canDelete" variant="ghost" class="text-rose-600 hover:bg-rose-50" @click="deleteSupplier">Delete</AppButton>
            </template>
        </PageHeader>

        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            <AppCard class="lg:col-span-2">
                <h3 class="text-sm font-semibold text-slate-900">Details</h3>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Contact</dt>
                        <dd class="font-medium text-slate-900">{{ supplier.contact_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Email</dt>
                        <dd class="font-medium text-slate-900">{{ supplier.email || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="font-medium text-slate-900">{{ supplier.phone || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">VAT number</dt>
                        <dd class="font-medium text-slate-900">{{ supplier.vat_number || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Registration</dt>
                        <dd class="font-medium text-slate-900">{{ supplier.registration_number || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd><AppBadge :variant="supplier.is_active ? 'success' : 'neutral'">{{ supplier.is_active ? 'active' : 'inactive' }}</AppBadge></dd>
                    </div>
                </dl>
                <div v-if="hasAddress" class="mt-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Address</h4>
                    <p class="mt-1 text-sm text-slate-800">
                        <span v-for="(line, i) in addressLines" :key="i">{{ line }}<br v-if="i < addressLines.length - 1"></span>
                    </p>
                </div>
                <div v-if="supplier.notes" class="mt-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</h4>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ supplier.notes }}</p>
                </div>
            </AppCard>

            <AppCard>
                <h3 class="text-sm font-semibold text-slate-900">Spend summary</h3>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ formatCents(stats.total_expenses_cents, stats.currency) }}</p>
                <p class="text-sm text-slate-500">Total recorded (excl. VAT lines) · {{ stats.expense_count }} expenses</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Expenses</h3>
                <Link :href="route('expenses.index', { supplier: supplier.name })" class="text-sm font-medium text-brand-600 hover:underline">
                    View in expense list
                </Link>
            </div>
            <AppTable
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'category', label: 'Category' },
                    { key: 'description', label: 'Description' },
                    { key: 'amount', label: 'Amount (excl VAT)' },
                    { key: 'vat', label: 'VAT' },
                    { key: 'receipt', label: 'Receipt' },
                ]"
                :page="expense_history.current_page"
                :last-page="expense_history.last_page"
                @page-change="goHistoryPage"
            >
                <tr v-for="row in expense_history.data" :key="row.id" class="hover:bg-slate-50">
                    <td class="px-4 py-3">{{ row.date || '-' }}</td>
                    <td class="px-4 py-3"><AppBadge variant="info">{{ row.category }}</AppBadge></td>
                    <td class="px-4 py-3">{{ row.description || '-' }}</td>
                    <td class="px-4 py-3">{{ formatCents(row.amount_cents, stats.currency) }}</td>
                    <td class="px-4 py-3">{{ formatCents(row.vat_amount_cents, stats.currency) }}</td>
                    <td class="px-4 py-3">{{ row.has_receipt ? 'Yes' : 'No' }}</td>
                </tr>
                <tr v-if="!expense_history.data.length">
                    <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">No expenses linked to this supplier yet.</td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
