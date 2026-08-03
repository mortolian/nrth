<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useTravelTabs } from '@/Composables/useFeatureTabs';

const travelTabs = useTravelTabs();

type VehicleRow = {
    id: number;
    name: string;
    make: string | null;
    model: string | null;
    year: number | null;
    registration_number: string | null;
    current_odometer_km: number | null;
    status: 'active' | 'inactive';
    trip_count: number;
    last_trip_date: string | null;
};

const props = defineProps<{
    vehicles: {
        data: VehicleRow[];
        current_page: number;
        last_page: number;
    };
    filters: {
        search: string | null;
        status: 'all' | 'active' | 'inactive';
    };
}>();

const filters = ref({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
});

const formatKm = (km: number | null) =>
    km == null ? '—' : `${Number(km).toLocaleString(undefined, { maximumFractionDigits: 1 })} km`;

const applyFilters = (page = 1) => {
    router.get(
        route('vehicles.index'),
        {
            search: filters.value.search || undefined,
            status: filters.value.status === 'all' ? undefined : filters.value.status,
            page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const goToVehicle = (id: number) => router.visit(route('vehicles.show', id));
</script>

<template>
    <FeatureShell
        title="Travel"
        section="vehicles"
        :tabs="travelTabs"
        document-title="Vehicles"
        subtitle="Vehicles used for business travel"
    >
        <template #actions>
            <AppButton variant="primary" @click="router.visit(route('vehicles.create'))">
                New vehicle
            </AppButton>
        </template>

        <AppCard>
            <div class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                    <AppInput v-model="filters.search" placeholder="Name, registration, make…" />
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
                <div class="flex items-end gap-2">
                    <AppButton variant="secondary" @click="applyFilters()">Apply</AppButton>
                    <AppButton
                        variant="ghost"
                        @click="
                            filters = { search: '', status: 'all' };
                            applyFilters();
                        "
                    >
                        Clear
                    </AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                table-class="text-sm"
                :columns="[
                    { key: 'name', label: 'Vehicle' },
                    { key: 'registration', label: 'Registration' },
                    { key: 'odometer', label: 'Odometer' },
                    { key: 'trips', label: 'Trips' },
                    { key: 'last_trip', label: 'Last trip' },
                    { key: 'status', label: 'Status' },
                ]"
                :page="vehicles.current_page"
                :last-page="vehicles.last_page"
                @page-change="applyFilters"
            >
                <tr
                    v-for="vehicle in vehicles.data"
                    :key="vehicle.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="goToVehicle(vehicle.id)"
                >
                    <td class="px-3 py-2">
                        <div class="font-medium text-slate-900">{{ vehicle.name }}</div>
                        <div v-if="vehicle.make || vehicle.model || vehicle.year" class="text-xs text-slate-500">
                            {{ [vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' ') }}
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">{{ vehicle.registration_number || '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                        {{ formatKm(vehicle.current_odometer_km) }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ vehicle.trip_count }}</td>
                    <td class="whitespace-nowrap px-3 py-2">{{ vehicle.last_trip_date || '—' }}</td>
                    <td class="px-3 py-2">
                        <AppBadge :variant="vehicle.status === 'active' ? 'success' : 'neutral'">
                            {{ vehicle.status }}
                        </AppBadge>
                    </td>
                </tr>
                <tr v-if="!vehicles.data.length">
                    <td colspan="6" class="px-4 py-6">
                        <EmptyState
                            title="No vehicles yet"
                            description="Add a vehicle you use for business so you can start the log book."
                        />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </FeatureShell>
</template>
