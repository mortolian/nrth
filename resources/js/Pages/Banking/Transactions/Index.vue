<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type TransactionRow = {
    id: number;
    transaction_date: string | null;
    value_date: string | null;
    description: string;
    reference: string | null;
    amount: string;
    currency: string;
    direction: 'debit' | 'credit';
    running_balance: string | null;
    account: {
        id: number;
        name: string;
        bank_name: string | null;
    };
    import: {
        id: number;
        original_filename: string;
        imported_at: string | null;
    } | null;
};

type AccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

const props = defineProps<{
    transactions: {
        data: TransactionRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    accounts: AccountOption[];
    filters: {
        from: string | null;
        to: string | null;
        account_id: number | null;
        direction: string | null;
        search: string | null;
    };
}>();

const filters = ref({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    account_id: props.filters.account_id ? String(props.filters.account_id) : 'all',
    direction: props.filters.direction ?? 'all',
    search: props.filters.search ?? '',
});

const formatAmount = (row: TransactionRow) => {
    const value = Number(row.amount) || 0;
    const signed = row.direction === 'debit' ? -value : value;
    return useFormatCurrency(signed, row.currency);
};

const accountLabel = (account: TransactionRow['account']) =>
    account.bank_name ? `${account.name} (${account.bank_name})` : account.name;

const applyFilters = (page = 1) => {
    router.get(route('banking.transactions.index'), {
        from: filters.value.from || undefined,
        to: filters.value.to || undefined,
        account_id: filters.value.account_id === 'all' ? undefined : filters.value.account_id,
        direction: filters.value.direction === 'all' ? undefined : filters.value.direction,
        search: filters.value.search || undefined,
        page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const clearFilters = () => {
    filters.value = { from: '', to: '', account_id: 'all', direction: 'all', search: '' };
    applyFilters();
};
</script>

<template>
    <AppLayout
        title="Imported transactions"
        :breadcrumbs="[
            { label: 'Banking' },
            { label: 'Transactions' },
        ]"
    >
        <PageHeader title="Banking transactions">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('banking.accounts.index'))">
                    Accounts
                </AppButton>
                <AppButton variant="primary" @click="router.visit(route('banking.import.create'))">
                    Import statement
                </AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <form @submit.prevent="applyFilters()">
                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                        <AppInput v-model="filters.from" type="date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                        <AppInput v-model="filters.to" type="date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Account</label>
                        <AppSelect
                            :model-value="filters.account_id"
                            :options="[
                                { label: 'All accounts', value: 'all' },
                                ...accounts.map((a) => ({
                                    label: a.bank_name ? `${a.name} (${a.bank_name})` : a.name,
                                    value: String(a.id),
                                })),
                            ]"
                            @update:model-value="filters.account_id = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                        <AppSelect
                            :model-value="filters.direction"
                            :options="[
                                { label: 'All', value: 'all' },
                                { label: 'Debit', value: 'debit' },
                                { label: 'Credit', value: 'credit' },
                            ]"
                            @update:model-value="filters.direction = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                        <AppInput v-model="filters.search" placeholder="Description or reference..." />
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <AppButton type="submit" variant="secondary">Apply</AppButton>
                    <AppButton type="button" variant="ghost" @click="clearFilters">Clear</AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard class="mt-5">
            <p class="mb-3 text-sm text-slate-500">
                {{ transactions.total }} transaction{{ transactions.total === 1 ? '' : 's' }}
            </p>

            <div v-if="transactions.data.length" class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="px-3 py-2 font-medium text-slate-600">Date</th>
                            <th class="px-3 py-2 font-medium text-slate-600">Account</th>
                            <th class="px-3 py-2 font-medium text-slate-600">Description</th>
                            <th class="px-3 py-2 font-medium text-slate-600">Reference</th>
                            <th class="px-3 py-2 font-medium text-slate-600 text-right">Amount</th>
                            <th class="px-3 py-2 font-medium text-slate-600 text-right">Balance</th>
                            <th class="px-3 py-2 font-medium text-slate-600">Source file</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in transactions.data"
                            :key="row.id"
                            class="border-b border-slate-100 hover:bg-slate-50/80"
                        >
                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ row.transaction_date }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ accountLabel(row.account) }}</td>
                            <td class="max-w-xs truncate px-3 py-2 text-slate-900" :title="row.description">
                                {{ row.description }}
                            </td>
                            <td class="px-3 py-2 text-slate-500">{{ row.reference || '—' }}</td>
                            <td
                                class="whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums"
                                :class="row.direction === 'debit' ? 'text-red-700' : 'text-emerald-700'"
                            >
                                {{ formatAmount(row) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600 tabular-nums">
                                {{ row.running_balance != null ? useFormatCurrency(Number(row.running_balance), row.currency) : '—' }}
                            </td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-xs text-slate-500" :title="row.import?.original_filename">
                                {{ row.import?.original_filename ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="py-8 text-center text-sm text-slate-500">
                No imported transactions yet.
                <button
                    type="button"
                    class="text-brand-700 underline hover:text-brand-800"
                    @click="router.visit(route('banking.import.create'))"
                >
                    Import a statement
                </button>
                to get started.
            </p>

            <div v-if="transactions.last_page > 1" class="mt-4 flex items-center justify-between">
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="transactions.current_page <= 1"
                    @click="applyFilters(transactions.current_page - 1)"
                >
                    Previous
                </AppButton>
                <span class="text-sm text-slate-500">
                    Page {{ transactions.current_page }} of {{ transactions.last_page }}
                </span>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="transactions.current_page >= transactions.last_page"
                    @click="applyFilters(transactions.current_page + 1)"
                >
                    Next
                </AppButton>
            </div>
        </AppCard>
    </AppLayout>
</template>
