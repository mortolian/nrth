<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Paperclip, TriangleAlert } from 'lucide-vue-next';

type ExpenseHistoryRow = {
    id: number;
    date: string | null;
    category: string;
    description: string | null;
    amount_cents: number;
    vat_amount_cents: number;
    status: string;
    has_receipt: boolean;
    can_delete: boolean;
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

const receiptAttachTransactionId = ref<number | null>(null);
const receiptAttachInput = ref<HTMLInputElement | null>(null);
const receiptAttachBusy = ref(false);

const startAttachReceipt = (id: number) => {
    if (receiptAttachBusy.value) {
        return;
    }
    receiptAttachTransactionId.value = id;
    receiptAttachInput.value?.click();
};

const onReceiptFileSelected = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const id = receiptAttachTransactionId.value;
    if (!file || id == null || receiptAttachBusy.value) {
        input.value = '';
        receiptAttachTransactionId.value = null;
        return;
    }

    receiptAttachBusy.value = true;
    const form = new FormData();
    form.append('receipt', file);
    router.post(route('expenses.receipt.store', id), form, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            receiptAttachBusy.value = false;
            receiptAttachTransactionId.value = null;
            input.value = '';
        },
    });
};

const confirmDeleteExpense = (expense: ExpenseHistoryRow) => {
    if (!expense.can_delete) return;
    if (!confirm(`Delete expense from ${expense.date ?? 'this date'}? This removes the journal entry.`)) return;
    router.delete(route('expenses.destroy', expense.id), { preserveScroll: true });
};

const rowActionItems = (expense: ExpenseHistoryRow) => {
    const actions = [
        { id: 'edit', label: 'Edit' },
        { id: 'attach_receipt', label: 'Attach receipt' },
    ];
    if (expense.can_delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }

    return actions;
};

const onRowAction = (expense: ExpenseHistoryRow, actionId: string) => {
    if (actionId === 'edit') {
        router.visit(route('expenses.edit', expense.id));
        return;
    }
    if (actionId === 'attach_receipt') {
        startAttachReceipt(expense.id);
        return;
    }
    if (actionId === 'delete') {
        confirmDeleteExpense(expense);
    }
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
                <AppButton
                    variant="secondary"
                    @click="router.visit(route('expenses.create', { supplier_id: supplier.id }))"
                >
                    New Expense
                </AppButton>
                <AppButton variant="primary" @click="router.visit(route('suppliers.edit', supplier.id))">Edit Supplier</AppButton>
                <AppButton v-if="canDelete" variant="ghost" class="text-rose-600 hover:bg-rose-50" @click="deleteSupplier">Delete</AppButton>
            </template>
        </PageHeader>

        <input
            ref="receiptAttachInput"
            type="file"
            accept="image/*,.pdf"
            class="hidden"
            @change="onReceiptFileSelected"
        >

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

        <AppCard class="mt-5 overflow-hidden p-0">
            <div class="flex flex-col gap-2 border-b border-canvas-200 bg-canvas-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-base font-semibold text-slate-900">Expenses</h3>
                <Link :href="route('expenses.index', { supplier: supplier.name })" class="text-sm font-medium text-brand-600 hover:underline">
                    View in expense list
                </Link>
            </div>
            <AppTable
                embedded
                dense
                table-class="text-sm"
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'category', label: 'Category' },
                    { key: 'description', label: 'Description' },
                    { key: 'amount', label: 'Amount (excl VAT)' },
                    { key: 'vat', label: 'VAT' },
                    { key: 'receipt', label: 'Receipt' },
                    { key: 'actions', label: '' },
                ]"
                :page="expense_history.current_page"
                :last-page="expense_history.last_page"
                @page-change="goHistoryPage"
            >
                <tr
                    v-for="row in expense_history.data"
                    :key="row.id"
                    class="cursor-pointer hover:bg-slate-50"
                    role="link"
                    tabindex="0"
                    :aria-label="`Edit expense from ${row.date ?? 'this date'}`"
                    @click="router.visit(route('expenses.edit', row.id))"
                    @keydown.enter.prevent="router.visit(route('expenses.edit', row.id))"
                >
                    <td class="whitespace-nowrap px-3 py-2">{{ row.date || '-' }}</td>
                    <td class="whitespace-nowrap px-3 py-2"><AppBadge variant="info">{{ row.category }}</AppBadge></td>
                    <td class="px-3 py-2">{{ row.description || '-' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatCents(row.amount_cents, stats.currency) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                        <span :class="row.vat_amount_cents > 0 ? 'font-medium text-brand-600' : 'text-slate-500'">
                            {{ formatCents(row.vat_amount_cents, stats.currency) }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        <Paperclip v-if="row.has_receipt" class="h-4 w-4 text-slate-600" />
                        <TriangleAlert v-else class="h-4 w-4 text-rose-500" />
                    </td>
                    <td class="px-3 py-2" @click.stop>
                        <div class="flex justify-end">
                            <InvoiceRowActionsMenu
                                :actions="rowActionItems(row)"
                                :aria-label="`Actions for expense ${row.description || row.id}`"
                                @select="(id) => onRowAction(row, id)"
                            />
                        </div>
                    </td>
                </tr>
                <tr v-if="!expense_history.data.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState title="No expenses yet" description="Expenses linked to this supplier will show up here." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
