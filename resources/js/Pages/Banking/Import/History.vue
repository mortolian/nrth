<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import type { RowActionItem } from '@/Components/InvoiceRowActionsMenu.vue';
import { useBankingTabs } from '@/Composables/useFeatureTabs';

const bankingTabs = useBankingTabs();

type ImportRow = {
    id: number;
    original_filename: string;
    file_type: string;
    status: string;
    status_label: string;
    total_rows: number | null;
    imported_rows: number | null;
    duplicate_rows: number | null;
    failed_rows: number | null;
    can_undo: boolean;
    can_reimport: boolean;
    can_delete: boolean;
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

type StatusOption = {
    value: string;
    label: string;
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
        status: string | null;
    };
    status_options: StatusOption[];
}>();

const filters = ref({
    account_id: props.filters.account_id ? String(props.filters.account_id) : 'all',
    status: props.filters.status ?? 'all',
});

const hasActiveFilters = computed(() =>
    filters.value.account_id !== 'all' || filters.value.status !== 'all',
);

const accountLabel = (account: ImportRow['account'] | AccountOption) =>
    account.bank_name ? `${account.name} (${account.bank_name})` : account.name;

const fileTypeLabel = (fileType: string) => fileType.toUpperCase();

const fileTypeVariant = (fileType: string) => {
    if (fileType === 'ofx') {
        return 'accent';
    }
    if (fileType === 'csv' || fileType === 'txt') {
        return 'info';
    }
    return 'neutral';
};

const statusVariant = (status: string) => {
    if (status === 'imported') {
        return 'success';
    }
    if (status === 'undone') {
        return 'warning';
    }
    if (status === 'failed') {
        return 'danger';
    }
    if (status === 'parsed') {
        return 'info';
    }
    return 'neutral';
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
        status: filters.value.status === 'all' ? undefined : filters.value.status,
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

const deleteImport = (row: ImportRow) => {
    if (!row.can_delete) {
        return;
    }
    if (!window.confirm(`Permanently delete “${row.original_filename}”? The statement file will be removed and you will not be able to re-import it from history.`)) {
        return;
    }
    router.delete(route('banking.imports.destroy', row.id));
};

const rowActions = (row: ImportRow): RowActionItem[] => {
    const actions: RowActionItem[] = [];
    if (row.can_undo) {
        actions.push({ id: 'undo', label: 'Undo' });
    }
    if (row.can_reimport) {
        actions.push({ id: 'reimport', label: 'Re-import' });
    }
    if (row.can_delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }
    return actions;
};

const onRowAction = (row: ImportRow, actionId: string) => {
    if (actionId === 'undo') {
        undoImport(row);
        return;
    }
    if (actionId === 'reimport') {
        reimportStatement(row);
        return;
    }
    if (actionId === 'delete') {
        deleteImport(row);
    }
};
</script>

<template>
    <FeatureShell
        title="Banking"
        section="import-history"
        :tabs="bankingTabs"
        document-title="Import history"
        subtitle="Undo keeps the statement file so you can re-import later, or delete it when you no longer need it."
    >
        <AppCard class="overflow-hidden p-0">
            <form class="border-b border-slate-100 px-5 py-4" @submit.prevent="applyFilters()">
                <div class="grid gap-3 md:grid-cols-2">
                    <div>
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
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="filters.status"
                            :options="[
                                { label: 'All statuses', value: 'all' },
                                ...status_options.map((option) => ({
                                    label: option.label,
                                    value: option.value,
                                })),
                            ]"
                            @update:model-value="(value) => { filters.status = value; applyFilters(); }"
                        />
                    </div>
                </div>
            </form>

            <AppTable
                v-if="imports.data.length"
                embedded
                dense
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
                    <td class="px-3 py-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-medium text-slate-900" :title="row.original_filename">
                                {{ row.original_filename }}
                            </span>
                            <AppBadge :variant="fileTypeVariant(row.file_type)">
                                {{ fileTypeLabel(row.file_type) }}
                            </AppBadge>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-slate-600">{{ accountLabel(row.account) }}</td>
                    <td class="px-3 py-2">
                        <AppBadge :variant="statusVariant(row.status)">
                            {{ row.status_label }}
                        </AppBadge>
                    </td>
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
                    <td class="px-3 py-2 text-right" @click.stop>
                        <div v-if="rowActions(row).length" class="inline-flex justify-end">
                            <InvoiceRowActionsMenu
                                :actions="rowActions(row)"
                                :aria-label="`Actions for ${row.original_filename}`"
                                @select="(actionId) => onRowAction(row, actionId)"
                            />
                        </div>
                    </td>
                </tr>
            </AppTable>

            <div v-else class="px-5 py-8">
                <EmptyState
                    :title="hasActiveFilters ? 'No imports match' : 'No imports yet'"
                    :description="hasActiveFilters
                        ? 'Try a different account or status filter.'
                        : 'Imported bank statements will appear here. Undo keeps the file so you can re-import later, or delete it from history when you no longer need it.'"
                >
                    <template v-if="!hasActiveFilters" #action>
                        <AppButton variant="primary" @click="router.visit(route('banking.import.create'))">
                            Import statement
                        </AppButton>
                    </template>
                </EmptyState>
            </div>

            <div
                v-if="imports.last_page > 1"
                class="flex items-center justify-between border-t border-slate-100 px-5 py-4"
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
    </FeatureShell>
</template>
