<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type SupplierRow = {
    id: number;
    name: string;
    contact_name: string | null;
    email: string | null;
    status: 'active' | 'inactive';
    expense_count: number;
    last_expense_date: string | null;
};

const props = defineProps<{
    suppliers: {
        data: SupplierRow[];
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
    router.get(route('suppliers.index'), { ...filters.value, page }, { preserveState: true, preserveScroll: true, replace: true });
};

const goToSupplier = (id: number) => router.visit(route('suppliers.show', id));
</script>

<template>
    <AppLayout
        title="Suppliers"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Suppliers' },
        ]"
    >
        <PageHeader title="Suppliers">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('expenses.index'))">Expenses</AppButton>
                <AppButton variant="primary" @click="router.visit(route('suppliers.create'))">New Supplier</AppButton>
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
                v-for="supplier in suppliers.data"
                :key="supplier.id"
                class="cursor-pointer hover:border-brand-300"
                @click="goToSupplier(supplier.id)"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">{{ supplier.name }}</h3>
                        <p class="text-sm text-slate-500">{{ supplier.email || 'No email' }}</p>
                    </div>
                    <AppBadge :variant="supplier.status === 'active' ? 'success' : 'neutral'">{{ supplier.status }}</AppBadge>
                </div>
                <div class="mt-3 space-y-1 text-sm">
                    <p><span class="text-slate-500">Expenses:</span> <span class="font-medium">{{ supplier.expense_count }}</span></p>
                    <p><span class="text-slate-500">Last expense:</span> {{ supplier.last_expense_date || '-' }}</p>
                </div>
            </AppCard>
        </div>

        <AppCard v-else class="mt-5">
            <AppTable
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'email', label: 'Email' },
                    { key: 'expenses', label: 'Expenses' },
                    { key: 'last_expense', label: 'Last expense' },
                    { key: 'status', label: 'Status' },
                ]"
                :page="suppliers.current_page"
                :last-page="suppliers.last_page"
                @page-change="applyFilters"
            >
                <tr
                    v-for="supplier in suppliers.data"
                    :key="supplier.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="goToSupplier(supplier.id)"
                >
                    <td class="px-4 py-3 font-medium text-slate-900">{{ supplier.name }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ supplier.email || '-' }}</td>
                    <td class="px-4 py-3">{{ supplier.expense_count }}</td>
                    <td class="px-4 py-3">{{ supplier.last_expense_date || '-' }}</td>
                    <td class="px-4 py-3"><AppBadge :variant="supplier.status === 'active' ? 'success' : 'neutral'">{{ supplier.status }}</AppBadge></td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
