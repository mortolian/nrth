<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Paperclip, TriangleAlert } from 'lucide-vue-next';

type ExpenseRow = {
    id: number;
    date: string | null;
    supplier_id: number | null;
    supplier: string;
    category: string;
    description: string | null;
    amount: number;
    vat_amount: number;
    status: string;
    has_receipt: boolean;
    can_delete: boolean;
};

const props = defineProps<{
    expenses: {
        data: ExpenseRow[];
        current_page: number;
        last_page: number;
    };
    summary: {
        total_this_month: number;
        total_vat_claimable: number;
        awaiting_receipts: number;
    };
    categories: string[];
    filters: {
        from: string | null;
        to: string | null;
        categories: string[];
        supplier: string | null;
        has_receipt: 'yes' | 'no' | 'all';
        vat_status: 'claimable' | 'non_claimable' | 'all';
    };
}>();

const selected = ref<number[]>([]);
const filters = ref({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    categories: props.filters.categories ?? [],
    supplier: props.filters.supplier ?? '',
    has_receipt: props.filters.has_receipt ?? 'all',
    vat_status: props.filters.vat_status ?? 'all',
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyFilters = (page = 1) => {
    router.get(route('expenses.index'), {
        ...filters.value,
        categories: filters.value.categories.join(','),
        page,
    }, { preserveScroll: true, preserveState: true, replace: true });
};

const toggleCategory = (category: string) => {
    if (filters.value.categories.includes(category)) {
        filters.value.categories = filters.value.categories.filter((c) => c !== category);
        return;
    }
    filters.value.categories = [...filters.value.categories, category];
};

const toggleSelected = (id: number, checked: boolean) => {
    if (checked) {
        if (!selected.value.includes(id)) selected.value.push(id);
        return;
    }
    selected.value = selected.value.filter((item) => item !== id);
};

const pageIds = computed(() => props.expenses.data.map((expense) => expense.id));

const allPageSelected = computed(
    () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
);

const somePageSelected = computed(
    () => pageIds.value.some((id) => selected.value.includes(id)) && !allPageSelected.value,
);

const selectAllCheckbox = ref<HTMLInputElement | null>(null);

watch([allPageSelected, somePageSelected], async () => {
    await nextTick();
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = somePageSelected.value;
    }
}, { immediate: true });

const toggleSelectAllPage = (checked: boolean) => {
    if (checked) {
        selected.value = [...new Set([...selected.value, ...pageIds.value])];
        return;
    }
    const onPage = new Set(pageIds.value);
    selected.value = selected.value.filter((id) => !onPage.has(id));
};

const receiptAttachTransactionId = ref<number | null>(null);
const receiptAttachInput = ref<HTMLInputElement | null>(null);

const startAttachReceipt = (id: number) => {
    receiptAttachTransactionId.value = id;
    receiptAttachInput.value?.click();
};

const onReceiptFileSelected = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const id = receiptAttachTransactionId.value;
    if (!file || id == null) {
        input.value = '';
        receiptAttachTransactionId.value = null;
        return;
    }
    const form = new FormData();
    form.append('receipt', file);
    router.post(route('expenses.receipt.store', id), form, { preserveScroll: true });
    receiptAttachTransactionId.value = null;
    input.value = '';
};

const confirmDelete = (expense: ExpenseRow) => {
    if (!expense.can_delete) return;
    if (!confirm(`Delete expense from ${expense.date ?? 'this date'} (${expense.supplier})? This removes the journal entry.`)) return;
    router.delete(route('expenses.destroy', expense.id), { preserveScroll: true });
};

const exportSelectedCsv = () => {
    if (selected.value.length === 0) {
        return;
    }

    const params = new URLSearchParams();
    selected.value.forEach((id) => {
        params.append('ids[]', String(id));
    });
    window.location.assign(`${route('expenses.export')}?${params.toString()}`);
};

const rowActionItems = (expense: ExpenseRow) => {
    const actions = [
        { id: 'edit', label: 'Edit' },
        { id: 'attach_receipt', label: 'Attach receipt' },
    ];
    if (expense.can_delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }

    return actions;
};

const onRowAction = (expense: ExpenseRow, actionId: string) => {
    if (actionId === 'edit') {
        router.visit(route('expenses.edit', expense.id));
        return;
    }
    if (actionId === 'attach_receipt') {
        startAttachReceipt(expense.id);
        return;
    }
    if (actionId === 'delete') {
        confirmDelete(expense);
    }
};
</script>

