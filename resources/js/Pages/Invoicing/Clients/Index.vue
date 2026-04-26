<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

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
        status: 'all' | 'active' | 'inactive';
        view: 'grid' | 'table';
    };
}>();

const filters = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
    view: props.filters.view ?? 'grid',
});

const applyFilters = (page = 1) => {
    router.get(route('invoicing.clients.index'), { ...filters.value, page }, { preserveState: true, preserveScroll: true, replace: true });
};

const goToClient = (id: number) => router.visit(route('invoicing.clients.show', id));
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
</script>

<template>
    <AppLayout
        title="Clients"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Clients' },
        ]"
    >
        <PageHeader title="Clients" subtitle="Manage companies and billing contacts">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('invoicing.clients.create'))">New Client</AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                    <AppInput v-model="filters.search" placeholder="Search name or email..." />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                    <AppSelect
                        :model-value="filters.status"
                        :options="[
                            { label: 'All', value: 'all' },
                            { label: 'Active', value: 'active' },
                            { label: 'Inactive', value: 'inactive' },
                        ]"
                        @update:model-value="filters.status = $event as 'all' | 'active' | 'inactive'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">View</label>
                    <div class="flex gap-2">
                        <AppButton :variant="filters.view === 'grid' ? 'primary' : 'secondary'" size="sm" @click="filters.view = 'grid'">Grid</AppButton>
                        <AppButton :variant="filters.view === 'table' ? 'primary' : 'secondary'" size="sm" @click="filters.view = 'table'">Table</AppButton>
                    </div>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <AppButton variant="secondary" @click="applyFilters()">Apply</AppButton>
                <AppButton variant="ghost" @click="filters = { search: '', status: 'all', view: 'grid' }; applyFilters()">Clear</AppButton>
            </div>
        </AppCard>

        <div v-if="filters.view === 'grid'" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <AppCard
                v-for="client in clients.data"
                :key="client.id"
                class="cursor-pointer hover:border-emerald-300"
                @click="goToClient(client.id)"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ client.name }}</h3>
                        <p class="text-sm text-slate-500">{{ client.email || 'No email' }}</p>
                    </div>
                    <AppBadge :variant="client.status === 'active' ? 'success' : 'neutral'">{{ client.status }}</AppBadge>
                </div>
                <div class="mt-3 space-y-1 text-sm">
                    <p><span class="text-slate-500">Outstanding:</span> <span class="font-medium">{{ formatCents(client.outstanding_balance_cents) }}</span></p>
                    <p><span class="text-slate-500">Last invoice:</span> {{ client.last_invoice_date || '-' }}</p>
                </div>
            </AppCard>
        </div>

        <AppCard v-else class="mt-5">
            <AppTable
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'email', label: 'Email' },
                    { key: 'outstanding', label: 'Outstanding' },
                    { key: 'last_invoice', label: 'Last invoice' },
                    { key: 'status', label: 'Status' },
                ]"
                :page="clients.current_page"
                :last-page="clients.last_page"
                @page-change="applyFilters"
            >
                <tr
                    v-for="client in clients.data"
                    :key="client.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="goToClient(client.id)"
                >
                    <td class="px-4 py-3 font-medium text-slate-900">{{ client.name }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ client.email || '-' }}</td>
                    <td class="px-4 py-3">{{ formatCents(client.outstanding_balance_cents) }}</td>
                    <td class="px-4 py-3">{{ client.last_invoice_date || '-' }}</td>
                    <td class="px-4 py-3"><AppBadge :variant="client.status === 'active' ? 'success' : 'neutral'">{{ client.status }}</AppBadge></td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
