<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useMoneyInTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const moneyInTabs = useMoneyInTabs();
const page = usePage();
const canTeam = (permission: string) => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes(permission);
};
const canManage = computed(() => canTeam('clients.manage'));

type ClientRow = {
    id: number;
    name: string;
    contact_name: string | null;
    email: string | null;
    status: 'active' | 'inactive';
    outstanding_balance_cents: number;
    last_invoice_date: string | null;
};

const props = defineProps<{
    clients: {
        data: ClientRow[];
        current_page: number;
        last_page: number;
    };
    filters: {
        search: string | null;
        status: 'active' | 'inactive';
    };
}>();

const filters = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'active',
});

const businessCurrency = computed(() => {
    const fromPage = page.props.business_currency;
    return typeof fromPage === 'string' && fromPage.trim() !== '' ? fromPage : 'ZAR';
});

const applyFilters = (pageNum = 1) => {
    router.get(
        route('invoicing.clients.index'),
        {
            search: filters.value.search || undefined,
            status: filters.value.status,
            page: pageNum,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    filters.value = { search: '', status: 'active' };
    applyFilters();
};

const goToClient = (id: number) => router.visit(route('invoicing.clients.show', id));

const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, businessCurrency.value);

const rowActions = (client: ClientRow) => {
    const actions = [{ id: 'view', label: 'View' }];
    if (canManage.value) {
        actions.push({ id: 'edit', label: 'Edit' });
    }
    return actions;
};

const onAction = (client: ClientRow, actionId: string) => {
    if (actionId === 'view') {
        goToClient(client.id);
        return;
    }
    if (actionId === 'edit') {
        router.visit(route('invoicing.clients.edit', client.id));
    }
};

const hasRows = computed(() => props.clients.data.length > 0);
</script>

<template>
    <FeatureShell
        title="Money In"
        section="clients"
        :tabs="moneyInTabs"
        document-title="Clients"
        subtitle="People and companies you invoice"
    >
        <template #actions>
            <AppButton
                v-if="canManage"
                variant="primary"
                @click="router.visit(route('invoicing.clients.create'))"
            >
                New client
            </AppButton>
        </template>

        <div class="space-y-6">
            <AppCard>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                        <AppInput
                            v-model="filters.search"
                            placeholder="Search name or email…"
                            @keydown.enter="applyFilters()"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="filters.status"
                            :options="[
                                { label: 'Active', value: 'active' },
                                { label: 'Inactive', value: 'inactive' },
                            ]"
                            @update:model-value="filters.status = $event as 'active' | 'inactive'"
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
                        { key: 'name', label: 'Client' },
                        { key: 'email', label: 'Email' },
                        { key: 'outstanding', label: 'Outstanding', align: 'right' },
                        { key: 'last_invoice', label: 'Last invoice' },
                        { key: 'status', label: 'Status' },
                        { key: 'actions', label: '' },
                    ]"
                    :page="clients.current_page"
                    :last-page="clients.last_page"
                    @page-change="applyFilters"
                >
                    <tr v-if="!hasRows">
                        <td colspan="6" class="px-4 py-10">
                            <EmptyState
                                title="No clients yet"
                                :description="
                                    filters.status === 'inactive'
                                        ? 'No inactive clients match these filters.'
                                        : 'Add a client to start creating invoices and estimates.'
                                "
                            >
                                <template v-if="canManage && filters.status === 'active'" #action>
                                    <AppButton
                                        variant="primary"
                                        @click="router.visit(route('invoicing.clients.create'))"
                                    >
                                        New client
                                    </AppButton>
                                </template>
                            </EmptyState>
                        </td>
                    </tr>
                    <tr
                        v-for="client in clients.data"
                        :key="client.id"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="goToClient(client.id)"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900">{{ client.name }}</div>
                            <div
                                v-if="client.contact_name"
                                class="mt-0.5 max-w-md truncate text-xs text-slate-500"
                            >
                                {{ client.contact_name }}
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                            {{ client.email || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">
                            {{ formatCents(client.outstanding_balance_cents) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                            <DateDisplay v-if="client.last_invoice_date" :value="client.last_invoice_date" />
                            <span v-else>—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge :variant="client.status === 'active' ? 'success' : 'neutral'">
                                {{ client.status === 'active' ? 'Active' : 'Inactive' }}
                            </AppBadge>
                        </td>
                        <td class="px-3 py-2 text-right" @click.stop>
                            <InvoiceRowActionsMenu
                                :actions="rowActions(client)"
                                :aria-label="`Actions for ${client.name}`"
                                @select="(id) => onAction(client, id)"
                            />
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </FeatureShell>
</template>