<template>
    <AppLayout
        title="Expenses"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Expenses' },
        ]"
    >
        <PageHeader title="Expenses">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('expenses.create'))">New Expense</AppButton>
            </template>
        </PageHeader>

        <input
            ref="receiptAttachInput"
            type="file"
            accept="image/*,.pdf"
            class="hidden"
            @change="onReceiptFileSelected"
        >

        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <StatCard title="Total expenses (MTD)" :value="formatCents(summary.total_this_month)" trend="neutral" />
            <StatCard title="VAT claimable (MTD)" :value="formatCents(summary.total_vat_claimable)" trend="up" />
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Missing receipts</p>
                <p :class="summary.awaiting_receipts > 0 ? 'text-rose-600' : 'text-slate-900'" class="mt-1 text-2xl font-semibold">
                    {{ summary.awaiting_receipts }}
                </p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="filters.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="filters.to" type="date" />
                </div>
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Supplier</label>
                    <AppInput v-model="filters.supplier" placeholder="Search supplier..." />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Has receipt</label>
                    <AppSelect
                        :model-value="filters.has_receipt"
                        :options="[{ label: 'All', value: 'all' }, { label: 'Yes', value: 'yes' }, { label: 'No', value: 'no' }]"
                        @update:model-value="filters.has_receipt = $event as 'yes' | 'no' | 'all'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT status</label>
                    <AppSelect
                        :model-value="filters.vat_status"
                        :options="[{ label: 'All', value: 'all' }, { label: 'Claimable', value: 'claimable' }, { label: 'Non-claimable', value: 'non_claimable' }]"
                        @update:model-value="filters.vat_status = $event as 'claimable' | 'non_claimable' | 'all'"
                    />
                </div>
            </div>
            <div class="mt-3">
                <p class="mb-1 text-xs font-medium text-slate-500">Categories</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
                        :class="[
                            'rounded-md border px-2 py-1 text-xs',
                            filters.categories.includes(category)
                                ? 'border-brand-500 bg-brand-50 text-brand-700'
                                : 'border-slate-200 text-slate-600',
                        ]"
                        @click="toggleCategory(category)"
                    >
                        {{ category }}
                    </button>
                </div>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <AppButton variant="secondary" @click="applyFilters()">Apply filters</AppButton>
                <AppButton
                    variant="ghost"
                    @click="filters = { from: '', to: '', categories: [], supplier: '', has_receipt: 'all', vat_status: 'all' }; applyFilters()"
                >
                    Clear filters
                </AppButton>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Expense list</h3>
                <div class="flex gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="selected.length === 0"
                        @click="exportSelectedCsv"
                    >
                        Export CSV
                    </AppButton>
                </div>
            </div>

            <AppTable
                table-class="text-sm"
                :columns="[
                    { key: 'select', label: '', widthClass: 'w-10' },
                    { key: 'date', label: 'Date' },
                    { key: 'supplier', label: 'Supplier' },
                    { key: 'category', label: 'Category' },
                    { key: 'description', label: 'Description' },
                    { key: 'amount', label: 'Amount (excl VAT)' },
                    { key: 'vat_amount', label: 'VAT amount' },
                    { key: 'receipt', label: 'Receipt' },
                    { key: 'actions', label: '' },
                ]"
                :page="expenses.current_page"
                :last-page="expenses.last_page"
                @page-change="applyFilters"
            >
                <template #header-select>
                    <input
                        ref="selectAllCheckbox"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300"
                        :checked="allPageSelected"
                        :disabled="expenses.data.length === 0"
                        :aria-label="allPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
                        @change="toggleSelectAllPage(($event.target as HTMLInputElement).checked)"
                    >
                </template>
                <tr v-for="expense in expenses.data" :key="expense.id" class="hover:bg-slate-50">
                    <td class="px-3 py-2">
                        <input
                            type="checkbox"
                            :checked="selected.includes(expense.id)"
                            class="h-4 w-4 rounded border-slate-300"
                            @change="toggleSelected(expense.id, ($event.target as HTMLInputElement).checked)"
                        >
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">{{ expense.date || '-' }}</td>
                    <td class="px-3 py-2">
                        <Link
                            v-if="expense.supplier_id"
                            :href="route('suppliers.show', expense.supplier_id)"
                            class="font-medium text-brand-600 hover:underline"
                            @click.stop
                        >
                            {{ expense.supplier }}
                        </Link>
                        <span v-else>{{ expense.supplier }}</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2"><AppBadge variant="info">{{ expense.category }}</AppBadge></td>
                    <td class="px-3 py-2">{{ expense.description || '-' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatCents(expense.amount) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                        <span :class="expense.vat_amount > 0 ? 'font-medium text-brand-600' : 'text-slate-500'">
                            {{ formatCents(expense.vat_amount) }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        <Paperclip v-if="expense.has_receipt" class="h-4 w-4 text-slate-600" />
                        <TriangleAlert v-else class="h-4 w-4 text-rose-500" />
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex justify-end">
                            <InvoiceRowActionsMenu
                                :actions="rowActionItems(expense)"
                                :aria-label="`Actions for expense ${expense.supplier}`"
                                @select="(id) => onRowAction(expense, id)"
                            />
                        </div>
                    </td>
                </tr>
                <tr v-if="!expenses.data.length">
                    <td colspan="9" class="px-4 py-6">
                        <EmptyState title="No expenses found" description="Try adjusting filters or add a new expense." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
