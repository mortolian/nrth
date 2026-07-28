<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useMoneyInTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

const moneyInTabs = useMoneyInTabs();
const page = usePage();
const toast = useToast();
const canTeam = (permission: string) => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes(permission);
};
const canManage = computed(() => canTeam('items.manage'));
const canDelete = computed(() => canTeam('items.delete'));

type ItemRow = {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    unit_price_cents: number;
    default_vat_rate: number | null;
    is_active: boolean;
};

const props = defineProps<{
    items: {
        data: ItemRow[];
        current_page: number;
        last_page: number;
    };
    summary: {
        total: number;
        active: number;
        inactive: number;
    };
    filters: {
        search: string | null;
        status: 'all' | 'active' | 'inactive';
    };
    default_currency: string;
}>();

const filters = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
});

const applyFilters = (pageNum = 1) => {
    router.get(
        route('invoicing.items.index'),
        {
            search: filters.value.search || undefined,
            status: filters.value.status,
            page: pageNum,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    filters.value = { search: '', status: 'all' };
    applyFilters();
};

const applyStatFilter = (status: 'all' | 'active' | 'inactive') => {
    filters.value.status = status;
    applyFilters();
};

const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, props.default_currency || 'ZAR');

const formatVat = (rate: number | null) => {
    if (rate === null || rate === undefined) return '—';
    return `${(Number(rate) * 100).toFixed(Number(rate) * 100 % 1 === 0 ? 0 : 2)}%`;
};

const rowActions = (item: ItemRow) => {
    const actions = [{ id: 'view', label: 'View' }];
    if (canManage.value) {
        actions.push({ id: 'edit', label: 'Edit' });
    }
    if (canDelete.value) {
        actions.push({ id: 'delete', label: 'Delete' });
    }
    return actions;
};

const onAction = (item: ItemRow, actionId: string) => {
    if (actionId === 'view') {
        router.visit(route('invoicing.items.show', item.id));
        return;
    }
    if (actionId === 'edit') {
        router.visit(route('invoicing.items.edit', item.id));
        return;
    }
    if (actionId === 'delete') {
        if (!confirm(`Delete “${item.name}”? Existing invoice lines keep their text and price.`)) {
            return;
        }
        router.delete(route('invoicing.items.destroy', item.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Item deleted.'),
        });
    }
};

const hasRows = computed(() => props.items.data.length > 0);
</script>

<template>
    <FeatureShell
        title="Money In"
        section="items"
        :tabs="moneyInTabs"
        document-title="Items"
        subtitle="Reusable products and services for invoice and estimate lines"
    >
        <template #actions>
            <AppButton
                v-if="canManage"
                variant="primary"
                @click="router.visit(route('invoicing.items.create'))"
            >
                New item
            </AppButton>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <button type="button" class="text-left" @click="applyStatFilter('all')">
                    <StatCard title="Total" :value="String(summary.total)" hint="All catalog items" trend="neutral" />
                </button>
                <button type="button" class="text-left" @click="applyStatFilter('active')">
                    <StatCard title="Active" :value="String(summary.active)" hint="Shown in pickers" trend="up" />
                </button>
                <button type="button" class="text-left" @click="applyStatFilter('inactive')">
                    <StatCard
                        title="Inactive"
                        :value="String(summary.inactive)"
                        hint="Hidden from pickers"
                        trend="neutral"
                    />
                </button>
            </div>

            <AppCard>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                        <AppInput
                            v-model="filters.search"
                            placeholder="Search name or description…"
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
                                { label: 'Inactive', value: 'inactive' },
                            ]"
                            @update:model-value="filters.status = $event as 'all' | 'active' | 'inactive'"
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
                    table-class="text-sm"
                    :columns="[
                        { key: 'name', label: 'Item' },
                        { key: 'unit', label: 'Unit' },
                        { key: 'price', label: 'Price' },
                        { key: 'vat', label: 'VAT' },
                        { key: 'status', label: 'Status' },
                        { key: 'actions', label: '' },
                    ]"
                    :page="items.current_page"
                    :last-page="items.last_page"
                    @page-change="applyFilters"
                >
                    <tr v-if="!hasRows">
                        <td colspan="6" class="px-4 py-10">
                            <EmptyState
                                title="No items yet"
                                description="Add products or services once, then pick them onto invoices and estimates."
                            >
                                <template v-if="canManage" #action>
                                    <AppButton
                                        variant="primary"
                                        @click="router.visit(route('invoicing.items.create'))"
                                    >
                                        New item
                                    </AppButton>
                                </template>
                            </EmptyState>
                        </td>
                    </tr>
                    <tr
                        v-for="item in items.data"
                        :key="item.id"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="router.visit(route('invoicing.items.show', item.id))"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900">{{ item.name }}</div>
                            <div
                                v-if="item.description"
                                class="mt-0.5 max-w-md truncate text-xs text-slate-500"
                            >
                                {{ item.description }}
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                            {{ item.unit || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-900">
                            {{ formatCents(item.unit_price_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-600">
                            {{ formatVat(item.default_vat_rate) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge :variant="item.is_active ? 'success' : 'neutral'">
                                {{ item.is_active ? 'Active' : 'Inactive' }}
                            </AppBadge>
                        </td>
                        <td class="px-3 py-2 text-right" @click.stop>
                            <InvoiceRowActionsMenu
                                :actions="rowActions(item)"
                                :aria-label="`Actions for ${item.name}`"
                                @select="(id) => onAction(item, id)"
                            />
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </FeatureShell>
</template>
