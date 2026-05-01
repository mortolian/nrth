<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type LedgerRow = {
    id: number;
    date: string | null;
    type: string;
    reference: string | null;
    description: string | null;
    accounts_affected: string;
    total_amount: number;
    status: string;
    can_delete: boolean;
    journal_entries: Array<{
        id: number;
        account: string;
        type: string;
        amount: number;
    }>;
};

const props = defineProps<{
    transactions: {
        data: LedgerRow[];
        current_page: number;
        last_page: number;
    };
    filters: {
        from: string | null;
        to: string | null;
        type: string;
        status: string;
        account_id: number | null;
        search: string | null;
    };
    accounts: Array<{
        id: number;
        name: string;
    }>;
}>();

const page = usePage<{ errors?: Record<string, string | string[] | undefined> }>();

const transactionDeleteError = computed(() => {
    const err = page.props.errors?.transaction;
    if (err === undefined || err === null) {
        return null;
    }
    return Array.isArray(err) ? err.join(' ') : String(err);
});

const expandedRows = ref<number[]>([]);
const filters = ref({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    type: props.filters.type ?? 'all',
    status: props.filters.status ?? 'all',
    account_id: props.filters.account_id ? String(props.filters.account_id) : 'all',
    search: props.filters.search ?? '',
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyFilters = (page = 1) => {
    router.get(route('accounting.transactions.index'), {
        ...filters.value,
        account_id: filters.value.account_id === 'all' ? '' : filters.value.account_id,
        page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const toggleExpanded = (id: number) => {
    if (expandedRows.value.includes(id)) {
        expandedRows.value = expandedRows.value.filter((rowId) => rowId !== id);
        return;
    }
    expandedRows.value = [...expandedRows.value, id];
};

const rowActionItems = (row: LedgerRow) => {
    const actions = [{ id: 'view_journal', label: 'View journal entries' }];
    if (row.can_delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }
    return actions;
};

const onTransactionAction = (transaction: LedgerRow, actionId: string) => {
    if (actionId === 'view_journal') {
        toggleExpanded(transaction.id);
    } else if (actionId === 'delete') {
        if (
            !window.confirm(
                'Permanently delete this transaction? Journal lines will be removed. This cannot be undone.',
            )
        ) {
            return;
        }
        router.delete(route('accounting.transactions.destroy', transaction.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout
        title="Transactions"
        :breadcrumbs="[
            { label: 'Accounting' },
            { label: 'Transactions' },
        ]"
    >
        <PageHeader title="Transactions">
            <template #actions>
                <AppButton variant="secondary">Export to Excel</AppButton>
            </template>
        </PageHeader>

        <div
            v-if="transactionDeleteError"
            class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
            role="alert"
        >
            {{ transactionDeleteError }}
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
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                    <AppSelect
                        :model-value="filters.type"
                        :options="[
                            { label: 'All', value: 'all' },
                            { label: 'Invoice', value: 'invoice' },
                            { label: 'Payment', value: 'payment' },
                            { label: 'Expense', value: 'expense' },
                            { label: 'Transfer', value: 'transfer' },
                            { label: 'Journal Adjustment', value: 'journal_adjustment' },
                        ]"
                        @update:model-value="filters.type = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                    <AppSelect
                        :model-value="filters.status"
                        :options="[
                            { label: 'All', value: 'all' },
                            { label: 'Draft', value: 'draft' },
                            { label: 'Posted', value: 'posted' },
                            { label: 'Void', value: 'void' },
                        ]"
                        @update:model-value="filters.status = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Account</label>
                    <AppSelect
                        :model-value="filters.account_id"
                        :options="[
                            { label: 'All accounts', value: 'all' },
                            ...accounts.map((account) => ({ label: account.name, value: String(account.id) })),
                        ]"
                        @update:model-value="filters.account_id = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                    <AppInput v-model="filters.search" placeholder="Reference / description" />
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <AppButton variant="secondary" @click="applyFilters()">Apply filters</AppButton>
                <AppButton
                    variant="ghost"
                    @click="filters = { from: '', to: '', type: 'all', status: 'all', account_id: 'all', search: '' }; applyFilters()"
                >
                    Clear
                </AppButton>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'type', label: 'Type' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'description', label: 'Description' },
                    { key: 'accounts', label: 'Accounts affected' },
                    { key: 'amount', label: 'Amount' },
                    { key: 'status', label: 'Status' },
                    { key: 'actions', label: '', widthClass: 'w-[1%] whitespace-nowrap text-right' },
                ]"
                :page="transactions.current_page"
                :last-page="transactions.last_page"
                @page-change="applyFilters"
            >
                <template v-for="transaction in transactions.data" :key="transaction.id">
                    <tr class="cursor-pointer hover:bg-slate-50" @click="toggleExpanded(transaction.id)">
                        <td class="px-4 py-3">{{ transaction.date || '-' }}</td>
                        <td class="px-4 py-3"><AppBadge variant="info">{{ transaction.type }}</AppBadge></td>
                        <td class="px-4 py-3">
                            <a class="text-brand-700 hover:underline" href="#" @click.stop>{{ transaction.reference || '—' }}</a>
                        </td>
                        <td class="px-4 py-3">{{ transaction.description || '—' }}</td>
                        <td class="px-4 py-3">{{ transaction.accounts_affected }}</td>
                        <td class="px-4 py-3">{{ formatCents(transaction.total_amount) }}</td>
                        <td class="px-4 py-3">
                            <AppBadge
                                :variant="transaction.status === 'posted' ? 'success' : transaction.status === 'draft' ? 'warning' : 'neutral'"
                                :class="transaction.status === 'void' ? 'line-through' : ''"
                            >
                                {{ transaction.status }}
                            </AppBadge>
                        </td>
                        <td class="px-4 py-3 text-right align-middle" @click.stop>
                            <div class="inline-flex justify-end">
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(transaction)"
                                    :aria-label="`Actions for transaction ${transaction.reference || transaction.id}`"
                                    @select="(id) => onTransactionAction(transaction, id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="expandedRows.includes(transaction.id)">
                        <td colspan="8" class="bg-slate-50 px-4 py-3">
                            <div class="rounded-md border border-slate-200 bg-white">
                                <table class="min-w-full text-sm">
                                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Account</th>
                                            <th class="px-3 py-2 text-left">Type</th>
                                            <th class="px-3 py-2 text-left">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="line in transaction.journal_entries" :key="line.id" class="border-b border-slate-100 last:border-0">
                                            <td class="px-3 py-2">{{ line.account }}</td>
                                            <td class="px-3 py-2">{{ line.type === 'debit' ? 'Dr' : 'Cr' }}</td>
                                            <td class="px-3 py-2">{{ formatCents(line.amount) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!transactions.data.length">
                    <td colspan="8" class="px-4 py-6">
                        <EmptyState title="No transactions found" description="Try broadening your filters." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
