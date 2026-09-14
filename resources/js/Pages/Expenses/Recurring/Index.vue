<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useMoneyOutTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

const moneyOutTabs = useMoneyOutTabs();
const page = usePage();
const toast = useToast();
const canManage = Array.isArray(page.props.team_permissions)
    && (page.props.team_permissions as string[]).includes('expenses.manage');
const canDelete = Array.isArray(page.props.team_permissions)
    && (page.props.team_permissions as string[]).includes('expenses.delete');

type Row = {
    id: number;
    supplier_name: string;
    category: string;
    description: string | null;
    status: string;
    frequency: string;
    next_run_date: string | null;
    generated_count: number;
    total_cents: number;
};

const props = defineProps<{
    recurring: {
        data: Row[];
        current_page: number;
        last_page: number;
    };
    summary: {
        active: number;
        on_hold: number;
        completed: number;
        due_soon: number;
    };
    filters: {
        status: string;
        search: string | null;
    };
}>();

const filters = ref({
    status: props.filters.status ?? 'all',
    search: props.filters.search ?? '',
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyFilters = (pageNum = 1) => {
    router.get(
        route('expenses.recurring.index'),
        {
            status: filters.value.status,
            search: filters.value.search || undefined,
            page: pageNum,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    filters.value = { status: 'all', search: '' };
    applyFilters();
};

const applyStatFilter = (status: string) => {
    filters.value.status = status;
    applyFilters();
};

const statusBadge = (status: string) => {
    if (status === 'active') return 'success';
    if (status === 'on_hold') return 'warning';
    if (status === 'completed') return 'neutral';
    return 'info';
};

const statusLabel = (status: string) => status.replaceAll('_', ' ');

const frequencyLabel = (frequency: string) => frequency.charAt(0).toUpperCase() + frequency.slice(1);

const rowActions = (row: Row) => {
    const actions = [
        { id: 'view', label: 'View' },
    ];
    if (canManage) {
        actions.push({ id: 'edit', label: 'Edit' });
        actions.push({ id: 'generate', label: 'Generate now' });
        if (row.status === 'active') {
            actions.push({ id: 'pause', label: 'Pause' });
        }
        if (row.status === 'on_hold') {
            actions.push({ id: 'resume', label: 'Resume' });
        }
        if (row.status !== 'completed') {
            actions.push({ id: 'complete', label: 'Mark completed' });
        }
    }
    if (canDelete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }
    return actions;
};

const onAction = (row: Row, actionId: string) => {
    if (actionId === 'view') {
        router.visit(route('expenses.recurring.show', row.id));
        return;
    }
    if (actionId === 'edit') {
        router.visit(route('expenses.recurring.edit', row.id));
        return;
    }
    if (actionId === 'generate') {
        router.post(route('expenses.recurring.generate', row.id), {}, {
            onSuccess: () => toast.success('Expense generated.'),
        });
        return;
    }
    if (actionId === 'pause') {
        router.post(route('expenses.recurring.pause', row.id), {}, {
            onSuccess: () => toast.success('Paused.'),
            preserveScroll: true,
        });
        return;
    }
    if (actionId === 'resume') {
        router.post(route('expenses.recurring.resume', row.id), {}, {
            onSuccess: () => toast.success('Resumed.'),
            preserveScroll: true,
        });
        return;
    }
    if (actionId === 'complete') {
        router.post(route('expenses.recurring.complete', row.id), {}, {
            onSuccess: () => toast.success('Marked completed.'),
            preserveScroll: true,
        });
        return;
    }
    if (actionId === 'delete') {
        if (!confirm(`Delete recurring schedule for ${row.supplier_name}? Generated expenses are kept.`)) {
            return;
        }
        router.delete(route('expenses.recurring.destroy', row.id), {
            onSuccess: () => toast.success('Recurring expense deleted.'),
        });
    }
};

const hasRows = computed(() => props.recurring.data.length > 0);
</script>

<template>
    <FeatureShell
        title="Money Out"
        section="recurring"
        :tabs="moneyOutTabs"
        document-title="Recurring expenses"
    >
        <template #actions>
            <AppButton
                v-if="canManage"
                variant="primary"
                @click="router.visit(route('expenses.recurring.create'))"
            >
                New recurring
            </AppButton>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <button type="button" class="text-left" @click="applyStatFilter('active')">
                    <StatCard
                        title="Active"
                        :value="String(summary.active)"
                        hint="Running on schedule"
                        trend="up"
                    />
                </button>
                <button type="button" class="text-left" @click="applyStatFilter('on_hold')">
                    <StatCard
                        title="On hold"
                        :value="String(summary.on_hold)"
                        hint="Paused templates"
                        trend="neutral"
                    />
                </button>
                <button type="button" class="text-left" @click="applyStatFilter('completed')">
                    <StatCard
                        title="Completed"
                        :value="String(summary.completed)"
                        hint="Finished schedules"
                        trend="neutral"
                    />
                </button>
                <StatCard
                    title="Due in 7 days"
                    :value="String(summary.due_soon)"
                    hint="Active next runs"
                    :trend="summary.due_soon > 0 ? 'down' : 'neutral'"
                />
            </div>

            <AppCard>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                        <AppInput
                            v-model="filters.search"
                            placeholder="Search by supplier, description, or reference…"
                            @keydown.enter="applyFilters()"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="filters.status"
                            :options="[
                                { label: 'All statuses', value: 'all' },
                                { label: 'Active', value: 'active' },
                                { label: 'On hold', value: 'on_hold' },
                                { label: 'Completed', value: 'completed' },
                            ]"
                            @update:model-value="filters.status = String($event)"
                        />
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <AppButton size="sm" variant="secondary" @click="applyFilters()">Apply</AppButton>
                    <AppButton size="sm" variant="ghost" @click="clearFilters">Clear</AppButton>
                </div>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <AppTable
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'supplier', label: 'Supplier' },
                        { key: 'category', label: 'Category' },
                        { key: 'frequency', label: 'Frequency' },
                        { key: 'next_run', label: 'Next run' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                        { key: 'status', label: 'Status' },
                        { key: 'actions', label: '' },
                    ]"
                    :page="recurring.current_page"
                    :last-page="recurring.last_page"
                    @page-change="applyFilters"
                >
                    <tr v-if="!hasRows">
                        <td colspan="7" class="px-4 py-10">
                            <EmptyState
                                title="No recurring expenses"
                                description="Set up rent, subscriptions, and other repeating bills once — nrth posts each cycle."
                            >
                                <template v-if="canManage" #action>
                                    <AppButton
                                        variant="primary"
                                        @click="router.visit(route('expenses.recurring.create'))"
                                    >
                                        New recurring
                                    </AppButton>
                                </template>
                            </EmptyState>
                        </td>
                    </tr>
                    <tr
                        v-for="row in recurring.data"
                        :key="row.id"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="router.visit(route('expenses.recurring.show', row.id))"
                    >
                        <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">
                            {{ row.supplier_name }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                            {{ row.category }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                            {{ frequencyLabel(row.frequency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">
                            {{ row.next_run_date || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">
                            {{ formatCents(row.total_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge :variant="statusBadge(row.status)" class="capitalize">
                                {{ statusLabel(row.status) }}
                            </AppBadge>
                        </td>
                        <td class="px-3 py-2 text-right" @click.stop>
                            <InvoiceRowActionsMenu
                                :actions="rowActions(row)"
                                :aria-label="`Actions for ${row.supplier_name}`"
                                @select="(id) => onAction(row, id)"
                            />
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </FeatureShell>
</template>
