<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type ImportRow = {
    id: number;
    original_filename: string;
    file_type: string;
    status: string;
    total_rows: number | null;
    imported_rows: number | null;
    duplicate_rows: number | null;
    failed_rows: number | null;
    can_undo: boolean;
    can_reimport: boolean;
    created_at: string | null;
    updated_at: string | null;
    account: {
        id: number;
        name: string;
        bank_name: string | null;
        currency: string;
    };
};

type AccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

const props = defineProps<{
    imports: {
        data: ImportRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    accounts: AccountOption[];
    filters: {
        account_id: number | null;
    };
}>();

const filters = ref({
    account_id: props.filters.account_id ? String(props.filters.account_id) : 'all',
});

const accountLabel = (account: ImportRow['account'] | AccountOption) =>
    account.bank_name ? `${account.name} (${account.bank_name})` : account.name;

const statusLabel = (status: string) => {
    switch (status) {
        case 'imported':
            return 'Imported';
        case 'parsed':
            return 'Previewed';
        case 'pending':
            return 'Pending';
        case 'undone':
            return 'Undone';
        case 'failed':
            return 'Failed';
        default:
            return status;
    }
};

const formatDate = (value: string | null) => {
    if (!value) {
        return '—';
    }
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
};

const applyFilters = (page = 1) => {
    router.get(route('banking.imports.index'), {
        account_id: filters.value.account_id === 'all' ? undefined : filters.value.account_id,
        page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const undoImport = (row: ImportRow) => {
    if (!row.can_undo) {
        return;
    }
    if (!window.confirm(`Undo import of “${row.original_filename}”? All transactions from this import will be deleted. The statement file will be kept so you can re-import it later.`)) {
        return;
    }
    router.post(route('banking.imports.undo', row.id));
};

const reimportStatement = (row: ImportRow) => {
    if (!row.can_reimport) {
        return;
    }
    router.post(route('banking.imports.reimport', row.id));
};
</script>

<template>
    <AppLayout
        title="Import history"
        :breadcrumbs="[
            { label: 'Banking', href: route('banking.transactions.index') },
            { label: 'Import history' },
        ]"
    >
        <PageHeader title="Import history">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('banking.transactions.index'))">
                    Transactions
                </AppButton>
                <AppButton variant="primary" @click="router.visit(route('banking.import.create'))">
                    Import statement
                </AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <form class="mb-4 max-w-sm" @submit.prevent="applyFilters()">
                <label class="mb-1 block text-xs font-medium text-slate-500">Account</label>
                <AppSelect
                    :model-value="filters.account_id"
                    :options="[
                        { label: 'All accounts', value: 'all' },
                        ...accounts.map((a) => ({
                            label: accountLabel(a),
                            value: String(a.id),
                        })),
                    ]"
                    @update:model-value="(value) => { filters.account_id = value; applyFilters(); }"
                />
            </form>

            <AppTable
                v-if="imports.data.length"
                table-class="text-sm"
                :show-pagination="false"
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'file', label: 'File' },
                    { key: 'account', label: 'Account' },
                    { key: 'status', label: 'Status' },
                    { key: 'rows', label: 'Rows' },
                    { key: 'actions', label: '' },
                ]"
            >
                <tr v-for="row in imports.data" :key="row.id" class="border-t border-slate-100">
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ formatDate(row.created_at) }}</td>
                    <td class="px-3 py-2 text-slate-900">
                        <div class="font-medium">{{ row.original_filename }}</div>
                        <div class="text-xs uppercase text-slate-500">{{ row.file_type }}</div>
                    </td>
                    <td class="px-3 py-2 text-slate-600">{{ accountLabel(row.account) }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ statusLabel(row.status) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-600">
                        <span v-if="row.status === 'imported' || row.status === 'undone'">
                            {{ row.imported_rows ?? 0 }} imported
                            <span v-if="(row.duplicate_rows ?? 0) > 0" class="text-slate-400">
                                · {{ row.duplicate_rows }} dup
                            </span>
                        </span>
                        <span v-else>
                            {{ row.total_rows ?? '—' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex justify-end gap-2">
                            <AppButton
                                v-if="row.can_undo"
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="undoImport(row)"
                            >
                                Undo
                            </AppButton>
                            <AppButton
                                v-if="row.can_reimport"
                                type="button"
                                variant="primary"
                                size="sm"
                                @click="reimportStatement(row)"
                            >
                                Re-import
                            </AppButton>
                        </div>
                    </td>
                </tr>
            </AppTable>

            <EmptyState
                v-else
                title="No imports yet"
                description="Imported bank statements will appear here. Undo keeps the file so you can re-import later."
            >
                <template #action>
                    <AppButton variant="primary" @click="router.visit(route('banking.import.create'))">
                        Import statement
                    </AppButton>
                </template>
            </EmptyState>

            <div
                v-if="imports.last_page > 1"
                class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4"
            >
                <p class="text-sm text-slate-500">
                    Page {{ imports.current_page }} of {{ imports.last_page }} · {{ imports.total }} total
                </p>
                <div class="flex gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="imports.current_page <= 1"
                        @click="applyFilters(imports.current_page - 1)"
                    >
                        Previous
                    </AppButton>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="imports.current_page >= imports.last_page"
                        @click="applyFilters(imports.current_page + 1)"
                    >
                        Next
                    </AppButton>
                </div>
            </div>
        </AppCard>
    </AppLayout>
</template>
