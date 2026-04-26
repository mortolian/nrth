<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = defineProps<{
    contracts: {
        data: Array<{
            id: number;
            client: string;
            title: string;
            start_date: string | null;
            end_date: string | null;
            value: number;
            status: string;
            billing_type: string;
        }>;
        current_page: number;
        last_page: number;
    };
    clients: Array<{ id: number; name: string }>;
    filters: {
        status: string;
        client_id: number | null;
        from: string | null;
        to: string | null;
    };
}>();

const filters = ref({
    status: props.filters.status ?? 'all',
    client_id: props.filters.client_id ? String(props.filters.client_id) : 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyFilters = (page = 1) => {
    router.get(route('contracting.contracts.index'), {
        ...filters.value,
        client_id: filters.value.client_id === 'all' ? '' : filters.value.client_id,
        page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <AppLayout
        title="Client Contracts"
        :breadcrumbs="[
            { label: 'Contracting' },
            { label: 'Contracts' },
        ]"
    >
        <PageHeader title="Client Contracts" subtitle="Manage active agreements and billing structures">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('contracting.contracts.create'))">New Contract</AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                    <AppSelect
                        :model-value="filters.status"
                        :options="[
                            { label: 'All', value: 'all' },
                            { label: 'Draft', value: 'draft' },
                            { label: 'Active', value: 'active' },
                            { label: 'Expired', value: 'expired' },
                            { label: 'Terminated', value: 'terminated' },
                        ]"
                        @update:model-value="filters.status = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                    <AppSelect
                        :model-value="filters.client_id"
                        :options="[
                            { label: 'All clients', value: 'all' },
                            ...clients.map((client) => ({ label: client.name, value: String(client.id) })),
                        ]"
                        @update:model-value="filters.client_id = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="filters.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="filters.to" type="date" />
                </div>
                <div class="flex items-end gap-2">
                    <AppButton variant="secondary" @click="applyFilters()">Apply</AppButton>
                    <AppButton variant="ghost" @click="filters = { status: 'all', client_id: 'all', from: '', to: '' }; applyFilters()">Clear</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                :columns="[
                    { key: 'client', label: 'Client' },
                    { key: 'title', label: 'Title' },
                    { key: 'start', label: 'Start date' },
                    { key: 'end', label: 'End date' },
                    { key: 'value', label: 'Value' },
                    { key: 'status', label: 'Status' },
                    { key: 'billing', label: 'Billing type' },
                ]"
                :page="contracts.current_page"
                :last-page="contracts.last_page"
                @page-change="applyFilters"
            >
                <tr
                    v-for="contract in contracts.data"
                    :key="contract.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="router.visit(route('contracting.contracts.edit', contract.id))"
                >
                    <td class="px-4 py-3">{{ contract.client }}</td>
                    <td class="px-4 py-3 font-medium text-slate-900">{{ contract.title }}</td>
                    <td class="px-4 py-3">{{ contract.start_date || '—' }}</td>
                    <td class="px-4 py-3">{{ contract.end_date || 'Ongoing' }}</td>
                    <td class="px-4 py-3">{{ formatCents(contract.value) }}</td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="contract.status === 'active' ? 'success' : contract.status === 'draft' ? 'warning' : 'neutral'">
                            {{ contract.status }}
                        </AppBadge>
                    </td>
                    <td class="px-4 py-3">{{ contract.billing_type }}</td>
                </tr>
                <tr v-if="!contracts.data.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState title="No contracts yet" description="Create your first client contract." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
